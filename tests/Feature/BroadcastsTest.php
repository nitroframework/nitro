<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Compiler\Obfuscator;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\ComponentCompiler;
use Nitro\Fusion\Compiler\ComponentReader;
use Nitro\Fusion\Tests\Fixtures\Invalid\BroadcastWithoutProperty;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Broadcast events (#[On('echo:...')], as Livewire's): the listeners the bundle gives the
 * runtime, which subscribes through Laravel Echo (the browser tests run them).
 */
class BroadcastsTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_the_page_lists_its_broadcast_listeners(string $framework): void
    {
        $this->boot($framework);
        $code = $this->app->make(Bundle::class)->compileAll()['live'];

        foreach ([
            '"echo:orders,OrderPlaced": ["orderPlaced"]',
            '"echo-private:orders.{order.id},OrderShipped": ["orderShipped"]',
            '"echo-presence:room,here": ["here"]',
            '"echo-presence:room,joining": ["joining"]',
            '"echo-notification:users.{order.id}": ["notified"]',
        ] as $listener) {
            $this->assertStringContainsString($listener, str_replace(['":["', '","'], ['": ["', '", "'], $code));
        }

        $this->assertStringContainsString('server: ["orderShipped"]', str_replace('server:["', 'server: ["', $code), 'a #[Server] listener is called with the event');
    }

    public function test_a_channel_reads_the_properties_by_their_browser_names(): void
    {
        $names = new Obfuscator('key', [], aliasComponents: true);
        $compiler = new ComponentCompiler(fn () => false, names: $names);
        $meta = ComponentReader::read(\Nitro\Fusion\Tests\Fixtures\Pages\Live::class, 'pages::live', 'fusion.pages.live', page: true);

        $code = $compiler->compile($meta, '<div>{{ count($placed) }}</div>', 'live.blade.php');

        $this->assertStringContainsString('echo-private:orders.{'.$names->path('order.id').'},OrderShipped', $code);
        $this->assertStringNotContainsString('{order.id}', $code);
    }

    public function test_a_channel_must_read_a_property(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage("{missing} names no public property");

        $meta = ComponentReader::read(BroadcastWithoutProperty::class, 'broadcast-without-property', 'fusion.components.x');
        (new ComponentCompiler(fn () => false))->compile($meta, '<div>{{ $count }}</div>', 'x.blade.php');
    }
}
