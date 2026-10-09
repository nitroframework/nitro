<?php

namespace Nitro\Tests\Compiler;

use Nitro\Attributes\Computed;
use Nitro\Attributes\Server;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentReader;
use Nitro\Component;
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
        $meta = ComponentReader::read(ComputedCart::class, 'cart', 'nitro.cart');

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

        ComponentReader::read($class, 'x', 'nitro.x');
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
