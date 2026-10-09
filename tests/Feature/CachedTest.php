<?php

namespace Nitro\Tests\Feature;

use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentReader;
use Nitro\Support\Wire;
use Nitro\Tests\Fixtures\Invalid\CachedBrowserMethod;
use Nitro\Tests\Fixtures\Pages\Catalog;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * #[Cached]: mount()'s state and a #[Server] method's result kept in the application's cache, by
 * what they were worked out from, until their time is up or Nitro::expire() names a tag of theirs.
 */
class CachedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Catalog::$mounts = 0;
        Catalog::$searches = 0;
    }

    /** @return array<string, mixed> the page's state */
    private function page(string $uri): array
    {
        $response = $this->get($uri);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->snapshots((string) $response->getContent())[0]['state']['data'];
    }

    #[DataProvider('frameworks')]
    public function test_mount_runs_once_for_the_same_inputs(string $framework): void
    {
        $this->boot($framework);

        $first = $this->page('/catalog');
        $second = $this->page('/catalog');

        $this->assertSame(1, Catalog::$mounts, 'the second visit takes mount()\'s state from the cache');
        $this->assertSame(['by name', 'mount 1'], $second['items']);
        $this->assertSame($first, $second);

        $bySize = $this->page('/catalog?sort=size');
        $this->assertSame(2, Catalog::$mounts, 'another #[Url] value is another input');
        $this->assertSame(['by size', 'mount 2'], $bySize['items']);
        $this->assertSame('size', $bySize['sort']);
    }

    #[DataProvider('frameworks')]
    public function test_expiring_a_tag_forgets_what_was_kept_under_it(string $framework): void
    {
        $this->boot($framework);

        $this->page('/catalog');
        $this->nitro()->expire('catalog');
        $again = $this->page('/catalog');

        $this->assertSame(2, Catalog::$mounts);
        $this->assertSame(['by name', 'mount 2'], $again['items']);

        $this->nitro()->expire('another');
        $this->page('/catalog');
        $this->assertSame(2, Catalog::$mounts, 'another tag leaves it');
    }

    #[DataProvider('frameworks')]
    public function test_a_server_method_returns_what_it_returned_for_the_same_arguments(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshots((string) $this->get('/catalog')->getContent())[0]['envelope'];
        $search = fn (string $query) => Wire::decode($this->json($this->update([
            'snapshot' => $snapshot,
            'call' => ['method' => 'search', 'params' => [$query]],
        ]))['effects']['returns']);

        $this->assertSame(['ada', 1], $search('ada'));
        $this->assertSame(['ada', 1], $search('ada'), 'kept');
        $this->assertSame(['bea', 2], $search('bea'), 'another argument');
        $this->assertSame(2, Catalog::$searches);

        $this->nitro()->expire('catalog');
        $this->assertSame(['ada', 3], $search('ada'));
    }

    /** In a Blade view a #[Server] method sets properties: a kept call sets them again, without running. */
    #[DataProvider('frameworks')]
    public function test_a_kept_call_changes_the_state_as_it_did(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshots((string) $this->get('/catalog')->getContent())[0]['envelope'];
        $found = fn () => Wire::fromJson($this->json($this->update([
            'snapshot' => $snapshot,
            'call' => ['method' => 'search', 'params' => ['ada']],
        ]))['snapshot']['state'])['data']['found'];

        $this->assertSame(['ada', 1], $found());
        $this->assertSame(['ada', 1], $found(), 'set again from the cache');
        $this->assertSame(1, Catalog::$searches);
    }

    /** search() depends on $sort only (by: ['sort']): what else changed since does not matter. */
    #[DataProvider('frameworks')]
    public function test_a_result_is_kept_by_the_properties_it_depends_on(string $framework): void
    {
        $this->boot($framework);
        $first = $this->snapshots((string) $this->get('/catalog')->getContent())[0]['envelope'];
        $search = fn (array $snapshot, array $updates = []) => $this->json($this->update([
            'snapshot' => $snapshot,
            'updates' => array_map(Wire::encode(...), $updates),
            'call' => ['method' => 'search', 'params' => ['ada']],
        ]))['snapshot'];

        $after = $search($first);
        $search($after, ['items' => ['changed']]);
        $this->assertSame(1, Catalog::$searches, '$found and $items changed; $sort did not');

        $search($after, ['sort' => 'size']);
        $this->assertSame(2, Catalog::$searches, 'another $sort');
    }

    #[DataProvider('frameworks')]
    public function test_a_browser_method_cannot_be_cached(string $framework): void
    {
        $this->boot($framework);

        try {
            ComponentReader::read(CachedBrowserMethod::class, 'cached-browser-method', 'nitro.components.cached-browser-method');
            $this->fail('Expected a compile error');
        } catch (CompileException $e) {
            $this->assertStringContainsString('#[Cached] on total(): it keeps what mount() or a #[Server] method gives, and total() runs in the browser', $e->getMessage());
        }
    }

    public function test_a_result_is_kept_for_a_second_at_least(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new \Nitro\Attributes\Cached(0);
    }
}
