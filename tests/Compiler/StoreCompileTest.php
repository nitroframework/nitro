<?php

namespace Nitro\Fusion\Tests\Compiler;

use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\Scope;
use Nitro\Fusion\Compiler\Template\TemplateCompiler;
use Nitro\Fusion\Compiler\Transpiler;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A component using a store (public Cart $cart): what reads and calls it compiles to, and what is
 * refused (writing to it from outside, members it does not have, a #[Server] method's result).
 */
class StoreCompileTest extends TestCase
{
    private function scope(): Scope
    {
        $cart = new Scope(
            properties: ['items', 'madeBy'],
            clientMethods: ['add', 'remove', 'count'],
            serverMethods: ['save'],
            locked: ['madeBy'],
            computed: ['count'],
        );

        return (new Scope(properties: ['note'], clientMethods: ['run'], file: 'Shelf.php'))
            ->withStores(['cart' => ['name' => 'stores::cart', 'scope' => $cart]]);
    }

    private function method(string $body): string
    {
        $source = "<?php class C {\n    public function run() {\n        {$body}\n    }\n}";
        $method = (new NodeFinder)->findFirstInstanceOf((new ParserFactory)->createForNewestSupportedVersion()->parse($source), Stmt\ClassMethod::class);

        return (new Transpiler)->method($method, $this->scope());
    }

    public function test_reads_and_calls_compile_to_the_store(): void
    {
        $js = $this->method("\$this->cart->add('Pen', 2);\n\$n = \$this->cart->count;\n\$i = \$this->cart->items;\n\$m = \$this->cart->madeBy;\n\$this->cart->save();\nreturn \$this->cart?->items;");

        $this->assertStringContainsString('$this.cart.add("Pen", 2)', $js);
        $this->assertStringContainsString('$this.cart.$computed("count")', $js, '#[Computed]: kept until the store changes');
        $this->assertStringContainsString('$this.cart.items', $js);
        $this->assertStringContainsString('$this.cart.madeBy', $js, 'a locked property reads');
        $this->assertStringContainsString('$this.cart.$call("save", [])', $js, 'a #[Server] method: a call with the store\'s state');
    }

    public static function refused(): array
    {
        return [
            'the store replaced' => ['$this->cart = null;', '$this->cart is a store, shared with other components: change it through its methods'],
            'a property written' => ['$this->cart->items = [];', '$this->cart is a store'],
            'an item appended' => ['$this->cart->items[] = 1;', '$this->cart is a store'],
            'a locked property written' => ['$this->cart->madeBy = "x";', '$this->cart is a store'],
            'an increment' => ['$this->cart->items[0]++;', '$this->cart is a store'],
            'an unknown property' => ['return $this->cart->nope;', '$cart->nope is not a public property of the store'],
            'an unknown method' => ['$this->cart->nope();', '$cart->nope() is not a public method of the store'],
            'a server result used' => ['$x = $this->cart->save();', '$cart->save() runs on the server: call it as a statement'],
        ];
    }

    #[DataProvider('refused')]
    public function test_what_cannot_be_done_to_a_store_is_refused(string $body, string $message): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage($message);

        $this->method($body);
    }

    public function test_a_view_reads_and_calls_the_store_through_its_property(): void
    {
        $js = (new TemplateCompiler(fn () => false))->compile('<div>{{ $cart->count }} {{ count($cart->items) }}<button fusion:click="$cart->remove(0)">x</button><button fusion:click="$cart->save()">s</button></div>', $this->scope());

        $this->assertStringContainsString('$this.cart.$computed("count")', $js);
        $this->assertStringContainsString('P.fns.count($this.cart.items)', $js);
        $this->assertStringContainsString('$this.cart.remove(0)', $js);
        $this->assertStringContainsString('$this.cart.$call("save", [])', $js);
    }

    public static function refusedInViews(): array
    {
        return [
            'a server method while rendering' => ['<div>{{ $cart->save() }}</div>', "save() is a store's #[Server] method: it runs on the server and cannot be called while rendering"],
            'an unknown member' => ['<div>{{ $cart->nope }}</div>', '$cart->nope is not a public property of the store'],
            'an assignment' => ['<div>{{ $cart->items = [] }}</div>', 'A template expression cannot assign'],
        ];
    }

    #[DataProvider('refusedInViews')]
    public function test_what_a_view_cannot_do_to_a_store_is_refused(string $view, string $message): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage($message);

        (new TemplateCompiler(fn () => false))->compile($view, $this->scope());
    }
}
