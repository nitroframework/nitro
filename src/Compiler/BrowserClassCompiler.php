<?php

namespace Nitro\Compiler;

use BackedEnum;
use Closure;
use ReflectionClass;
use ReflectionMethod;
use UnitEnum;

/**
 * The #[Browser] classes and enums that compiled code reaches, compiled once for the main bundle:
 * the static methods browser code calls, an enum's cases and the methods called on them, and what
 * those call in turn. A method that does not compile fails every component reaching it.
 */
final class BrowserClassCompiler
{
    /** @var array<string, string> "Class::method" => its compiled function, reached so far */
    private array $methods = [];

    /** @var array<string, list<string>> "Class::method" => the keys its own code reaches (BrowserClasses) */
    private array $methodUses = [];

    /** @var array<string, CompileException> "Class::method" => why it does not compile */
    private array $broken = [];

    /** @var array<class-string, true> the enums reached: each is defined with all its cases */
    private array $enums = [];

    /**
     * @param  Closure(array<string, array<string, true>>): void  $use  takes what a method's code uses (Uses)
     */
    public function __construct(private readonly ?Obfuscator $names, private readonly Closure $use)
    {
    }

    /**
     * The classes and enums reached, as the main bundle defines them:
     *
     *   Nitro.classes["App\\Support\\Money"] = { format: function ($cents) {...} };
     *   Nitro.classes["App\\Enums\\Status"] = P.defineEnum("App\\Enums\\Status", [["Draft", "draft"], ...], {static methods}, {methods});
     */
    public function definitions(): string
    {
        $classes = array_fill_keys(array_keys($this->enums), []);

        foreach ($this->methods as $key => $js) {
            [$class, $method] = BrowserClasses::split($key);
            $classes[$class][$method] = $js;
        }

        ksort($classes);
        $scope = (new Scope)->withNames($this->names);
        $list = fn (array $methods) => implode(",\n", array_map(fn ($name, $js) => json_encode($scope->browserMethod($name)).': '.$js, array_keys($methods), $methods));
        $definitions = [];

        foreach ($classes as $class => $methods) {
            ksort($methods);
            $name = json_encode($scope->browserClass($class), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if (! enum_exists($class)) {
                $definitions[] = '// '.$class." · #[Browser]\n"."Nitro.classes[{$name}] = {\n".$list($methods)."\n};";

                continue;
            }

            $static = array_filter($methods, fn (string $method) => (new ReflectionMethod($class, $method))->isStatic(), ARRAY_FILTER_USE_KEY);
            $cases = array_map(fn (UnitEnum $case) => $case instanceof BackedEnum ? [$case->name, $case->value] : [$case->name], $class::cases());

            $definitions[] = '// '.$class." · #[Browser] enum\n"
                ."Nitro.classes[{$name}] = P.defineEnum({$name}, ".json_encode($cases, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                .", {\n".$list($static)."\n}, {\n".$list(array_diff_key($methods, $static))."\n});";
        }

        return implode("\n\n", $definitions);
    }

    /**
     * What definitions() defines: each method compiled ("Class::method") and each enum ("Enum::"), sorted.
     *
     * @return list<string>
     */
    public function compiled(): array
    {
        $keys = [...array_keys($this->methods), ...array_map(BrowserClasses::definition(...), array_keys($this->enums))];
        sort($keys);

        return $keys;
    }

    /**
     * Everything the keys reach, compiled: methods, what they call, the enums whose cases they use,
     * and for "$value->label()", the label() of each of those enums. One of those enums must have
     * it: in the browser, only an enum case has methods.
     *
     * @param  list<string>  $keys  (BrowserClasses)
     */
    public function reach(array $keys): void
    {
        $seen = [];
        $enums = [];
        $called = [];
        $queue = $keys;

        do {
            while ($queue !== []) {
                $key = array_shift($queue);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                [$class, $method] = BrowserClasses::split($key);

                if ($class === '*') {
                    [$name, $file, $line] = BrowserClasses::calledAt($method);
                    $called[$name] ??= [$file, $line];
                } elseif ($method === '') {
                    $enums[$class] = true;
                } else {
                    $this->compileMethod($class, $method, $key);
                    array_push($queue, ...$this->methodUses[$key]);
                }
            }

            foreach (array_keys($enums) as $enum) {
                foreach (array_keys($called) as $method) {
                    if (self::caseMethod($enum, $method) && ! isset($seen[BrowserClasses::key($enum, $method)])) {
                        $queue[] = BrowserClasses::key($enum, $method);
                    }
                }
            }
        } while ($queue !== []);

        foreach ($called as $method => [$file, $line]) {
            if (array_filter(array_keys($enums), fn (string $enum) => self::caseMethod($enum, $method)) === []) {
                throw new CompileException("->{$method}() is called on a value in the browser, but only an enum case has methods there, and "
                    .($enums === [] ? 'this code uses no #[Browser] enum' : 'no #[Browser] enum this code uses ('.implode(', ', array_keys($enums)).") has a public {$method}()")
                    .'. Data from the server arrives as arrays: call methods on $this, or in a #[Server] method', $file, $line);
            }
        }

        $this->enums += $enums;
    }

    /** Whether $method is a public instance method of $enum, as written (a case's method in the browser). */
    private static function caseMethod(string $enum, string $method): bool
    {
        if (! method_exists($enum, $method)) {
            return false;
        }

        $reflection = new ReflectionMethod($enum, $method);

        return $reflection->getName() === $method && $reflection->isPublic() && ! $reflection->isStatic();
    }

    /**
     * A static method of a #[Browser] class or enum, or an enum's instance method (a case's), once.
     *
     * @param  class-string  $class
     */
    private function compileMethod(string $class, string $method, string $key): void
    {
        if (isset($this->methods[$key])) {
            return;
        }

        if (isset($this->broken[$key])) {
            throw $this->broken[$key];
        }

        $reflection = new ReflectionMethod($class, $method);
        $enum = enum_exists($class);
        $instanceMethods = $enum ? array_values(array_map(fn (ReflectionMethod $reflected) => $reflected->getName(), array_filter((new ReflectionClass($class))->getMethods(), fn (ReflectionMethod $reflected) => ! $reflected->isStatic()))) : [];
        $scope = new Scope(clientMethods: $instanceMethods, file: (string) $reflection->getFileName(), constants: (new ReflectionClass($class))->getConstants(), names: $this->names);
        $transpiler = new Transpiler($scope);

        try {
            $node = MethodSource::node($class, $method);
            $js = match (true) {
                $reflection->isStatic() => $transpiler->staticMethod($node, $scope, $class),
                $enum => $transpiler->enumMethod($node, $scope, $class),
                default => throw new CompileException("{$class}::{$method}() is not static", $reflection->getFileName() ?: null, $reflection->getStartLine() ?: null),
            };
        } catch (CompileException $exception) {
            throw $this->broken[$key] = new CompileException(
                "{$class}::{$method}() runs in the browser (browser code calls it) but cannot be compiled: {$exception->reason}",
                $exception->sourceFile,
                $exception->sourceLine,
            );
        }

        $uses = array_keys($transpiler->uses['classes']);

        /** An enum's method: its enum is defined, and the methods it calls on $this are compiled. */
        if ($enum) {
            $uses[] = BrowserClasses::definition($class);

            foreach ($transpiler->calledMethods as $called) {
                $uses[] = BrowserClasses::key($class, $called);
            }
        }

        $this->methods[$key] = $js;
        $this->methodUses[$key] = array_values(array_unique($uses));
        ($this->use)($transpiler->uses);
    }
}
