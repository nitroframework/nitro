<?php

namespace Nitro\Tests\Feature;

use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RenderingTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_components_render_on_the_server_inside_any_blade_view(string $framework): void
    {
        $this->boot($framework);

        $response = $this->get('/embed');
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode(), $html);
        $this->assertStringContainsString('<span class="count">0</span>', $html);
        $this->assertStringContainsString('<p>Doubled: 0</p>', $html);
        $this->assertStringContainsString('<small>Normal</small>', $html);
        $this->assertStringContainsString('nitro:click="increment"', $html);
        $this->assertStringContainsString('1 item left', $html);

        /** Counter, the list, and one child per todo, each with its own signed state. */
        $snapshots = $this->snapshots($html);
        $names = array_map(fn ($s) => $s['state']['name'], $snapshots);
        $this->assertSame(['counter', 'todo-list', 'todo-item', 'todo-item'], $names);

        $this->assertSame(2, $snapshots[0]['state']['data']['step'], 'step prop from the tag');

        /** Children are keyed as the browser keys them: parent id + key. */
        $listId = $snapshots[1]['state']['id'];
        $this->assertSame([$listId.'.1', $listId.'.2'], [$snapshots[2]['state']['id'], $snapshots[3]['state']['id']]);

        $this->assertStringContainsString('<script src="/_nitro/nitro.js?v=', $html);
        $this->assertStringContainsString('<script src="/_nitro/app.js?v=', $html);
    }

    #[DataProvider('frameworks')]
    public function test_a_routed_page_binds_its_model_through_mount(string $framework): void
    {
        $this->boot($framework);

        $response = $this->get('/students/1');
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode(), $html);
        $this->assertStringContainsString('<title>Student</title>', $html);
        $this->assertStringContainsString('<h1>Ada Lovelace</h1>', $html);
        $this->assertStringContainsString('<p class="shout">ADA LOVELACE!</p>', $html);
        $this->assertStringContainsString('nitro:page="components.layouts.app"', $html);

        /** The browser gets the model's visible attributes, and the server remembers the key. */
        $state = $this->snapshots($html)[0]['state'];
        $this->assertSame(['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com'], $state['data']['student']);
        $this->assertSame(1, $state['memo']['models']['student']['key']);
        $this->assertSame(['method' => 'GET', 'uri' => '/students/1'], $state['memo']['route']);

        $this->assertSame(404, $this->get('/students/99')->getStatusCode());
    }

    #[DataProvider('frameworks')]
    public function test_a_page_without_mount(string $framework): void
    {
        $this->boot($framework);

        $response = $this->get('/counter');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertStringContainsString('<span class="count">0</span>', (string) $response->getContent());
    }

    #[DataProvider('frameworks')]
    public function test_the_server_renders_computed_values_poll_init_and_confirm(string $framework): void
    {
        $this->boot($framework);

        $html = (string) $this->get('/clock')->getContent();

        $this->assertStringContainsString('<p class="label">0 ticks</p>', $html);
        $this->assertStringContainsString('<p class="again">0 ticks</p>', $html);
        $this->assertStringContainsString('nitro:init="start"', $html);
        $this->assertStringContainsString('nitro:poll.100ms="tick"', $html);
        $this->assertStringContainsString('nitro:confirm="Reset the clock?"', $html);

        /** WithPagination and WithSorting run in PHP too, for the server's render. */
        $this->assertMatchesRegularExpression('/<li>Eva<\/li>\s*<li>Bea<\/li>\s*<li>Gus<\/li>/', $html);
        $this->assertStringContainsString('<p class="where">1-3 of 7, page 1 of 3</p>', $html);

        /** Computed values are not state. */
        $this->assertArrayNotHasKey('label', $this->snapshots($html)[0]['state']['data']);

        /** Outside a render (a #[Server] method), $this->label is worked out afresh. */
        $clock = new \Nitro\Tests\Fixtures\Components\Clock;
        $clock->ticks = 4;
        $this->assertSame('4 ticks', $clock->label);
        $clock->ticks = 5;
        $this->assertSame('5 ticks', $clock->label);
    }

    /**
     * #[Url]: the page reads the query string when it creates the component (the WithPagination
     * page, a search under another name), each value as the property's type; one that does not
     * fit is ignored.
     */
    #[DataProvider('frameworks')]
    public function test_url_properties_come_from_the_query_string(string $framework): void
    {
        $this->boot($framework);

        $html = (string) $this->get('/clock?q=a&page=2')->getContent();
        $this->assertStringContainsString('4-6 of 6, page 2 of 2', $html);
        $this->assertMatchesRegularExpression('#<li>Fay</li>\s*<li>Cal</li>\s*<li>Dan</li>#', $html);

        $this->app->forgetScopedInstances();
        $html = (string) $this->get('/clock?page=abc&q[]=x')->getContent();
        $this->assertStringContainsString('1-3 of 7, page 1 of 3', $html, 'values of another type are ignored');
    }

    /**
     * Slots: what is between a child's tags renders in the parent's view (its variables) and goes
     * where the child's view puts $slot; <nitro:slot name="..."> where it puts $slots['...'].
     * Whitespace after the tags stays as Blade keeps it.
     */
    #[DataProvider('frameworks')]
    public function test_slots_render_the_parents_content_inside_the_child(string $framework): void
    {
        $this->boot($framework);

        $html = (string) $this->get('/shelf')->getContent();

        $this->assertMatchesRegularExpression('#<article nitro:id="[^"]+\.c0" nitro:name="card"[^>]*class="card">\s*<header>\s*<h3>Stock</h3>#', $html);
        $this->assertMatchesRegularExpression('#<div class="body">\s*<p class="count">Books: 1</p>.*<li>Atlas</li>\s*<li>Bible</li>.*nitro:name="todo-item".*<span[^>]*>Books</span>.*</div>#s', $html);
        $this->assertMatchesRegularExpression('#<footer><button type="button" class="add" nitro:click="add">Add</button></footer>#', $html);
        $this->assertStringNotContainsString('<p class="blank">', explode('nitro:name="card"', $html)[1]);

        $empty = explode('nitro:name="card"', $html)[2];
        $this->assertStringContainsString('<p class="blank">Nothing here yet.</p>', $empty);
        $this->assertStringNotContainsString('<footer>', $empty);
        $this->assertStringContainsString('<div class="body"></div>', $empty);
    }

    /**
     * Viewi's portals: @teleport content renders at its @portal once the response is rendered,
     * wherever the portal is (here before the component), in any web response and in a routed page.
     */
    #[DataProvider('frameworks')]
    public function test_teleported_content_renders_at_its_portal(string $framework): void
    {
        $this->boot($framework);

        foreach (['/portals', '/journal'] as $url) {
            $html = (string) $this->get($url)->getContent();

            $this->assertStringNotContainsString('PORTAL_', $html, $url);
            $this->assertMatchesRegularExpression('#<aside id="dock"><i id="portal_dock" style="display: none !important;"></i>\s*<aside class="journal-dock">\s*<span class="dock-note">first entry</span>\s*</aside>\s*<i id="portal_dock_end"#', $html, $url);
            $this->assertMatchesRegularExpression('#<i id="portal_toasts"[^>]*></i><i id="portal_toasts_end"#', $html, $url);
            /** The component on /portals, the page on /journal. */
            $this->assertStringNotContainsString('journal-dock', explode('</section>', preg_split('/nitro:name="(?:pages::)?journal"/', $html)[1])[0], $url);
        }
    }

    /**
     * A visit renders the layout for its title only: its @portal is that render's, so the next
     * request (a test's, a long-running worker's) does not find it already defined.
     */
    #[DataProvider('frameworks')]
    public function test_a_visit_leaves_the_layouts_portals_as_they_were(string $framework): void
    {
        $this->boot($framework);
        $visit = fn () => $this->request('GET', '/journal', ['HTTP_X_NITRO_NAVIGATE' => '1', 'HTTP_ACCEPT' => 'application/json']);

        $this->assertSame(200, $visit()->getStatusCode());
        $this->assertSame(200, $visit()->getStatusCode(), 'a second visit');
        $this->assertSame(200, $this->get('/journal')->getStatusCode(), 'then the page in full');
    }

    /** @nitroScript is the browser's: the server renders nothing of it, its HTML the same around it. */
    #[DataProvider('frameworks')]
    public function test_a_script_is_never_rendered_on_the_server(string $framework): void
    {
        $this->boot($framework);
        $html = (string) $this->get('/libraries')->getContent();

        $this->assertSame(2, substr_count($html, 'class="spark"'), 'the components render');
        $this->assertStringNotContainsString('window.sparks', $html);
        $this->assertStringNotContainsString('nitroScript', $html);
        $this->assertStringContainsString('window.sparks', $this->app->make(\Nitro\Build\Bundle::class)->compile(), 'the bundle has it');
    }

    /** PHP's functions, Str and preg_* in a view and its methods (the browser's render is TemplateParityTest's). */
    #[DataProvider('frameworks')]
    public function test_php_functions_str_and_preg_render(string $framework): void
    {
        $this->boot($framework);

        $html = (string) $this->get('/links')->getContent();

        $this->assertStringContainsString('<span class="str">Ada Lovelace #hello-world-at-cafe Ada Love... 0001 se******xample.com</span>', $html);
        $this->assertStringContainsString('<span class="preg">2:AL:ada ada-lovelace a|b|c</span>', $html);
    }

    #[DataProvider('frameworks')]
    public function test_the_bundle_and_runtime_are_served(string $framework): void
    {
        $this->boot($framework);

        $bundle = $this->get('/_nitro/app.js');
        $this->assertSame(200, $bundle->getStatusCode());
        $this->assertStringStartsWith('application/javascript', $bundle->headers->get('Content-Type'));

        $js = (string) $bundle->getContent();
        $this->assertStringNotContainsString('console.error', $js, $js);

        foreach (['counter', 'todo-list', 'wallet'] as $name) {
            $this->assertStringContainsString('Nitro.define("'.$name.'"', $js);
        }

        /** Pages and #[LazyLoad] components come in their chunks. */
        foreach (['pages' => 'pages::student-page', 'todos' => 'todo-item', 'board' => 'pages::board'] as $group => $name) {
            $chunk = $this->get('/_nitro/app/'.$group.'.js');
            $this->assertSame(200, $chunk->getStatusCode());
            $this->assertStringContainsString('Nitro.define("'.$name.'"', (string) $chunk->getContent());
            $js .= $chunk->getContent();
        }

        /** Only what the browser reaches is compiled: #[Server] methods never are. */
        $this->assertStringNotContainsString('"save": function', $js);
        $this->assertStringNotContainsString('"persist": function', $js);
        $this->assertStringNotContainsString('"withdraw": function', $js);
        $this->assertStringNotContainsString('"drain": function', $js);

        $runtime = $this->get('/_nitro/nitro.js');
        $this->assertSame(200, $runtime->getStatusCode());
        $this->assertStringContainsString('Nitro', (string) $runtime->getContent());
    }
}
