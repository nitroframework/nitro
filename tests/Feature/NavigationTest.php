<?php

namespace Nitro\Tests\Feature;

use Nitro\State\Snapshot;
use Nitro\Support\Wire;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * nitro:navigate visits: the page's URL, asked with X-Nitro-Navigate, answers with the data the
 * browser renders the page from (Laravel still routes it: middleware, bindings, redirects).
 */
class NavigationTest extends TestCase
{
    private function visit(string $uri): Response
    {
        return $this->request('GET', $uri, ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_NITRO_NAVIGATE' => '1']);
    }

    private function state(array $envelope): array
    {
        return Wire::fromJson($envelope['state']);
    }

    #[DataProvider('frameworks')]
    public function test_a_page_answers_a_visit_with_its_state_and_title_not_html(string $framework): void
    {
        $this->boot($framework);

        $response = $this->visit('/students/1');
        $page = $this->json($response);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertContains('X-Nitro-Navigate', $response->getVary());
        $this->assertStringNotContainsString('<h1>', (string) $response->getContent());

        $this->assertSame(1, $page['nitro']);
        $this->assertSame('Student', $page['title']);
        $this->assertSame('components.layouts.app', $page['layout']);
        $this->assertSame([], $page['children']);

        $state = $this->state($page['snapshot']);
        $this->assertSame('pages::student-page', $state['name']);
        $this->assertSame('Ada Lovelace', $state['data']['student']['name']);
        $this->assertSame(['method' => 'GET', 'uri' => '/students/1'], $state['memo']['route']);

        /** Signed as a server-rendered page's state is: #[Server] calls accept it. */
        $this->assertSame(200, $this->update([
            'snapshot' => $page['snapshot'],
            'updates' => [],
            'call' => ['method' => 'rename', 'params' => ['Ada King']],
        ])->getStatusCode());
    }

    #[DataProvider('frameworks')]
    public function test_the_title_is_the_layouts(string $framework): void
    {
        $this->boot($framework);

        /** No #[Title]: the layout's own default. */
        $this->assertSame('Nitro', $this->json($this->visit('/counter'))['title']);
    }

    #[DataProvider('frameworks')]
    public function test_the_html_page_varies_on_the_header_and_names_its_layout(string $framework): void
    {
        $this->boot($framework);

        $response = $this->get('/counter');

        $this->assertContains('X-Nitro-Navigate', $response->getVary());
        $this->assertStringContainsString('nitro:page="components.layouts.app"', (string) $response->getContent());
    }

    #[DataProvider('frameworks')]
    public function test_children_with_mount_come_from_the_server(string $framework): void
    {
        $this->boot($framework);

        $page = $this->json($this->visit('/board'));
        $pageId = $this->state($page['snapshot'])['id'];

        $this->assertCount(1, $page['children']);
        [$id, $envelope] = [array_key_first($page['children']), reset($page['children'])];
        $state = $this->state($envelope);

        /** Keyed as the browser's render keys it, and signed. */
        $this->assertSame($pageId.'.c0', $id);
        $this->assertSame($id, $state['id']);
        $this->assertSame('todo-list', $state['name']);
        $this->assertSame(['Plan', 'Build'], array_column($state['data']['todos'], 'title'));
        $this->assertSame(3, $state['data']['nextId'], 'mount() ran');
        Snapshot::fromEnvelope($envelope, app(\Nitro\Support\Signer::class));

        /** The todo items have no mount(): the browser creates them. */
        $this->assertStringNotContainsString('todo-item', json_encode($page['children']));
    }

    #[DataProvider('frameworks')]
    public function test_the_progress_bar_settings_reach_the_browser(string $framework): void
    {
        $this->boot($framework);

        preg_match('/data-progress="([^"]*)"/', (string) $this->get('/counter')->getContent(), $m);
        $settings = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);

        /** The application's config/nprogress.php over the package's defaults. */
        $this->assertSame('#e11d48', $settings['color']);
        $this->assertSame('4px', $settings['height']);
        $this->assertTrue($settings['spinner']);
        $this->assertSame(0, $settings['delay']);
        $this->assertSame(0.15, $settings['minimum']);
        $this->assertSame(0.92, $settings['maximum']);
        $this->assertSame(150, $settings['fadeSpeed']);
        $this->assertSame('top', $settings['position']);

        $this->app->make('config')->set('nprogress.show', false);
        $this->assertStringNotContainsString('data-progress', (string) $this->get('/counter')->getContent());
    }

    #[DataProvider('frameworks')]
    public function test_a_visit_goes_through_the_routes_middleware_and_bindings(string $framework): void
    {
        $this->boot($framework);

        /** Laravel's auth answers a JSON request with 401; the browser then loads the page normally (and is sent to log in). */
        $response = $this->visit('/private');
        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringNotContainsString('snapshot', (string) $response->getContent());

        $this->assertSame(404, $this->visit('/students/99')->getStatusCode());
    }

    /** After a deploy, a browser running the earlier build's components loads the page in full. */
    #[DataProvider('frameworks')]
    public function test_a_visit_from_another_build_is_answered_with_a_full_load(string $framework): void
    {
        $this->boot($framework);
        $tag = $this->app->make(\Nitro\Build\Bundle::class)->tag();
        /** Each request its own, as in production. */
        $visit = fn (string $version) => tap($this->request('GET', '/students/1?tab=info', [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_NITRO_NAVIGATE' => '1',
            'HTTP_X_NITRO_VERSION' => $version,
        ]), fn () => $this->app->forgetScopedInstances());

        $current = $visit($tag);
        $this->assertSame(200, $current->getStatusCode());
        $this->assertSame(1, $this->json($current)['nitro']);

        $stale = $visit('an-earlier-build');
        $this->assertSame(409, $stale->getStatusCode());
        $this->assertSame('http://localhost/students/1?tab=info', $stale->headers->get('X-Nitro-Location'));
        $this->assertContains('X-Nitro-Navigate', $stale->getVary());

        /** Without the header (not the runtime's visit) there is nothing to compare. */
        $this->assertSame(200, $this->visit('/students/1')->getStatusCode());
    }
}
