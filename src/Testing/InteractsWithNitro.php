<?php

namespace Nitro\Testing;

use Illuminate\Testing\TestResponse;
use Nitro\Build\BrowserNames;
use Nitro\Rendering\PageData;
use Nitro\Support\Config;
use Nitro\Support\Protocol;
use Nitro\Support\Wire;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * #[Server] calls in an app's tests, as the browser makes them: the component's signed state from
 * a page the test fetched, the properties the browser changed, the method and its arguments, sent
 * to Nitro's endpoint through the test's own client (its user, its session). The server checks
 * the call as it checks the browser's: the signature, #[Locked], types, #[Validate], the page's
 * middleware. An obfuscated build's names are the test's own: they are translated here.
 *
 *   use Nitro\Testing\InteractsWithNitro;
 *
 *   $page = $this->actingAs($user)->get('/posts/1');
 *
 *   $this->callNitro($page, 'addComment', updates: ['comment' => 'Nice post'])
 *       ->assertOk()
 *       ->assertNitro(fn (AssertableNitro $post) => $post->hasNoErrors()->where('comment', ''));
 *
 * @phpstan-require-extends \Illuminate\Foundation\Testing\TestCase
 */
trait InteractsWithNitro
{
    /**
     * Call a #[Server] method of the page's component (or of the component named, such as
     * "posts.comment-form", the first of them on the page), with arguments, after the browser
     * changed these properties. The answer's state reads with assertNitro().
     *
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $page  a page the test fetched: rendered, or a nitro:navigate visit's data
     * @param  list<mixed>  $params
     * @param  array<string, mixed>  $updates  property => value, as nitro:model or $set would set it
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function callNitro(TestResponse $page, string $method, array $params = [], array $updates = [], ?string $component = null): TestResponse
    {
        $names = $this->app->make(BrowserNames::class);
        $envelope = $this->nitroEnvelope($page, $component === null ? null : $names->component($component));

        /** The next request starts with fresh parts, as a new request in the browser would. */
        $this->app->forgetScopedInstances();

        return $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', 'Sec-Fetch-Site' => 'same-origin'])
            ->postJson($this->app->make(Config::class)->endpoint(Protocol::path('update')), [
                'snapshot' => $envelope,
                'updates' => collect($updates)->mapWithKeys(fn (mixed $value, string $property) => [$names->property($property) => Wire::encode($value)])->all(),
                'call' => ['method' => $names->method($method), 'params' => array_map(Wire::encode(...), $params)],
            ]);
    }

    /**
     * The signed state of the page, or of the first component of this (browser's) name on it.
     *
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $page
     * @return array<string, mixed>
     */
    private function nitroEnvelope(TestResponse $page, ?string $name): array
    {
        $content = (string) $page->getContent();
        $visit = json_decode($content, true);
        $pageData = $this->app->make(PageData::class);

        if ($name === null) {
            $envelope = is_array($visit) && isset($visit['snapshot']) ? $visit['snapshot'] : $pageData->pageState($content);
            PHPUnit::assertIsArray($envelope, 'The response has no Nitro page: fetch the page first, or name the component.');

            return $envelope;
        }

        foreach ($pageData->statesIn($content) as $envelope) {
            if ((Wire::fromJson((string) $envelope['state'])['name'] ?? null) === $name) {
                return $envelope;
            }
        }

        PHPUnit::fail("No [{$name}] component on the page.");
    }
}
