<?php

namespace Nitro\Tests\Feature;

use Nitro\Build\Bundle;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentReader;
use Nitro\Tests\Fixtures\Invalid\PersistedComponent;
use Nitro\Tests\Fixtures\Invalid\SyncedLocked;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * #[Persist] and #[Sync] on stores: which properties the browser keeps and shares, as the store's
 * definition tells the runtime's plugins (what they do is the browser tests' "store plugins").
 */
class StorePluginsTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_the_cart_keeps_and_shares_what_the_browser_can_change(string $framework): void
    {
        $this->boot($framework);
        $meta = $this->app->make(\Nitro\Compiler\Components::class)->meta('stores::cart');

        $this->assertSame(['items'], $meta->persist, '#[Persist] on the store: every property the browser can change, not madeBy (#[Locked])');
        $this->assertSame(['items'], $meta->sync, '#[Sync] on the property');

        $bundle = $this->app->make(Bundle::class)->compile();
        $this->assertStringContainsString("Nitro.store(\"stores::cart\", {\nprops: {\"items\":{\"locked\":false,\"default\":[]},\"madeBy\":{\"locked\":true,\"default\":\"\"}},\nserver: [\"save\"],\npersist: [\"items\"],\nsync: [\"items\"],", $bundle, 'the store\'s definition tells the plugins');
    }

    #[DataProvider('frameworks')]
    public function test_not_on_a_component(string $framework): void
    {
        $this->boot($framework);

        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('#[Persist] is for stores: Nitro\Tests\Fixtures\Invalid\PersistedComponent is a component, whose state is its own');
        ComponentReader::read(PersistedComponent::class, 'persisted-component', 'nitro.components.persisted-component');
    }

    #[DataProvider('frameworks')]
    public function test_not_on_a_locked_property(string $framework): void
    {
        $this->boot($framework);

        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('#[Sync] on $owner of Nitro\Tests\Fixtures\Invalid\SyncedLocked: it is #[Locked], so only the server sets it');
        ComponentReader::read(SyncedLocked::class, 'stores::synced-locked', '');
    }
}
