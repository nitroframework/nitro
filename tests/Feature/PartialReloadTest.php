<?php

namespace Nitro\Fusion\Tests\Feature;

use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Testing\AssertableFusion;
use Nitro\Fusion\Tests\Fixtures\Components\Store;
use Nitro\Fusion\Tests\Fixtures\Pages\Orders;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Partial reloads ($reload, $this->reload()), as Inertia's: the page asks its URL again
 * (X-Fusion-Partial, X-Fusion-Only), and a closure prop is called only when it is wanted.
 */
class PartialReloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Store::$log = [];
    }

    /** A request of its own, as in production (Fusion's per-request state starts afresh). */
    private function page(?string $only = null, bool $partial = true): TestResponse
    {
        $this->app->forgetScopedInstances();
        Store::$log = [];

        $server = $partial ? array_filter([
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FUSION_NAVIGATE' => '1',
            'HTTP_X_FUSION_PARTIAL' => 'fabcdefghij',
            'HTTP_X_FUSION_ONLY' => $only,
        ]) : [];

        return TestResponse::fromBaseResponse($this->request('GET', '/rendered/orders', $server));
    }

    private function addOrder(string $method): array
    {
        $snapshot = $this->snapshots((string) $this->page(partial: false)->getContent())[0]['envelope'];
        $this->app->forgetScopedInstances();

        $response = $this->update(['snapshot' => $snapshot, 'updates' => [], 'call' => ['method' => $method, 'params' => [Wire::encode('tea')]]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response);
    }

    #[DataProvider('frameworks')]
    public function test_closure_props_are_called_for_the_page(string $framework): void
    {
        $this->boot($framework);

        $this->page(partial: false)->assertOk()->assertFusion(fn (AssertableFusion $page) => $page
            ->where('orders', [])
            ->where('total', 0));

        $this->assertSame(['orders', 'total'], Store::$log);
    }

    #[DataProvider('frameworks')]
    public function test_a_partial_reload_calls_only_the_props_it_wants(string $framework): void
    {
        $this->boot($framework);
        $this->addOrder('add');

        $response = $this->page('orders')->assertOk();
        $this->assertSame(['orders'], Store::$log);
        $response->assertFusion(fn (AssertableFusion $page) => $page->where('orders', ['tea'])->where('total', 0));

        $state = Wire::fromJson((string) $response->json('snapshot.state'));
        $this->assertSame('fabcdefghij', $state['id'], 'The page keeps the id it has in the browser.');

        $this->page()->assertFusion(fn (AssertableFusion $page) => $page->where('orders', ['tea'])->where('total', 1));
        $this->assertSame(['orders', 'total'], Store::$log, 'without names: all of them');
    }

    #[DataProvider('frameworks')]
    public function test_a_server_method_asks_the_browser_to_reload(string $framework): void
    {
        $this->boot($framework);

        $this->assertSame(['only' => ['orders']], $this->addOrder('add')['effects']['reload']);
        $this->assertSame(['only' => []], $this->addOrder('addAndReloadAll')['effects']['reload']);
    }

    #[DataProvider('frameworks')]
    public function test_reload_names_public_properties(string $framework): void
    {
        $this->boot($framework);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fusion page [pages::orders] has no public property $nothing to reload.');

        (fn () => $this->reload('nothing'))->call(new Orders);
    }
}
