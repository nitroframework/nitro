<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Nitro\Testing\AssertableNitro;
use Nitro\Testing\InteractsWithNitro;
use Tests\TestCase;

/** Nitro in an app, tested as the app's own tests test it: Laravel's TestCase, Nitro's helpers. */
class NitroTest extends TestCase
{
    use InteractsWithNitro;

    public function test_a_page_arrives_with_its_state(): void
    {
        $this->get('/counter')
            ->assertOk()
            ->assertSee('<title>Counter', false)
            ->assertSee('<script type="application/json" data-nitro-state>', false)
            ->assertDontSee('<!--nitro:state-->', false)
            ->assertNitro(fn (AssertableNitro $page) => $page->page('pages::counter')->where('count', 0));
    }

    public function test_a_server_call_runs_with_what_the_browser_changed(): void
    {
        $page = $this->get('/counter');

        $this->callNitro($page, 'save', updates: ['count' => 3])
            ->assertOk()
            ->assertNitro(fn (AssertableNitro $counter) => $counter->where('count', 3));

        $this->assertSame(3, Cache::get('count'));
    }

    public function test_teleported_content_reaches_its_portal(): void
    {
        Cache::forever('count', 7);

        $html = (string) $this->get('/counter')->getContent();

        $this->assertMatchesRegularExpression('#<aside id="dock">.*?<p class="saved">Count: 7</p>.*?</aside>#s', $html);
    }

    public function test_the_address_sets_a_url_property(): void
    {
        $this->get('/article?topic=laravel')
            ->assertOk()
            ->assertNitro(fn (AssertableNitro $page) => $page->page('pages::article')->where('topic', 'laravel'));
    }

    public function test_html_made_on_the_server_travels_beside_the_state(): void
    {
        $page = $this->get('/article?topic=laravel');

        $this->assertStringContainsString('<h1>Laravel</h1>', $this->html($page));
        $this->assertStringNotContainsString('<h1>', (string) $this->envelope($page)['state'], 'not in the signed state');

        $this->assertStringContainsString('<h1>Vue</h1>', $this->html($this->callNitro($page, 'choose', ['vue'])));
    }

    public function test_the_vue_view_is_in_the_build(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

        $this->assertArrayHasKey('resources/views/vue/article.vue', $manifest);
    }

    /** The page's signed state: from its HTML's block, or a #[Server] call's answer. */
    private function envelope(TestResponse $response): array
    {
        $answer = json_decode((string) $response->getContent(), true);

        if (is_array($answer) && isset($answer['snapshot'])) {
            return $answer['snapshot'];
        }

        preg_match('#<script type="application/json" data-nitro-state>(.*?)</script>#s', (string) $response->getContent(), $block);

        return array_values(json_decode($block[1] ?? '{}', true))[0] ?? [];
    }

    /** The HTML of its #[Html] methods, as the browser receives it. */
    private function html(TestResponse $response): string
    {
        return implode('', array_column($this->envelope($response)['html'] ?? [], 1));
    }
}
