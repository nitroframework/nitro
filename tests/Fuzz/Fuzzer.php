<?php

namespace Nitro\Tests\Fuzz;

use Nitro\Compiler\CompileException;
use Nitro\Compiler\Scope;
use Nitro\Compiler\Transpiler;
use Nitro\Tests\Support\Node;
use PhpParser\Error as ParseError;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;
use Throwable;

/**
 * Differential fuzzing: random methods (Generator) run in PHP and, compiled, in Node
 * (js/test/support/run-fuzz.mjs); a program whose result, property or error differs is shrunk
 * to the fewest statements that still differ, and reported with its seed.
 *
 * What the compiler refuses (a CompileException) is skipped: that is code the build reports.
 * Anything else the compiler throws is a crash, and reported.
 */
final class Fuzzer
{
    private const BATCH = 200;

    private readonly Parser $parser;

    private int $classes = 0;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    public function run(int $seed, int $count, int $show): int
    {
        $started = microtime(true);
        $prepared = [];
        $skipped = [];
        $crashes = [];

        for ($i = 0; $i < $count; $i++) {
            $program = (new Generator($seed + $i))->program();
            $result = $this->prepare($program);

            if (isset($result['skip'])) {
                $skipped[$result['skip']] = ($skipped[$result['skip']] ?? 0) + 1;
            } elseif (isset($result['crash'])) {
                $crashes[$result['crash']] ??= [$seed + $i, $program];
            } else {
                $prepared[$seed + $i] = [$program, $result];
            }
        }

        $failing = [];

        foreach (array_chunk($prepared, self::BATCH, true) as $batch) {
            foreach ($this->differing($batch) as $id => $actual) {
                $failing[$id] = $actual;
            }
        }

        /** Each failure shrunk; the same smallest program is one finding. */
        $findings = [];

        foreach ($failing as $id => $actual) {
            [$program, $result, $actual] = $this->shrink($prepared[$id][0], $prepared[$id][1], $actual);
            $source = self::source($program);
            $findings[$source] ??= ['seed' => $id, 'source' => $source, 'php' => $result['expected'], 'js' => $actual, 'count' => 0];
            $findings[$source]['count']++;
        }

        $this->report($seed, $count, count($prepared), $skipped, $crashes, array_values($findings), $show, microtime(true) - $started);

        return $findings === [] && $crashes === [] ? 0 : 1;
    }

    /**
     * A program compiled for the browser and run in PHP: its JavaScript and what PHP did
     * (canon()), or why it was skipped, or the compiler's crash.
     *
     * @param  array{init: list<string>, body: list<string>, return: string}  $program
     * @return array{js: string, expected: string}|array{skip: string}|array{crash: string}
     */
    public function prepare(array $program): array
    {
        $class = 'Program'.(++$this->classes);
        $code = "namespace Nitro\\Tests\\Fuzz\\Generated;\n\nfinal class {$class}\n{\n    public \$p = null;\n\n    public function f()\n    {\n"
            .self::source($program)."\n    }\n}";

        try {
            $method = (new NodeFinder)->findFirstInstanceOf($this->parser->parse('<?php '.$code), Stmt\ClassMethod::class);
        } catch (ParseError $e) {
            return ['crash' => 'The generator wrote PHP that does not parse: '.$e->getRawMessage()];
        }

        try {
            $js = (new Transpiler)->method($method, new Scope(properties: ['p'], clientMethods: ['f'], file: 'Fuzz.php'));
        } catch (CompileException $e) {
            return ['skip' => $e->reason];
        } catch (Throwable $e) {
            return ['crash' => get_class($e).': '.$e->getMessage()];
        }

        set_error_handler(static fn () => true);

        try {
            eval($code);
            $name = "Nitro\\Tests\\Fuzz\\Generated\\{$class}";
            $object = new $name;
            $expected = self::canon([$object->f(), $object->p]);
        } catch (Throwable $e) {
            $expected = 'error '.(new ReflectionClass($e))->getShortName();
        } finally {
            restore_error_handler();
        }

        return ['js' => $js, 'expected' => $expected];
    }

    /** A value as text both sides write the same way (run-fuzz.mjs canon()). */
    public static function canon(mixed $value): string
    {
        $json = fn (string $s) => json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE);

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            /** -0.0 is 0 in the browser (a known limit). */
            is_float($value) && $value == 0 => 'n:0',
            is_int($value), is_float($value) => 'n:'.$value,
            is_string($value) => 's:'.$json($value),
            is_array($value) && array_is_list($value) => '['.implode(',', array_map(self::canon(...), $value)).']',
            is_array($value) => '{'.implode(',', array_map(
                fn ($k, $v) => (is_int($k) ? 'n:'.$k : 's:'.$json($k)).'=>'.self::canon($v),
                array_keys($value),
                $value,
            )).'}',
            default => 'object '.get_debug_type($value),
        };
    }

    /**
     * @param  array{init: list<string>, body: list<string>, return: string}  $program
     */
    public static function source(array $program): string
    {
        return implode("\n", [...$program['init'], ...$program['body'], $program['return']]);
    }

    /**
     * The programs (by id) whose compiled method does something else in Node: what it did.
     *
     * @param  array<int, array{0: array, 1: array{js: string, expected: string}}>  $programs
     * @return array<int, string>
     */
    private function differing(array $programs): array
    {
        $input = [];

        foreach ($programs as $id => [, $result]) {
            $input[] = ['id' => $id, 'js' => $result['js'], 'expected' => $result['expected']];
        }

        $differing = [];

        foreach (Node::run('js/test/support/run-fuzz.mjs', ['programs' => $input], 600) as $failure) {
            $differing[$failure['id']] = $failure['actual'];
        }

        return $differing;
    }

    /**
     * The program with statements taken out (and starting values made null) while it still
     * differs: what is left is what the difference needs.
     *
     * @param  array{init: list<string>, body: list<string>, return: string}  $program
     * @param  array{js: string, expected: string}  $result
     * @return array{0: array{init: list<string>, body: list<string>, return: string}, 1: array{js: string, expected: string}, 2: string}
     */
    private function shrink(array $program, array $result, string $actual): array
    {
        while (true) {
            $variants = [];

            foreach (array_keys($program['body']) as $i) {
                $smaller = $program;
                array_splice($smaller['body'], $i, 1);
                $variants[] = $smaller;
            }

            /** A line inside a block (one statement: no braces). */
            foreach ($program['body'] as $i => $statement) {
                $lines = explode("\n", $statement);

                foreach ($lines as $l => $line) {
                    if ($l > 0 && str_ends_with($line, ';') && ! str_contains($line, '{') && ! str_contains($line, '}')) {
                        $smaller = $program;
                        $smaller['body'][$i] = implode("\n", array_diff_key($lines, [$l => true]));
                        $variants[] = $smaller;
                    }
                }
            }

            foreach ($program['init'] as $i => $line) {
                $null = substr($line, 0, 2).' = null;';

                if ($line !== $null) {
                    $smaller = $program;
                    $smaller['init'][$i] = $null;
                    $variants[] = $smaller;
                }
            }

            $candidates = [];

            foreach ($variants as $i => $variant) {
                $prepared = $this->prepare($variant);

                if (isset($prepared['js'])) {
                    $candidates[$i] = [$variant, $prepared];
                }
            }

            $differing = $candidates === [] ? [] : $this->differing($candidates);

            if ($differing === []) {
                return [$program, $result, $actual];
            }

            $first = array_key_first($differing);
            [$program, $result] = $candidates[$first];
            $actual = $differing[$first];
        }
    }

    /**
     * @param  array<string, int>  $skipped
     * @param  array<string, array{0: int, 1: array}>  $crashes
     * @param  list<array{seed: int, source: string, php: string, js: string, count: int}>  $findings
     */
    private function report(int $seed, int $count, int $ran, array $skipped, array $crashes, array $findings, int $show, float $seconds): void
    {
        echo "\n  Nitro fuzz: seeds {$seed} to ".($seed + $count - 1).sprintf(' (%.1fs)', $seconds)."\n\n";
        echo "  {$ran} programs compared, ".array_sum($skipped).' refused by the compiler, '.count($crashes).' compiler crashes, '.count($findings)." differences\n";

        if ($skipped !== []) {
            arsort($skipped);
            echo "\n  Refused most often:\n";

            foreach (array_slice($skipped, 0, 5, true) as $reason => $times) {
                echo "    {$times}x  ".strtok($reason, "\n")."\n";
            }
        }

        foreach ($crashes as $message => [$programSeed, $program]) {
            echo "\n  CRASH (seed {$programSeed}): {$message}\n".self::indent(self::source($program))."\n";
        }

        /** The worst first: code that works in PHP and gives something else in the browser. */
        $kinds = ['Different results' => [], 'The browser fails where PHP runs' => [], 'Different errors' => [], 'PHP throws, the browser carries on' => []];

        foreach ($findings as $finding) {
            $phpFails = str_starts_with($finding['php'], 'error ');
            $browserFails = str_starts_with($finding['js'], 'error ') || str_starts_with($finding['js'], 'JS ');
            $kinds[match (true) {
                $phpFails && $browserFails => 'Different errors',
                $phpFails => 'PHP throws, the browser carries on',
                $browserFails => 'The browser fails where PHP runs',
                default => 'Different results',
            }][] = $finding;
        }

        foreach (array_filter($kinds) as $kind => $list) {
            echo "\n  ".str_pad(" {$kind}: ".count($list).' ', 96, '=', STR_PAD_BOTH)."\n";

            foreach (array_slice($list, 0, $show) as $i => $finding) {
                echo "\n  #".($i + 1)." (seed {$finding['seed']}".($finding['count'] > 1 ? ", {$finding['count']} programs shrink to this" : '').")\n";
                echo self::indent($finding['source'])."\n";
                echo "    PHP:     {$finding['php']}\n";
                echo "    Browser: {$finding['js']}\n";
            }

            if (count($list) > $show) {
                echo "\n  ... and ".(count($list) - $show)." more (--show=N)\n";
            }
        }

        echo "\n";
    }

    private static function indent(string $source): string
    {
        return '      '.str_replace("\n", "\n      ", $source);
    }
}
