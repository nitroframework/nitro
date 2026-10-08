<?php

namespace Nitro\Fusion\Tests\Compiler;

use Closure;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\ComponentCompiler;
use Nitro\Fusion\Compiler\PropertyMeta;
use Nitro\Fusion\Compiler\Scope;
use Nitro\Fusion\Compiler\Transpiler;
use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Tests\Fixtures\Browser\OrderStatus;
use Nitro\Fusion\Tests\Fixtures\Browser\Orders;
use Nitro\Fusion\Tests\Fixtures\Browser\Priority;
use Nitro\Fusion\Tests\Support\Node;
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
 * #[Browser] enums: cases, from() / tryFrom() / cases(), methods on a case, static methods, and
 * enum properties. Orders' methods run in PHP and, compiled with what they reach, in Node: the
 * same results and errors. What is refused, and why.
 */
class BrowserEnumTest extends TestCase
{
    public function test_methods_using_enums_behave_like_php(): void
    {
        [$methods, $classes] = self::compile();
        $cases = [];

        foreach (self::cases() as $i => [$method, $args]) {
            $cases[] = [
                'id' => $i,
                'method' => $method,
                'args' => array_map(Wire::encode(...), $args),
                'state' => Wire::encode(get_object_vars(new Orders)),
                'expected' => self::runInPhp($method, $args),
            ];
        }

        $failures = Node::run('js/test/support/run-methods.mjs', [
            'methods' => $methods,
            'classes' => $classes,
            'properties' => ['log'],
            'cases' => $cases,
        ]);

        $this->assertSame([], array_map(fn ($f) => "#{$f['id']} {$f['method']}: PHP {$f['expected']}\n   JS  {$f['actual']}", $failures));
    }

    public function test_an_enum_is_defined_with_its_cases_and_the_methods_reached(): void
    {
        [, $classes] = self::compile();

        $this->assertStringContainsString('P.defineEnum("Nitro\\\\Fusion\\\\Tests\\\\Fixtures\\\\Browser\\\\OrderStatus", [["Draft","draft"],["Paid","paid"],["Shipped","shipped"],["Cancelled","cancelled"]]', $classes);
        $this->assertStringContainsString('[["Low",1],["Normal",2],["High",3]]', $classes, 'an int-backed enum');
        $this->assertStringContainsString('[["Hearts"],["Spades"]]', $classes, 'a pure enum: names only');
        $this->assertStringContainsString('"open": function', $classes, 'a static method');
        $this->assertStringContainsString('"badge": function', $classes);
        $this->assertStringContainsString('"isFinal": function', $classes, 'called by another method of the case');
        $this->assertStringNotContainsString('"audit"', $classes, 'a method nothing calls is never compiled (it needs the server)');
    }

    public static function refused(): array
    {
        return [
            'an unmarked enum\'s case' => ['return \Nitro\Fusion\Tests\Fixtures\Unmarked::One;', "Unmarked's cases are not in the browser: mark Nitro\\Fusion\\Tests\\Fixtures\\Unmarked #[Browser]"],
            'from() on a pure enum' => ['return Suit::from("x");', 'is a pure enum: it has no from()'],
            'an undefined case' => ['return OrderStatus::Lost;', 'Undefined constant Nitro\Fusion\Tests\Fixtures\Browser\OrderStatus::Lost'],
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

    public function test_a_method_called_on_a_value_must_be_an_enum_cases(): void
    {
        $compiler = new ComponentCompiler(fn () => false);
        $compile = Closure::bind(fn (array $keys) => $this->classes->reach($keys), $compiler, ComponentCompiler::class);

        try {
            $compile(['*::save']);
            $this->fail('Expected a compile error');
        } catch (CompileException $e) {
            $this->assertStringContainsString('->save() is called on a value in the browser, but only an enum case has methods there, and this code uses no #[Browser] enum', $e->getMessage());
        }

        try {
            $compile(['*::save', 'Nitro\Fusion\Tests\Fixtures\Browser\OrderStatus::']);
            $this->fail('Expected a compile error');
        } catch (CompileException $e) {
            $this->assertStringContainsString('no #[Browser] enum this code uses (Nitro\Fusion\Tests\Fixtures\Browser\OrderStatus) has a public save()', $e->getMessage());
        }

        /** label() exists: compiled for the enum reached. */
        $compile(['*::label', 'Nitro\Fusion\Tests\Fixtures\Browser\OrderStatus::']);
        $this->assertStringContainsString('"label": function', $compiler->compileClasses());
    }

    public function test_enum_properties_take_values_naming_a_case(): void
    {
        $property = PropertyMeta::from(new ReflectionProperty(EnumHolder::class, 'status'), false, null);
        $this->assertSame(PropertyMeta::ENUM, $property->kind);
        $this->assertFalse($property->locked, 'the browser may change it, to one of its cases');
        $this->assertTrue($property->accepts('paid'));
        $this->assertFalse($property->accepts('lost'));
        $this->assertFalse($property->accepts(1), 'not of its backing type');
        $this->assertFalse($property->accepts(null), 'not nullable');
        $this->assertSame(OrderStatus::Paid, $property->cast('paid'));
        $this->assertSame([true, OrderStatus::Shipped], $property->fromQuery('shipped'));

        $priority = PropertyMeta::from(new ReflectionProperty(EnumHolder::class, 'priority'), false, null);
        $this->assertTrue($priority->accepts(3));
        $this->assertTrue($priority->accepts('3'), 'the query string\'s 3');
        $this->assertFalse($priority->accepts('three'));
        $this->assertTrue($priority->accepts(null), 'nullable');
        $this->assertSame(Priority::High, $priority->cast(3));

        try {
            PropertyMeta::from(new ReflectionProperty(EnumHolder::class, 'unmarked'), false, 'EnumHolder.php');
            $this->fail('Expected a compile error');
        } catch (CompileException $e) {
            $this->assertStringContainsString('the browser holds it as one of its cases, so mark Nitro\Fusion\Tests\Fixtures\Unmarked #[Browser]', $e->getMessage());
        }

        try {
            PropertyMeta::from(new ReflectionProperty(EnumHolder::class, 'suit'), false, 'EnumHolder.php');
            $this->fail('Expected a compile error');
        } catch (CompileException $e) {
            $this->assertStringContainsString('a pure enum: it has no value to send to the browser', $e->getMessage());
        }
    }

    /** @return array{0: array<string, string>, 1: string} Orders' methods, and the classes they reach */
    private static function compile(): array
    {
        $reflection = new ReflectionClass(Orders::class);
        $ast = self::parse((string) file_get_contents((string) $reflection->getFileName()));
        $scope = new Scope(properties: ['log'], clientMethods: [], file: $reflection->getFileName());
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
        $source = "<?php namespace Nitro\\Fusion\\Tests\\Fixtures\\Browser;\nclass C {\n    public function run() {\n        {$body}\n    }\n}";
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

    private static function runInPhp(string $method, array $args): array
    {
        $object = new Orders;
        set_error_handler(static fn () => true);

        try {
            $return = $object->$method(...$args);

            return ['value' => ['state' => Wire::encode(get_object_vars($object)), 'return' => Wire::encode($return)]];
        } catch (Throwable $e) {
            return ['error' => (new ReflectionClass($e))->getShortName()];
        } finally {
            restore_error_handler();
        }
    }

    /** @return list<array{0: string, 1: array}> */
    private static function cases(): array
    {
        return [
            ['label', ['paid']],
            ['label', ['shipped']],
            ['label', ['lost']],
            ['tryLabel', ['cancelled']],
            ['tryLabel', ['lost']],
            ['options', []],
            ['compare', []],
            ['route', ['draft']],
            ['route', ['paid']],
            ['route', ['shipped']],
            ['route', ['cancelled']],
            ['walk', ['draft']],
            ['walk', ['shipped']],
            ['json', []],
            ['priority', [3]],
            ['priority', ['2']],
            ['priority', [9]],
            ['priority', ['high']],
            ['suits', []],
            ['returnsCase', []],
            ['echoes', []],
            ['concatenates', []],
            ['valueOfPure', []],
        ];
    }
}

/** Enum properties of each kind (PropertyMeta). */
class EnumHolder
{
    public OrderStatus $status = OrderStatus::Draft;

    public ?Priority $priority = null;

    public ?\Nitro\Fusion\Tests\Fixtures\Unmarked $unmarked = null;

    public ?\Nitro\Fusion\Tests\Fixtures\Browser\Suit $suit = null;
}
