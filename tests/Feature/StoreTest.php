<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Tests\Fixtures\Components\Store as Record;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Stores on the server: one per request for every component that uses it, made with its mount();
 * rendered with the page; sent with it; read and changed by #[Server] calls, checked as a
 * component's state is.
 */
class StoreTest extends TestCase
{
    private function page(string $uri): string
    {
        $response = $this->get($uri);
        $this->assertSame(200, $response->getStatusCode(), substr((string) $response->getContent(), 0, 2000));

        return (string) $response->getContent();
    }

    /** @return array<string, mixed> the page's @fusionScripts globals */
    private function globals(string $html): array
    {
        preg_match('#<script type="application/json" data-fusion-globals>(.*?)</script>#s', $html, $m);

        return json_decode($m[1] ?? '{}', true);
    }

    #[DataProvider('frameworks')]
    public function test_one_store_per_request_for_every_component_and_sent_with_the_page(string $framework): void
    {
        $this->boot($framework);
        $html = $this->page('/shop');

        $this->assertStringContainsString('class="cart-badge">0 · 0 · server</span>', $html, 'rendered on the server with its mount()');
        $this->assertSame(1, count(array_keys(Record::$log, 'cart mounted')), 'one store, made once for the badge and the shelf');

        $stores = $this->globals($html)['stores'] ?? [];
        $this->assertSame(['stores::cart'], array_keys($stores));
        $state = Wire::fromJson($stores['stores::cart']['state']);
        $this->assertSame('stores::cart', $state['name']);
        $this->assertSame(['items' => [], 'madeBy' => 'server'], $state['data']);

        /** Not in the components' own state. */
        foreach ($this->snapshots($html) as $snapshot) {
            $this->assertArrayNotHasKey('cart', $snapshot['state']['data']);
        }
    }

    #[DataProvider('frameworks')]
    public function test_a_page_without_stores_sends_none(string $framework): void
    {
        $this->boot($framework);
        $this->assertArrayNotHasKey('stores', $this->globals($this->page('/counter')));
    }

    /**
     * The shelf's snapshot and the cart store's, from /shop.
     *
     * @return array{0: array, 1: array}
     */
    private function shop(): array
    {
        $html = $this->page('/shop');
        $shelf = collect($this->snapshots($html))->first(fn (array $s) => $s['state']['name'] === 'shop-shelf')['envelope'];

        return [$shelf, $this->globals($html)['stores']['stores::cart']];
    }

    private function call(array $payload): \Symfony\Component\HttpFoundation\Response
    {
        $this->app->forgetScopedInstances();

        return $this->update($payload);
    }

    #[DataProvider('frameworks')]
    public function test_a_components_server_method_reads_the_store_as_the_browser_had_it_and_sends_it_back(string $framework): void
    {
        $this->boot($framework);
        [$shelf, $cart] = $this->shop();

        $items = [['name' => 'Pen', 'price' => 2], ['name' => 'Book', 'price' => 10]];
        $response = $this->call(['snapshot' => $shelf, 'call' => ['method' => 'checkout', 'params' => []], 'stores' => ['stores::cart' => ['snapshot' => $cart, 'updates' => ['items' => Wire::encode($items)]]]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertSame([2, 12], Record::$saved['checkout'], 'the method saw what the browser changed');
        $state = Wire::fromJson($this->json($response)['stores']['stores::cart']['state']);
        $this->assertSame([...$items, ['name' => 'Receipt', 'price' => 0]], $state['data']['items'], 'and the change it made comes back, signed');
        $this->assertSame('/shop', $state['memo']['route']['uri'], 'signed for the page it was made for, not the endpoint');
        $this->assertArrayNotHasKey('cart', Wire::fromJson($this->json($response)['snapshot']['state'])['data']);
    }

    #[DataProvider('frameworks')]
    public function test_the_stores_state_is_checked_as_a_components_is(string $framework): void
    {
        $this->boot($framework);
        [$shelf, $cart] = $this->shop();
        $checkout = ['method' => 'checkout', 'params' => []];
        $state = Wire::fromJson($cart['state']);

        $tampered = $state;
        $tampered['data']['madeBy'] = 'attacker';
        $renamed = $state;
        $renamed['name'] = 'wallet';

        $refused = [
            'a locked property changed' => [['stores::cart' => ['snapshot' => $cart, 'updates' => ['madeBy' => 'attacker']]], 403],
            'an unknown property' => [['stores::cart' => ['snapshot' => $cart, 'updates' => ['nope' => 1]]], 403],
            'a value of another type' => [['stores::cart' => ['snapshot' => $cart, 'updates' => ['items' => 'x']]], 403],
            'the state changed' => [['stores::cart' => ['snapshot' => ['state' => Wire::json($tampered), 'checksum' => $cart['checksum']], 'updates' => []]], 419],
            'another component\'s state' => [['stores::cart' => ['snapshot' => $shelf, 'updates' => []]], 403],
            'renamed' => [['stores::cart' => ['snapshot' => ['state' => Wire::json($renamed), 'checksum' => $cart['checksum']], 'updates' => []]], 419],
            'not an object' => [['stores::cart' => 'x'], 400],
            'not a map' => ['x', 400],
        ];

        foreach ($refused as $what => [$stores, $status]) {
            $response = $this->call(['snapshot' => $shelf, 'call' => $checkout, 'stores' => $stores]);
            $this->assertSame($status, $response->getStatusCode(), "{$what}: ".substr((string) $response->getContent(), 0, 300));
            $this->assertArrayNotHasKey('checkout', Record::$saved, "{$what}: nothing ran");
        }

        /** Within its type but against its #[Validate] rules: the method does not run. */
        $response = $this->call(['snapshot' => $shelf, 'call' => $checkout, 'stores' => ['stores::cart' => ['snapshot' => $cart, 'updates' => ['items' => Wire::encode([['name' => 5, 'price' => -1]])]]]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertArrayNotHasKey('checkout', Record::$saved, 'a store that fails its rules keeps the method from running');
    }

    #[DataProvider('frameworks')]
    public function test_a_stores_own_server_method_is_called_as_a_components_is(string $framework): void
    {
        $this->boot($framework);
        [, $cart] = $this->shop();
        $items = [['name' => 'Pen', 'price' => 2]];

        $response = $this->call(['snapshot' => $cart, 'updates' => ['items' => Wire::encode($items)], 'call' => ['method' => 'save', 'params' => []]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame($items, Record::$saved['cart']);

        foreach (['add', 'remove', 'count', 'mount', 'boot', 'nope'] as $method) {
            $this->assertSame(403, $this->call(['snapshot' => $cart, 'call' => ['method' => $method, 'params' => ['x', 1]]])->getStatusCode(), "{$method}() is not callable from the browser");
        }

        $this->assertSame(403, $this->call(['snapshot' => $cart, 'updates' => ['madeBy' => 'x'], 'call' => null])->getStatusCode(), 'locked');
    }

    #[DataProvider('frameworks')]
    public function test_a_store_the_browser_made_itself_is_made_on_the_server(string $framework): void
    {
        $this->boot($framework);
        $this->page('/shop');

        $response = $this->call(['snapshot' => null, 'store' => 'stores::cart', 'updates' => ['items' => Wire::encode([['name' => 'Pen', 'price' => 2]])], 'call' => ['method' => 'save', 'params' => []]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame([['name' => 'Pen', 'price' => 2]], Record::$saved['cart']);
        $this->assertSame('server', Wire::fromJson($this->json($response)['snapshot']['state'])['data']['madeBy'], 'made with its mount(), signed');

        foreach (['stores::nope', 'counter', 'pages::shop', '', 'stores::'] as $name) {
            $this->assertSame(404, $this->call(['snapshot' => null, 'store' => $name, 'call' => ['method' => 'save', 'params' => []]])->getStatusCode(), "[{$name}] is not a store");
        }

        $this->assertSame(419, $this->call(['snapshot' => null, 'store' => 5, 'call' => null])->getStatusCode(), 'a name that is not a string: no state at all');
    }

    public static function invalidStores(): array
    {
        return [
            'an event listener' => ['#[\Nitro\Fusion\Attributes\On("saved")] public function saved(): void {}', '#[On] is for components on the page'],
            'the query string' => ['#[\Nitro\Fusion\Attributes\Url] public string $q = "";', '#[Url] is for components'],
            'the history entry' => ['#[\Nitro\Fusion\Attributes\Remember] public string $q = "";', '#[Remember] is for components'],
            'fusion:model on its tag' => ['#[\Nitro\Fusion\Attributes\Modelable] public string $q = "";', '#[Modelable] is for components'],
            'another store' => ['public \Nitro\Fusion\Tests\Fixtures\Stores\Cart $cart;', 'it cannot hold another store'],
        ];
    }

    #[DataProvider('invalidStores')]
    public function test_what_belongs_to_a_component_on_the_page_is_refused_in_a_store(string $member, string $message): void
    {
        $this->boot('laravel');
        $class = 'BadStore'.md5($member);
        eval("namespace Nitro\\Fusion\\Tests\\Fuzz\\Generated; class {$class} extends \\Nitro\\Fusion\\Store { {$member} }");

        $this->expectException(\Nitro\Fusion\Compiler\CompileException::class);
        $this->expectExceptionMessage($message);

        app(\Nitro\Fusion\Compiler\Components::class)->meta("Nitro\\Fusion\\Tests\\Fuzz\\Generated\\{$class}");
    }

    #[DataProvider('frameworks')]
    public function test_a_store_is_neither_routed_nor_placed(string $framework): void
    {
        $this->boot($framework);

        try {
            \Illuminate\Support\Facades\Route::fusion('/cart-store', \Nitro\Fusion\Tests\Fixtures\Stores\Cart::class);
            $this->fail('a store is not routed');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('is a store', $e->getMessage());
        }

        try {
            app(\Nitro\Fusion\Rendering\Placement::class)->mount('stores::cart');
            $this->fail('a store is not placed');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('[stores::cart] is a store: components use it through a property of its type', $e->getMessage());
        }
    }

    #[DataProvider('frameworks')]
    public function test_stores_sent_for_a_component_that_uses_none_are_ignored(string $framework): void
    {
        $this->boot($framework);
        [, $cart] = $this->shop();
        $counter = $this->snapshots($this->page('/embed'))[0]['envelope'];

        $response = $this->call(['snapshot' => $counter, 'call' => null, 'stores' => ['stores::cart' => ['snapshot' => $cart, 'updates' => ['madeBy' => 'x']]]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertNull($this->json($response)['stores']);
    }
}
