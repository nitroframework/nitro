<?php

namespace Nitro\Fusion\Tests\Feature;

use Illuminate\Testing\TestResponse;
use Nitro\Fusion\Fusion;
use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Tests\Fixtures\Components\Store;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Once shared data (Fusion::once(), Fusion::shareOnce()) and flash data (Fusion::flash()), as
 * Inertia's once props and flash data.
 */
class OnceAndFlashTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Store::$onceCalls = 0;
    }

    /** A fusion:navigate visit, with the once data the browser says it has. */
    private function visit(string $uri, string $once = ''): TestResponse
    {
        $this->app->forgetScopedInstances();
        Store::$onceCalls = 0;

        return TestResponse::fromBaseResponse($this->request('GET', $uri, array_filter([
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FUSION_NAVIGATE' => '1',
            'HTTP_X_FUSION_ONCE' => $once,
        ])));
    }

    private function shared(TestResponse $response): array
    {
        return Wire::decode($response->json('shared'));
    }

    private function globals(string $html): array
    {
        preg_match('#<script type="application/json" data-fusion-globals>(.*?)</script>#s', $html, $m);

        return json_decode($m[1], true);
    }

    #[DataProvider('frameworks')]
    public function test_once_data_is_sent_until_the_browser_has_it(string $framework): void
    {
        $this->boot($framework);

        $page = $this->globals((string) $this->get('/greeting')->getContent());
        $this->assertSame(['PK', 'US'], Wire::decode($page['shared'])['countries']);
        $this->assertSame(['countries' => null], $page['once']);

        $response = $this->visit('/greeting', 'countries');
        $this->assertArrayNotHasKey('countries', $this->shared($response), 'the browser has it');
        $this->assertArrayHasKey('user', $this->shared($response));
        $this->assertSame(['countries' => null], $response->json('once'), 'still named: the browser keeps it');
        $this->assertSame(0, Store::$onceCalls, 'its closure is not called for the browser');

        $this->assertSame(['PK', 'US'], $this->shared($this->visit('/greeting'))['countries'], 'a browser without it gets it');
        $this->assertSame(1, Store::$onceCalls, 'called once for the visit (not again for the layout title)');
    }

    #[DataProvider('frameworks')]
    public function test_once_data_can_expire_and_be_sent_fresh(string $framework): void
    {
        $this->boot($framework);
        $fusion = $this->app->make(Fusion::class);

        $fusion->share('rates', $fusion->once(fn () => [1.5])->until(60));
        $fusion->share('menu', $fusion->once(fn () => ['home'])->fresh());

        $this->app->instance('request', \Illuminate\Http\Request::create('/', 'GET', server: ['HTTP_X_FUSION_ONCE' => 'rates,menu,countries']));
        $shared = Wire::decode($fusion->sharedForBrowser());
        $once = $fusion->onceForBrowser();

        $this->assertArrayNotHasKey('rates', $shared);
        $this->assertSame(['home'], $shared['menu'], 'fresh: sent although the browser has it');
        $this->assertEqualsWithDelta(time() + 60, $once['rates'], 2);
        $this->assertNull($once['menu']);
    }

    #[DataProvider('frameworks')]
    public function test_flash_data_goes_with_the_page_after_a_redirect_then_is_gone(string $framework): void
    {
        $this->boot($framework);

        $this->assertSame(302, $this->get('/flash-and-redirect')->getStatusCode());
        $this->app->forgetScopedInstances();

        $html = (string) $this->get('/greeting')->getContent();
        $this->assertStringContainsString('<p class="toast">Saved</p>', $html, '$this->flashed() on the server');
        $this->assertSame(['toast' => 'Saved'], Wire::decode($this->globals($html)['flash']));

        $this->app->forgetScopedInstances();
        $html = (string) $this->get('/greeting')->getContent();
        $this->assertStringContainsString('<p class="toast">no toast</p>', $html, 'delivered once');
        $this->assertSame([], Wire::decode($this->globals($html)['flash']));
    }

    #[DataProvider('frameworks')]
    public function test_flash_data_from_a_server_call_comes_with_its_answer(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshots((string) $this->get('/greeting')->getContent())[0]['envelope'];

        $this->app->forgetScopedInstances();
        $answer = $this->json($this->update(['snapshot' => $snapshot, 'updates' => [], 'call' => ['method' => 'celebrate', 'params' => []]]));
        $this->assertSame(['toast' => 'Well done'], Wire::decode($answer['flash']));

        $this->app->forgetScopedInstances();
        $answer = $this->json($this->update(['snapshot' => $answer['snapshot'], 'updates' => [], 'call' => null]));
        $this->assertSame([], Wire::decode($answer['flash']), 'delivered once');
    }

    #[DataProvider('frameworks')]
    public function test_a_server_call_that_redirects_leaves_its_flash_data_for_the_page_it_goes_to(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshots((string) $this->get('/greeting')->getContent())[0]['envelope'];

        $this->app->forgetScopedInstances();
        $answer = $this->json($this->update(['snapshot' => $snapshot, 'updates' => [], 'call' => ['method' => 'celebrateElsewhere', 'params' => []]]));
        $this->assertNull($answer['flash']);
        $this->assertSame('/rendered/counter', $answer['effects']['redirect']['url']);

        $this->assertSame(['toast' => 'Over here'], Wire::decode($this->visit('/rendered/counter')->json('flash')));
    }
}
