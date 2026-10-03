<?php

namespace Nitro\Fusion\Tests\Feature;

use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Nitro\Fusion\Facades\Fusion;
use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Testing\AssertableFusion;
use Nitro\Fusion\Tests\Fixtures\Components\Counter;
use Nitro\Fusion\Tests\Fixtures\Components\Store;
use Nitro\Fusion\Tests\Fixtures\Pages\Counter as CounterPage;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Deferred page properties ($this->defer()): left out of the page, then loaded
 * by the page asking its URL again for them (a partial reload), with the id it has in the browser.
 */
class DeferredTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Store::$log = [];
    }

    /** A request of its own, as in production (Fusion's per-request state starts afresh). */
    private function response(string $uri, array $headers = []): TestResponse
    {
        $this->app->forgetScopedInstances();
        $server = [];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return TestResponse::fromBaseResponse($this->request('GET', $uri, $server));
    }

    /** The browser's request for the deferred properties: a partial reload of them. */
    private function load(string $uri, string $id = 'fabcdefghij', ?string $only = null): TestResponse
    {
        return $this->response($uri, array_filter([
            'Accept' => 'application/json',
            'X-Fusion-Navigate' => '1',
            'X-Fusion-Partial' => $id,
            'X-Fusion-Only' => $only,
        ]));
    }

    #[DataProvider('frameworks')]
    public function test_a_page_is_shown_without_its_deferred_properties(string $framework): void
    {
        $this->boot($framework);

        $response = $this->response('/report')->assertOk()
            ->assertSee('Sales')
            ->assertSee('Loading stats')
            ->assertDontSee('orders,')
            ->assertDontSee('Chart:');

        $response->assertFusion(fn (AssertableFusion $page) => $page
            ->deferred('stats', 'chart')
            ->where('stats', null)
            ->where('chart', []));

        $this->assertSame([], Store::$log, 'A deferred closure is not called for the page.');
    }

    #[DataProvider('frameworks')]
    public function test_a_visit_leaves_them_out_too(string $framework): void
    {
        $this->boot($framework);

        $this->response('/report', ['Accept' => 'application/json', 'X-Fusion-Navigate' => '1'])
            ->assertOk()
            ->assertFusion(fn (AssertableFusion $page) => $page->deferred('stats', 'chart')->where('stats', null));

        $this->assertSame([], Store::$log);
    }

    #[DataProvider('frameworks')]
    public function test_the_page_loads_them_with_its_id(string $framework): void
    {
        $this->boot($framework);

        $response = $this->load('/report', only: 'stats,chart')->assertOk();

        $response->assertFusion(fn (AssertableFusion $page) => $page
            ->page('pages::report')
            ->notDeferred()
            ->where('title', 'Sales')
            ->where('stats', ['orders' => 12, 'total' => 340])
            ->where('chart', [3, 5, 4]));

        $state = Wire::fromJson((string) $response->json('snapshot.state'));
        $this->assertSame('fabcdefghij', $state['id'], 'The page keeps the id it has in the browser.');
        $this->assertSame(['stats'], Store::$log);
    }

    #[DataProvider('frameworks')]
    public function test_a_partial_reload_of_other_properties_leaves_them_alone(string $framework): void
    {
        $this->boot($framework);

        $this->load('/report', only: 'title')->assertOk()->assertFusion(fn (AssertableFusion $page) => $page
            ->notDeferred()
            ->where('stats', null)
            ->where('chart', []));

        $this->assertSame([], Store::$log);
    }

    #[DataProvider('frameworks')]
    public function test_an_id_that_is_not_a_page_id_is_not_taken(string $framework): void
    {
        $this->boot($framework);

        $state = Wire::fromJson((string) $this->load('/report', 'x" onload="')->json('snapshot.state'));

        $this->assertMatchesRegularExpression('/^f[a-z0-9]{10}$/', $state['id']);
    }

    #[DataProvider('frameworks')]
    public function test_a_deferred_prop_names_a_public_property(string $framework): void
    {
        $this->boot($framework);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fusion page [pages::counter] has no public property $nothing to defer.');

        app(\Nitro\Fusion\Fusion::class)->deferProperty(new CounterPage, 'nothing', fn () => 1);
    }

    #[DataProvider('frameworks')]
    public function test_only_pages_defer(string $framework): void
    {
        $this->boot($framework);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only pages defer properties: [counter] is a component.');

        app(\Nitro\Fusion\Fusion::class)->deferProperty(new Counter, 'count', fn () => 1);
    }
}
