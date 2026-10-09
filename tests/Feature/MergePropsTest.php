<?php

namespace Nitro\Tests\Feature;

use Illuminate\Testing\TestResponse;
use Nitro\Merge;
use Nitro\Support\Wire;
use Nitro\Testing\AssertableNitro;
use Nitro\Tests\Fixtures\Components\Store;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Merge props from a page's mount() ($this->merge(), $this->deepMerge(), $this->scroll()), as
 * Inertia's: a partial reload's value merged into the page's, in the browser and, through the
 * signed partial reload, on the server (/timeline).
 */
class MergePropsTest extends TestCase
{
    private function page(string $uri = '/timeline', ?string $only = null, string $id = 'fabcdefghij'): TestResponse
    {
        $this->app->forgetScopedInstances();

        $server = $only === null ? [] : [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_NITRO_NAVIGATE' => '1',
            'HTTP_X_NITRO_PARTIAL' => $id,
            'HTTP_X_NITRO_ONLY' => $only,
        ];

        return TestResponse::fromBaseResponse($this->request('GET', $uri, $server));
    }

    public function test_lists_are_appended_prepended_and_matched(): void
    {
        $append = ['mode' => 'append', 'path' => null, 'match' => []];

        $this->assertSame([1, 2, 3, 4], Merge::apply([1, 2], [3, 4], $append));
        $this->assertSame([3, 4, 1, 2], Merge::apply([1, 2], [3, 4], ['mode' => 'prepend', 'path' => null, 'match' => []]));
        $this->assertSame([3], Merge::apply('not a list', [3], $append), 'a value that is not a list is replaced');

        $this->assertSame(
            [['id' => 1, 'name' => 'php 8'], ['id' => 2, 'name' => 'js'], ['id' => 3, 'name' => 'go']],
            Merge::apply([['id' => 1, 'name' => 'php'], ['id' => 2, 'name' => 'js']], [['id' => 3, 'name' => 'go'], ['id' => 1, 'name' => 'php 8']], ['mode' => 'append', 'path' => null, 'match' => ['id']]),
            'matchOn: an item with the same id replaces the one there, in its place'
        );

        $this->assertSame(
            ['data' => [1, 2, 3], 'scroll' => ['nextPage' => 3]],
            Merge::apply(['data' => [1, 2], 'scroll' => ['nextPage' => 2]], ['data' => [3], 'scroll' => ['nextPage' => 3]], ['mode' => 'append', 'path' => 'data', 'match' => []]),
            'a path: its list is appended, the rest replaced'
        );
    }

    public function test_deep_merges_key_by_key(): void
    {
        $this->assertSame(
            ['theme' => ['color' => 'teal', 'size' => 'large'], 'list' => [1, 2]],
            Merge::apply(['theme' => ['color' => 'red', 'size' => 'large'], 'list' => [1]], ['theme' => ['color' => 'teal'], 'list' => [2]], ['mode' => 'deep', 'path' => null, 'match' => []]),
        );
    }

    /** Each framework, with the timeline's page. */
    public static function timelines(): array
    {
        $cases = [];

        foreach (self::frameworks() as $name => [$framework]) {
            $cases["{$name}, mount()"] = [$framework, '/timeline'];
        }

        return $cases;
    }

    #[DataProvider('timelines')]
    public function test_a_scroll_prop_holds_the_items_and_the_snapshot_where_it_is(string $framework, string $uri): void
    {
        $this->boot($framework);

        $response = $this->page($uri)->assertOk()->assertNitro(fn (AssertableNitro $page) => $page
            ->has('posts', 10)
            ->where('posts.0.title', 'Post 1'));

        $html = (string) $response->getContent();
        $this->assertSame(['pageName' => 'page', 'currentPage' => 1, 'nextPage' => 2, 'previousPage' => null], $this->snapshots($html)[0]['state']['memo']['scroll']['posts']);
        $this->assertStringContainsString('class="more"', $html, '$this->hasMore() on the server');
    }

    #[DataProvider('timelines')]
    public function test_a_partial_reload_of_a_merge_prop_carries_its_rule(string $framework, string $uri): void
    {
        $this->boot($framework);

        $state = Wire::fromJson((string) $this->page("{$uri}?page=2", 'posts')->json('partial.state'));

        $this->assertSame('Post 11', $state['data']['posts'][0]['title']);
        $this->assertSame(3, $state['memo']['scroll']['posts']['nextPage']);
        $this->assertSame(['posts' => ['mode' => 'append', 'path' => null, 'match' => []]], $state['memo']['merge']);
        $this->assertArrayNotHasKey('tags', $state['data']);
    }

    #[DataProvider('timelines')]
    public function test_the_server_merges_the_partial_reloads_into_its_state(string $framework, string $uri): void
    {
        $this->boot($framework);

        $snapshot = $this->snapshots((string) $this->page($uri)->getContent())[0]['envelope'];
        $id = Wire::fromJson($snapshot['state'])['id'];
        $partials = [
            $this->page("{$uri}?page=2", 'posts', $id)->json('partial'),
            $this->page($uri, 'tags', $id)->json('partial'),
            $this->page($uri, 'settings', $id)->json('partial'),
        ];

        $this->app->forgetScopedInstances();
        $response = $this->update(['snapshot' => $snapshot, 'partials' => $partials, 'updates' => [], 'call' => ['method' => 'touch', 'params' => []]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $state = Wire::fromJson($this->json($response)['snapshot']['state']);
        $data = $state['data'];

        $this->assertCount(20, $data['posts'], 'page 2 appended to page 1');
        $this->assertSame(3, $state['memo']['scroll']['posts']['nextPage'], 'where it is, from the last partial reload');
        $this->assertSame(['php', 'js'], array_column($data['tags'], 'name'), 'matched on id: not doubled');
        $this->assertSame(['theme' => ['color' => 'teal', 'size' => 'large']], $data['settings'], 'merged key by key');
        $this->assertSame(1, $data['touched']);
    }

    /**
     * From mount(): a merge prop's closure runs only when the request wants the property, and
     * $this->wants() skips the work a partial reload does not need; a first visit does it all.
     */
    #[DataProvider('frameworks')]
    public function test_mount_does_only_what_a_partial_reload_wants(string $framework): void
    {
        $this->boot($framework);

        Store::$log = [];
        $this->page('/timeline')->assertOk()->assertNitro(fn (AssertableNitro $page) => $page->where('count', 23));
        $this->assertSame(['posts', 'tags', 'count'], Store::$log, 'a first visit: everything');

        Store::$log = [];
        $this->page('/timeline?page=2', 'posts')->assertOk();
        $this->assertSame(['posts'], Store::$log, 'a partial reload of posts: posts only');
    }
}
