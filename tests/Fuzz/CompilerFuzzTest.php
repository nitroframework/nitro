<?php

namespace Nitro\Tests\Fuzz;

use Nitro\Build\Bundle;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentCompiler;
use Nitro\Tests\Support\Node;
use Nitro\Tests\TestCase;
use Throwable;

/**
 * Robustness fuzzing of the compiler: the fixture components' views and classes, mutated at
 * random (spans cut or doubled, Blade, HTML and PHP put in anywhere), compiled. Whatever the
 * source, the compiler:
 *
 *   - compiles it, or refuses it with a CompileException (what is wrong, and where);
 *   - never fails another way (a PHP error, a TypeError, a warning), and never hangs;
 *   - when it compiles, writes valid JavaScript.
 *
 * A class PHP itself cannot load (a parse error) is PHP's to refuse, not the compiler's: skipped.
 *
 * Off unless asked for:   FUZZ_COMPILER=500 FUZZ_SEED=1 phpunit tests/Fuzz/CompilerFuzzTest.php
 * A failure names its seed: FUZZ_SEED=N FUZZ_COMPILER=1 makes it again.
 */
class CompilerFuzzTest extends TestCase
{
    /** What views are made of, and what tends to break their parsing. */
    private const VIEW_TOKENS = [
        '@if ($count > 1)', '@else', '@elseif ($x)', '@endif', '@foreach ($items as $i => $item)', '@endforeach', '@forelse ($a as $b)', '@empty', '@endforelse',
        '@for ($i = 0; $i < 3; $i++)', '@endfor', '@while (false)', '@endwhile', '@switch ($a)', '@case (1)', '@break', '@endswitch', '@continue',
        '@isset($a)', '@endisset', '@unless ($a)', '@endunless', '@error(\'a\')', '@enderror', '@csrf', '@method(\'PUT\')', '@json($a)',
        '@class([\'a\' => $b])', '@style([\'c\' => true])', '@checked($a)', '@disabled(true)', '@php', '@endphp', '@include(\'x\')', '@teleport(\'m\')', '@endteleport',
        '{{ $count }}', '{{ ', ' }}', '{!! $html !!}', '{{-- c --}}', '@{{ x }}', '{{ $this->doubled() }}', '{{ Str::upper($a) }}', '{{ route(\'counter\') }}',
        '<div>', '</div>', '<span class="a">', '</span>', '<input nitro:model="count">', '<br>', '<!-- x -->', '<template>', '</template>', '<svg><path d="M0 0"/></svg>',
        'nitro:click="increment"', 'nitro:click="$set(\'count\', 1)"', 'nitro:loading', 'nitro:key="1"', 'nitro:transition', 'nitro:poll.1s="x"',
        '<nitro:counter />', '<nitro:card>', '</nitro:card>', '<nitro:slot name="footer">', '</nitro:slot>', '<nitro:dynamic-component :is="$a" />',
        '"', "'", '<', '>', '=', '{', '}', '(', ')', '$', '@', '\\', "\n", '$loop->index', '$errors->first(\'a\')', '?->', '??', '::',
    ];

    /** What classes are made of. */
    private const CLASS_TOKENS = [
        '#[Server]', '#[Computed]', '#[Locked]', '#[Url]', '#[On(\'x\')]', '#[Validate(\'required\')]', '#[Modelable]',
        'public int $n = 0;', 'public array $list = [];', 'public ?string $s = null;', 'private $hidden;', 'public static $shared;', 'public readonly int $r;',
        'public function extra(): void { $this->count++; }', 'public function args(int $a, string ...$rest) {}', 'public function mount(): void {}',
        '$this->', 'static::', 'self::', 'new \ArrayObject()', 'fn ($a) => $a', 'function () use (&$x) {}', 'match ($a) { 1 => 2, default => 3 }',
        'yield 1;', 'goto end;', 'list($a, $b) = [1, 2];', '[$a, $b] = [1, 2];', '&$', '...', '?->', 'clone $this', 'throw new \Exception();',
        'try {} catch (\Throwable) {}', 'return;', 'return $this;', 'echo 1;', 'global $g;', 'static $s = 1;', 'unset($a);', 'isset($a)', '@$a',
        '{', '}', '(', ')', ';', '$', '"', "'", '?', ':', ',', '=>', '->', '::class', 'abstract ', 'final ', 'enum E {}', 'interface I {}',
    ];

    public function test_mutated_components_compile_or_are_refused_never_an_error(): void
    {
        $count = (int) getenv('FUZZ_COMPILER');

        if ($count < 1) {
            $this->markTestSkipped('Set FUZZ_COMPILER=N to compile N mutated components.');
        }

        $seed = (int) (getenv('FUZZ_SEED') ?: random_int(1, 1_000_000));
        $this->boot('laravel');
        $fixtures = $this->fixtures();
        $bundle = $this->app->make(Bundle::class);
        $registry = app(\Nitro\Support\Registry::class);
        $failures = [];
        $compiled = [];
        $outcomes = ['compiled' => 0, 'refused' => 0, 'PHP refused the class' => 0];

        for ($i = 0; $i < $count; $i++) {
            $caseSeed = $seed + $i;
            $mutator = new Mutator($caseSeed);
            $fixture = $mutator->pick($fixtures);
            $compiler = new ComponentCompiler($bundle->isBladeDirective(...), parseTemplates: true, componentName: fn (string $class) => array_search(ltrim($class, '\\'), $registry->all(), true) ?: null, meta: fn (string $class) => app(\Nitro\Compiler\Components::class)->meta($class));
            $started = microtime(true);

            try {
                /** A store has no view: its class is mutated, and compiled as a store. */
                if ($fixture['store']) {
                    $what = 'store';
                    $source = $mutator->text($fixture['class'], self::CLASS_TOKENS, mt_rand(1, 3));
                    $name = $this->load($source, $fixture, $caseSeed);

                    if ($name === null) {
                        $outcomes['PHP refused the class']++;

                        continue;
                    }

                    $definition = $compiler->compileStore(app(\Nitro\Compiler\Components::class)->meta($name));
                } elseif ($mutator->chance(60)) {
                    $what = 'view';
                    $source = $mutator->text($fixture['view'], self::VIEW_TOKENS, mt_rand(1, 4));
                    $definition = $compiler->compile(app(\Nitro\Compiler\Components::class)->meta($fixture['name']), $source, $fixture['viewFile']);
                } else {
                    $what = 'class';
                    $source = $mutator->text($fixture['class'], self::CLASS_TOKENS, mt_rand(1, 3));
                    $name = $this->load($source, $fixture, $caseSeed);

                    if ($name === null) {
                        $outcomes['PHP refused the class']++;

                        continue;
                    }

                    $definition = $compiler->compile(app(\Nitro\Compiler\Components::class)->meta($name), $fixture['view'], $fixture['viewFile']);
                }

                $outcomes['compiled']++;
                $compiled[] = ['id' => $caseSeed, 'js' => $definition];
                $sources[$caseSeed] = "{$fixture['name']} {$what}:\n{$source}";
            } catch (CompileException) {
                $outcomes['refused']++;
            } catch (Throwable $e) {
                $failures[] = "seed {$caseSeed}, {$fixture['name']} {$what}: ".get_class($e).': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine()."\n".substr($source, 0, 1500);
            }

            if (microtime(true) - $started > 10) {
                $failures[] = "seed {$caseSeed}, {$fixture['name']} {$what}: took ".round(microtime(true) - $started, 1).'s';
            }
        }

        /** What compiled must be JavaScript. */
        foreach ($compiled === [] ? [] : Node::run('js/test/support/run-syntax.mjs', ['cases' => $compiled], 300) as $bad) {
            $failures[] = "seed {$bad['id']}: compiled to invalid JavaScript ({$bad['error']})\n".substr($sources[$bad['id']], 0, 1500);
        }

        fwrite(STDERR, sprintf("\n  Compiler fuzz: seeds %d to %d, %s, %d failures\n", $seed, $seed + $count - 1, json_encode($outcomes), count($failures)));

        $this->assertSame([], array_slice($failures, 0, 8), implode("\n\n", array_slice($failures, 0, 8)));
    }

    /**
     * The fixture components to mutate: name, class source and view.
     *
     * @return list<array{name: string, class: string, view: string, viewFile: string, viewName: string}>
     */
    private function fixtures(): array
    {
        $fixtures = [];

        foreach (app(\Nitro\Support\Registry::class)->all() as $name => $class) {
            $meta = app(\Nitro\Compiler\Components::class)->meta($name);

            /** A view the app's build compiles (Vue, React, Solid): nothing here to mutate. */
            if (! $meta->store && ! app(\Nitro\View\ViewEngines::class)->of($meta)->compiledByNitro()) {
                continue;
            }

            $viewFile = $meta->store ? '' : $this->app['view']->getFinder()->find($meta->view);
            $fixtures[] = [
                'name' => $name,
                'class' => (string) file_get_contents($meta->file),
                'view' => $meta->store ? '' : (string) file_get_contents($viewFile),
                'viewFile' => $viewFile,
                'viewName' => $meta->view,
                'shortName' => (new \ReflectionClass($class))->getShortName(),
                'store' => $meta->store,
            ];
        }

        return $fixtures;
    }

    /** The mutated class, loaded under a name of its own, or null when PHP cannot load it. */
    private function load(string $source, array $fixture, int $seed): ?string
    {
        $class = "Mutant{$seed}";
        $source = preg_replace('/^\s*<\?php\s*/', '', $source);
        $source = preg_replace('/^namespace [^;]+;/m', 'namespace Nitro\Tests\Fuzz\Generated;', (string) $source, 1);
        /** Its view by #[View] (in another namespace its name no longer finds it), unless it names one already or is a store. */
        $view = $fixture['store'] || str_contains((string) $source, '#[View(') ? '' : "#[\\Nitro\\Attributes\\View('{$fixture['viewName']}')]\n";
        $source = preg_replace('/\b(class)\s+'.preg_quote($fixture['shortName'], '/').'\b/', "{$view}$1 {$class}", (string) $source, 1, $renamed);

        if ($renamed !== 1) {
            return null;
        }

        /** Some of what PHP refuses ends the process (a compile or link error), past catching: tried in another one first. */
        $file = tempnam(sys_get_temp_dir(), 'nitro-mutant');
        file_put_contents($file, "<?php\n".$source);
        $autoload = var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true);
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-r', "require {$autoload}; require ".var_export($file, true).';'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $loads = proc_close($process) === 0;
        @unlink($file);

        if (! $loads) {
            return null;
        }

        try {
            eval($source);
        } catch (\ParseError|\Error) {
            return null;
        }

        $fqcn = "Nitro\\Tests\\Fuzz\\Generated\\{$class}";

        if (! class_exists($fqcn, false)) {
            return null;
        }

        $name = 'mutant-'.$seed;
        app(\Nitro\Support\Registry::class)->register($name, $fqcn);

        return $name;
    }
}
