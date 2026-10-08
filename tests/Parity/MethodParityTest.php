<?php

namespace Nitro\Fusion\Tests\Parity;

use Nitro\Fusion\Compiler\Scope;
use Nitro\Fusion\Compiler\Transpiler;
use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Tests\Fixtures\Parity\Playground;
use Nitro\Fusion\Tests\Support\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Each method of the Playground fixture runs in PHP and, transpiled, in Node, from the same
 * state: the state afterwards and the return value (or the error) must be the same.
 */
class MethodParityTest extends TestCase
{
    public function test_transpiled_methods_behave_like_php(): void
    {
        $methods = self::compile(Playground::class);
        $cases = [];

        foreach (self::cases() as $i => [$method, $args, $state]) {
            $cases[] = [
                'id' => $i,
                'method' => $method,
                'args' => array_map(Wire::encode(...), $args),
                'state' => Wire::encode([...get_object_vars(new Playground), ...$state]),
                'expected' => self::runInPhp($method, $args, $state),
            ];
        }

        $failures = Node::run('js/test/support/run-methods.mjs', [
            'methods' => $methods,
            'properties' => self::properties(),
            'cases' => $cases,
        ]);

        $this->assertSame([], array_map(
            fn ($f) => "#{$f['id']} {$f['method']}: PHP {$f['expected']}\n   JS  {$f['actual']}",
            $failures
        ));
    }

    /** @return array<string, string> method name => JS function */
    public static function compile(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse(file_get_contents($reflection->getFileName()));
        $methods = (new NodeFinder)->findInstanceOf($ast, Stmt\ClassMethod::class);
        $names = array_map(fn (Stmt\ClassMethod $m) => $m->name->toString(), $methods);

        $scope = new Scope(
            properties: self::properties(),
            clientMethods: $names,
            file: $reflection->getFileName(),
            constants: $reflection->getConstants(),
        );

        $compiled = [];

        foreach ($methods as $method) {
            $compiled[$method->name->toString()] = (new Transpiler)->method($method, $scope);
        }

        return $compiled;
    }

    private static function properties(): array
    {
        return array_map(fn (ReflectionProperty $p) => $p->getName(), (new ReflectionClass(Playground::class))->getProperties(ReflectionProperty::IS_PUBLIC));
    }

    /**
     * Code that PHP only warns about but a Laravel view fails on: run as Laravel runs it (its
     * HandleExceptions turns the warning into an ErrorException).
     */
    public function test_what_laravel_fails_on_fails_in_the_browser(): void
    {
        $cases = [];

        foreach (range(1, 5) as $i => $case) {
            $cases[] = [
                'id' => $i,
                'method' => 'propertyOfNonObject',
                'args' => [$case],
                'state' => Wire::encode(get_object_vars(new Playground)),
                'expected' => self::runInPhp('propertyOfNonObject', [$case], [], laravel: true),
            ];
        }

        $this->assertSame(['ErrorException', 'ErrorException', 'ErrorException', 'ErrorException'], array_column(array_column(array_slice($cases, 0, 4), 'expected'), 'error'));

        $failures = Node::run('js/test/support/run-methods.mjs', [
            'methods' => self::compile(Playground::class),
            'properties' => self::properties(),
            'cases' => $cases,
        ]);

        $this->assertSame([], array_map(fn ($f) => "#{$f['id']} {$f['method']}: PHP {$f['expected']}\n   JS  {$f['actual']}", $failures));
    }

    private static function runInPhp(string $method, array $args, array $state, bool $laravel = false): array
    {
        $object = new Playground;

        foreach ($state as $name => $value) {
            $object->$name = $value;
        }

        set_error_handler($laravel
            ? static fn (int $level, string $message, string $file, int $line) => throw new \ErrorException($message, 0, $level, $file, $line)
            : static fn () => true);

        try {
            $return = $object->$method(...$args);

            return ['value' => ['state' => Wire::encode(get_object_vars($object)), 'return' => Wire::encode($return)]];
        } catch (Throwable $e) {
            return ['error' => (new ReflectionClass($e))->getShortName()];
        } finally {
            restore_error_handler();
        }
    }

    /** @return list<array{0: string, 1: array, 2: array}> */
    private static function cases(): array
    {
        $cases = [];

        foreach ((new ReflectionClass(Playground::class))->getMethods() as $method) {
            if ($method->getNumberOfRequiredParameters() === 0) {
                $cases[] = [$method->getName(), [], []];
            }
        }

        $cases[] = ['increment', [5], ['count' => 10]];
        $cases[] = ['increment', ['7'], []];
        $cases[] = ['increment', [2.9], []];
        $cases[] = ['increment', ['abc'], []];
        $cases[] = ['increment', [null], []];
        $cases[] = ['strings', [], ['name' => 'Grace', 'items' => [10, 20]]];
        $cases[] = ['loops', [], ['items' => ['a' => 5, 'b' => 1, 'c' => 7]]];
        $cases[] = ['loops', [], ['items' => []]];

        foreach ([0, '0', 'a', 'b', 5, 6, 7, '', null, false, '5'] as $v) {
            $cases[] = ['switches', [$v], []];
        }

        foreach ([1, 2, '1', null, 3, '2'] as $v) {
            $cases[] = ['matches', [$v], []];
        }

        $cases[] = ['strictMatch', [1], []];
        $cases[] = ['strictMatch', ['1'], []];
        $cases[] = ['typed', ['5', 7, '0.5', 1, [1]], []];
        $cases[] = ['typed', [5, 'x', null, 'yes', ['a' => 1]], []];
        $cases[] = ['typed', ['x', 'y'], []];
        $cases[] = ['typed', [1, []], []];
        $cases[] = ['typed', [1, 'y', 'z'], []];
        $cases[] = ['existence', [], ['selected' => ['id' => 5]]];
        $cases[] = ['conditionsAndCasts', [], ['count' => 3, 'name' => '']];
        $cases[] = ['callsOtherMethods', [], ['count' => 100]];
        $cases[] = ['nestedWrites', [], ['form' => []]];
        $cases[] = ['byReference', [], ['items' => ['b' => 1, 'a' => 3]]];

        foreach (range(1, 14) as $case) {
            $cases[] = ['functionError', [$case], []];
        }

        foreach ([1, 2, 3, 4, 5, 6] as $case) {
            $cases[] = ['stringOffsetError', [$case], []];
        }

        return $cases;
    }
}
