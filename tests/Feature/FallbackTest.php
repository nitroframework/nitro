<?php

namespace Nitro\Tests\Feature;

use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A component that fails shows a fallback in its place, and the rest of the page works on: its
 * fallback(), else nitro.fallbacks.view, else Nitro's message. On when APP_DEBUG is off, or
 * with nitro.fallbacks.enabled; in development the error shows.
 */
class FallbackTest extends TestCase
{
    private function fallbacks(bool $on, ?string $view = null): void
    {
        $this->app->make('config')->set('nitro.fallbacks', ['enabled' => $on, 'view' => $view]);
        /** As between requests: the parts read nitro.* again (Config is per request). */
        $this->app->forgetScopedInstances();
    }

    #[DataProvider('frameworks')]
    public function test_a_component_that_fails_to_start_shows_its_fallback(string $framework): void
    {
        $this->boot($framework);
        $this->fallbacks(true);

        $response = $this->get('/fallbacks?flaky=fail');
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode(), $html);
        $this->assertMatchesRegularExpression('#<div nitro:fallback nitro:id="f[a-z0-9]{10}">Flaky is resting &lt;for now&gt;\.</div>#', $html, 'its fallback(), as text, with its id (a parent\'s view keeps it there)');
        $this->assertStringContainsString('The gauge reads 42.', $html, 'the rest of the page works on');
        $this->assertStringNotContainsString('Flaky is up', $html);
        $this->assertCount(2, $this->snapshots($html), 'the counter and the gauge go live; the fallback has no state');
    }

    #[DataProvider('frameworks')]
    public function test_without_a_fallback_of_its_own_the_applications_or_nitros(string $framework): void
    {
        $this->boot($framework);
        $this->fallbacks(true);

        $this->assertMatchesRegularExpression('#<div nitro:fallback nitro:id="\w+"><p class="nitro-fallback">This part of the page could not load\. <a href="">Try again</a></p></div>#', (string) $this->get('/fallbacks?gauge=fail')->getContent());

        $this->fallbacks(true, 'partials.fallback');
        $this->assertMatchesRegularExpression('#<div nitro:fallback nitro:id="\w+"><aside class="app-fallback">Back soon\.</aside>#', (string) $this->get('/fallbacks?gauge=fail')->getContent());
    }

    #[DataProvider('frameworks')]
    public function test_with_fallbacks_off_the_error_shows(string $framework): void
    {
        $this->boot($framework);
        $this->fallbacks(false);

        $this->assertSame(500, $this->get('/fallbacks?flaky=fail')->getStatusCode());
        $this->assertStringNotContainsString('data-fallback', (string) $this->get('/fallbacks')->getContent(), 'the browser is given no fallback either');
    }

    /** abort() is the request's answer, not the component's failure. */
    #[DataProvider('frameworks')]
    public function test_an_http_exception_is_not_a_failure(string $framework): void
    {
        $this->boot($framework);
        $this->fallbacks(true);

        $this->assertSame(403, $this->get('/fallbacks?flaky=forbidden')->getStatusCode());
    }

    #[DataProvider('frameworks')]
    public function test_a_server_call_that_fails_answers_with_the_fallback(string $framework): void
    {
        $this->boot($framework);
        $this->fallbacks(true);
        $html = (string) $this->get('/fallbacks')->getContent();
        $flaky = array_values(array_filter($this->snapshots($html), fn (array $s) => $s['state']['name'] === 'flaky'))[0]['envelope'];

        $response = $this->update(['snapshot' => $flaky, 'call' => ['method' => 'save', 'params' => []]]);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['message' => 'Server Error', 'fallback' => 'Flaky is resting &lt;for now&gt;.'], $this->json($response));

        $this->fallbacks(false);
        $this->assertArrayNotHasKey('fallback', $this->json($this->update(['snapshot' => $flaky, 'call' => ['method' => 'save', 'params' => []]])));
    }

    /**
     * A visit (nitro:navigate asks for the page's data): a child that fails goes as its fallback,
     * by its id, and the page renders in place; the browser puts it where the child goes.
     */
    #[DataProvider('frameworks')]
    public function test_a_visit_carries_a_failed_childs_fallback(string $framework): void
    {
        $this->boot($framework);
        $this->fallbacks(true);
        $visit = fn () => $this->request('GET', '/outage', ['HTTP_X_NITRO_NAVIGATE' => '1', 'HTTP_ACCEPT' => 'application/json']);

        $response = $visit();
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $children = $this->json($response)['children'];

        $failed = array_filter($children, fn (array $child) => isset($child['fallback']));
        $this->assertCount(1, $failed, 'Flaky failed: its fallback');
        [$id, $child] = [array_key_first($failed), reset($failed)];
        $this->assertSame('<div nitro:fallback nitro:id="'.$id.'">Flaky is resting &lt;for now&gt;.</div>', $child['fallback']);

        $this->fallbacks(false);
        $this->assertSame(500, $visit()->getStatusCode(), 'off, the failure is the visit\'s, as in development');
    }

    /** What a component that fails to render in the browser shows: given with @nitroScripts. */
    #[DataProvider('frameworks')]
    public function test_the_browser_is_given_the_default(string $framework): void
    {
        $this->boot($framework);
        $this->fallbacks(true, 'partials.fallback');

        $this->assertStringContainsString('data-fallback="&lt;aside class=&quot;app-fallback&quot;&gt;Back soon.&lt;/aside&gt;', (string) $this->get('/fallbacks')->getContent());
    }
}
