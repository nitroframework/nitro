<?php

namespace Nitro\Fusion\Tests\Compiler;

use Nitro\Fusion\Attributes\Computed;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Component;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #[Computed] methods are read as properties and worked out from the state in the browser, so
 * they take no parameters and cannot be #[Server].
 */
class ComputedTest extends TestCase
{
    public function test_computed_methods_are_listed_and_are_browser_methods(): void
    {
        $meta = ComponentMeta::read(ComputedCart::class, 'cart', 'fusion.cart');

        $this->assertSame(['total'], $meta->computed);
        $this->assertTrue($meta->isComputed('total'));
        $this->assertContains('total', $meta->browserMethods);
        $this->assertSame(['total'], $meta->scope()->computed);
    }

    public static function mistakes(): array
    {
        return [
            'server' => [ComputedOnServer::class, 'cannot be #[Server]'],
            'parameters' => [ComputedWithParameters::class, 'cannot take parameters'],
            'property name' => [ComputedNamedLikeProperty::class, 'has the name of a public property'],
        ];
    }

    #[DataProvider('mistakes')]
    public function test_what_cannot_be_computed(string $class, string $message): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage($message);

        ComponentMeta::read($class, 'x', 'fusion.x');
    }
}

class ComputedCart extends Component
{
    public array $items = [];

    #[Computed]
    public function total(): int
    {
        return array_sum($this->items);
    }
}

class ComputedOnServer extends Component
{
    #[Computed]
    #[Server]
    public function total(): int
    {
        return 1;
    }
}

class ComputedWithParameters extends Component
{
    #[Computed]
    public function total(int $tax): int
    {
        return $tax;
    }
}

class ComputedNamedLikeProperty extends Component
{
    public int $total = 0;

    #[Computed]
    public function total(): int
    {
        return 1;
    }
}
