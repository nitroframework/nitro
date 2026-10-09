<?php

namespace Nitro\Tests\Compiler;

use Closure;
use Nitro\Compiler\BrowserClasses;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentCompiler;
use Nitro\Compiler\Scope;
use Nitro\Compiler\Transpiler;
use Nitro\Support\Wire;
use Nitro\Tests\Fixtures\Browser\Checkout;
use Nitro\Tests\Fixtures\Browser\Money;
use Nitro\Tests\Support\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * #[Browser] classes: their static methods and constants used from browser code. Checkout's
 * methods (calling Money and Basket) run in PHP and, compiled with the classes they reach, in
 * Node: the same state and results. What is refused, and why.
 */
class BrowserClassTest extends TestCase
{
    public function test_methods_using_browser_classes_behave_like_php(): void
    {
        [$methods, $classes] = self::compile();
        $cases = [];

        foreach (self::cases() as $i => [$method, $args, $state]) {
            $cases[] = [
                'id' => $i,
                'method' => $method,
                'args' => array_map(Wire::encode(...), $args),
                'state' => Wire::encode([...get_object_vars(new Checkout), ...$state]),
                'expected' => self::runInPhp($method, $args, $state),
            ];
        }

        $failures = Node::run('js/test/support/run-methods.mjs', [
            'methods' => $methods,
            'classes' => $classes,
            'properties' => self::properties(),
            'cases' => $cases,
        ]);

        $this->assertSame([], array_map(fn ($f) => "#{$f['id']} {$f['method']}: PHP {$f['expected']}\n   JS  {$f['actual']}", $failures));
    }

    public function test_only_the_methods_reached_are_compiled(): void
    {
        [, $classes] = self::compile();

        $this->assertStringContainsString('Nitro.classes["Nitro\\\\Tests\\\\Fixtures\\\\Browser\\\\Money"]', $classes);
        $this->assertStringContainsString('"digits": function', $classes, 'a private method its class calls');
        $this->assertStringContainsString('"summary": function', $classes, 'Basket, reached through Checkout');
        $this->assertStringNotContainsString('"instance"', $classes, 'an instance method is never compiled');
    }

    public function test_constants_travel_as_their_values(): void
    {
        $js = self::method('return Money::CURRENCY.Money::RATES["reduced"];');

        $this->assertStringContainsString('"£"', $js);
        $this->assertStringNotContainsString('Nitro.classes', $js, 'a constant needs no class in the browser');
    }

    public static function refused(): array
    {
        return [
            'a class without #[Browser]' => ['return \Nitro\Tests\Fixtures\Browser\Checkout::make();', 'Checkout::make() is not a browser method: mark Nitro\Tests\Fixtures\Browser\Checkout #[Browser]'],
            'an unmarked class\'s constant' => ['return \Nitro\State\SharedData::FLASH_KEY;', "Nitro\\State\\SharedData's constants are not in the browser: mark Nitro\\State\\SharedData #[Browser]"],
            'an instance method' => ['return Money::instance();', 'Money::instance() is not static'],
            'a private method from outside' => ['return Money::digits(5);', 'Money::digits() is not public'],
            'a private constant from outside' => ['return Money::THOUSANDS;', 'Money::THOUSANDS is not public'],
            'an undefined method' => ['return Money::parse("1");', 'Call to undefined method Nitro\Tests\Fixtures\Browser\Money::parse()'],
            'an undefined constant' => ['return Money::EURO;', 'Undefined constant Nitro\Tests\Fixtures\Browser\Money::EURO'],
            'a computed method name' => ['$m = "format"; return Money::$m(1);', 'must be named'],
        ];
    }

    #[DataProvider('refused')]
    public function test_refused(string $body, string $message): void
    {
        try {
            self::method($body);
            $this->fail('Expected a compile error');
        } catch (CompileException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_a_method_needing_the_server_fails_with_where_it_is(): void
    {
        $compiler = new ComponentCompiler(fn () => false);
        $compile = Closure::bind(fn (array $keys) => $this->classes->reach($keys), $compiler, ComponentCompiler::class);

        try {
            $compile([BrowserClasses::key(\Nitro\Tests\Fixtures\Invalid\ServerHelper::class, 'stamp')]);
            $this->fail('Expected a compile error');
        } catch (CompileException $e) {
            $this->assertStringContainsString('ServerHelper::stamp() runs in the browser (browser code calls it) but cannot be compiled', $e->getMessage());
            $this->assertStringEndsWith('ServerHelper.php', (string) $e->sourceFile);
            $this->assertNotNull($e->sourceLine);
        }

        /** Every later component reaching it fails with the same error, without compiling it again. */
        $this->expectException(CompileException::class);
        $compile([BrowserClasses::key(\Nitro\Tests\Fixtures\Invalid\ServerHelper::class, 'stamp')]);
    }

    /** @return array{0: array<string, string>, 1: string} Checkout's methods, and the classes they reach */
    private static function compile(): array
    {
        $reflection = new ReflectionClass(Checkout::class);
        $ast = self::parse((string) file_get_contents((string) $reflection->getFileName()));
        $scope = new Scope(properties: self::properties(), clientMethods: [], file: $reflection->getFileName());
        $compiler = new ComponentCompiler(fn () => false);
        $reached = [];
        $methods = [];

        foreach ((new NodeFinder)->findInstanceOf($ast, Stmt\ClassMethod::class) as $method) {
            $transpiler = new Transpiler;
            $methods[$method->name->toString()] = $transpiler->method($method, $scope);
            $reached += $transpiler->uses['classes'];
        }

        Closure::bind(fn (array $keys) => $this->classes->reach($keys), $compiler, ComponentCompiler::class)(array_keys($reached));

        return [$methods, $compiler->compileClasses()];
    }

    private static function method(string $body): string
    {
        $source = "<?php namespace Nitro\\Tests\\Fixtures\\Browser;\nclass C {\n    public function run() {\n        {$body}\n    }\n}";
        $method = (new NodeFinder)->findFirstInstanceOf(self::parse($source), Stmt\ClassMethod::class);

        return (new Transpiler)->method($method, new Scope(file: 'C.php'));
    }

    /** @return list<Stmt> */
    private static function parse(string $source): array
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        (new NodeTraverser(new NameResolver(null, ['replaceNodes' => false])))->traverse($ast);

        return $ast;
    }

    /** @return list<string> */
    private static function properties(): array
    {
        return array_map(fn (ReflectionProperty $p) => $p->getName(), (new ReflectionClass(Checkout::class))->getProperties(ReflectionProperty::IS_PUBLIC));
    }

    private static function runInPhp(string $method, array $args, array $state): array
    {
        $object = new Checkout;

        foreach ($state as $name => $value) {
            $object->$name = $value;
        }

        try {
            $return = $object->$method(...$args);

            return ['value' => ['state' => Wire::encode(get_object_vars($object)), 'return' => Wire::encode($return)]];
        } catch (Throwable $e) {
            return ['error' => (new ReflectionClass($e))->getShortName()];
        }
    }

    /** @return list<array{0: string, 1: array, 2: array}> */
    private static function cases(): array
    {
        return [
            ['describe', [], []],
            ['describe', [], ['lines' => []]],
            ['describe', [], ['lines' => [['price' => 100000, 'quantity' => 3]]]],
            ['withVat', [], []],
            ['withVat', [], ['rate' => 'reduced']],
            ['withVat', [], ['rate' => 'zero']],
            ['withVat', [], ['rate' => 'luxury']],
            ['constants', [], []],
            ['formatted', [0], []],
            ['formatted', [5], []],
            ['formatted', [-1999], []],
            ['formatted', [123456789], []],
            ['formatted', ['42'], []],
        ];
    }
}
