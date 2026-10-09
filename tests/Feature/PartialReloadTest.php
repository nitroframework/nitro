<?php

namespace Nitro\Tests\Feature;

use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Nitro\Support\Wire;
use Nitro\Testing\AssertableNitro;
use Nitro\Tests\Fixtures\Components\Store;
use Nitro\Tests\Fixtures\Pages\Orders;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Partial reloads ($reload, $this->reload()), as Inertia's: the page asks its URL again
 * (X-Nitro-Partial, X-Nitro-Only), and mount() loads only what is wanted ($this->wants()).
 */
class PartialReloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Store::$log = [];
    }

    /** A request of its own, as in production (Nitro's per-request state starts afresh). */
    private function page(?string $only = null, bool $partial = true): TestResponse
    {
        $this->app->forgetScopedInstances();
        Store::$log = [];

        $server = $partial ? array_filter([
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_NITRO_NAVIGATE' => '1',
            'HTTP_X_NITRO_PARTIAL' => 'fabcdefghij',
            'HTTP_X_NITRO_ONLY' => $only,
        ]) : [];

        return TestResponse::fromBaseResponse($this->request('GET', '/orders', $server));
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

        $this->page(partial: false)->assertOk()->assertNitro(fn (AssertableNitro $page) => $page
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
        $response->assertNitro(fn (AssertableNitro $page) => $page->where('orders', ['tea'])->where('total', 0));

        $state = Wire::fromJson((string) $response->json('snapshot.state'));
        $this->assertSame('fabcdefghij', $state['id'], 'The page keeps the id it has in the browser.');

        $this->page()->assertNitro(fn (AssertableNitro $page) => $page->where('orders', ['tea'])->where('total', 1));
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
    public function test_a_partial_reload_answers_the_properties_signed_on_their_own(string $framework): void
    {
        $this->boot($framework);
        $this->addOrder('add');

        $partial = $this->page('orders')->json('partial');
        $state = Wire::fromJson($partial['state']);

        $this->assertSame(['orders' => ['tea']], $state['data']);
        $this->assertSame('fabcdefghij', $state['id']);
        $this->assertTrue($state['memo']['partial']);
        $this->assertNull($this->page()->json('partial'), 'a reload of all of them: the snapshot is the state');
    }

    #[DataProvider('frameworks')]
    public function test_the_next_server_call_lays_the_partial_reloads_over_the_snapshot(string $framework): void
    {
        $this->boot($framework);
        $this->addOrder('add');
        $this->addOrder('add');

        /** The page as the browser has it: its snapshot, then a reload of the (locked) total. */
        $html = (string) $this->page(partial: false)->getContent();
        $snapshot = $this->snapshots($html)[0]['envelope'];
        $id = Wire::fromJson($snapshot['state'])['id'];
        $this->addOrder('add');
        $this->app->forgetScopedInstances();
        $partial = json_decode((string) $this->request('GET', '/orders', [
            'HTTP_ACCEPT' => 'application/json', 'HTTP_X_NITRO_NAVIGATE' => '1', 'HTTP_X_NITRO_PARTIAL' => $id, 'HTTP_X_NITRO_ONLY' => 'total',
        ])->getContent(), true)['partial'];

        $this->app->forgetScopedInstances();
        $response = $this->update(['snapshot' => $snapshot, 'partials' => [$partial], 'updates' => [], 'call' => null]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = Wire::fromJson($this->json($response)['snapshot']['state'])['data'];

        $this->assertSame(3, $data['total'], 'the reloaded total');
        $this->assertSame(['tea', 'tea'], $data['orders'], 'the rest from the snapshot');
    }

    #[DataProvider('frameworks')]
    public function test_a_partial_reload_is_not_a_components_state(string $framework): void
    {
        $this->boot($framework);
        $partial = $this->page('total')->json('partial');

        $this->app->forgetScopedInstances();
        $this->assertSame(403, $this->update(['snapshot' => $partial, 'updates' => [], 'call' => null])->getStatusCode());

        /** Nor does it go with another component's snapshot. */
        $snapshot = $this->snapshots((string) $this->page(partial: false)->getContent())[0]['envelope'];
        $this->app->forgetScopedInstances();
        $this->assertSame(403, $this->update(['snapshot' => $snapshot, 'partials' => [$partial], 'updates' => [], 'call' => null])->getStatusCode());
    }

    #[DataProvider('frameworks')]
    public function test_reload_names_public_properties(string $framework): void
    {
        $this->boot($framework);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Nitro page [pages::orders] has no public property $nothing to reload.');

        (fn () => $this->reload('nothing'))->call(new Orders);
    }
}
