<?php

namespace Nitro\Fusion\Tests\Feature;

use InvalidArgumentException;
use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Tests\Fixtures\Components\Counter;
use Nitro\Fusion\Tests\Fixtures\Pages\Counter as CounterPage;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fusion::render() from a Laravel controller, as Inertia::render(), and the data Fusion::share()
 * gives every component: on the page, in fusion:navigate visits and in #[Server] call answers.
 */
class RenderTest extends TestCase
{
    private function visit(string $uri): Response
    {
        return $this->request('GET', $uri, ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_FUSION_NAVIGATE' => '1']);
    }

    #[DataProvider('frameworks')]
    public function test_a_controller_renders_a_page_with_props_as_properties(string $framework): void
    {
        $this->boot($framework);

        $response = $this->get('/rendered/counter');
        $html = (string) $response->getContent();
        $state = $this->snapshots($html)[0]['state'];

        $this->assertSame(200, $response->getStatusCode(), $html);
        $this->assertStringContainsString('<span class="count">7</span>', $html);
        $this->assertStringContainsString('<title>Fusion</title>', $html, 'in the default layout');
        $this->assertSame(['count' => 7, 'step' => 2], array_intersect_key($state['data'], ['count' => 1, 'step' => 1]));
        $this->assertSame(['method' => 'GET', 'uri' => '/rendered/counter'], $state['memo']['route']);
        $this->assertContains('X-Fusion-Navigate', $response->getVary());
    }

    #[DataProvider('frameworks')]
    public function test_a_controller_renders_a_page_with_props_as_mount_arguments(string $framework): void
    {
        $this->boot($framework);

        $html = (string) $this->get('/rendered/students/1')->getContent();

        $this->assertSame('Ada Lovelace', $this->snapshots($html)[0]['state']['data']['student']['name']);
        $this->assertStringContainsString('<title>Student</title>', $html, '#[Title] applies');
    }

    #[DataProvider('frameworks')]
    public function test_a_rendered_page_answers_a_visit_with_its_page_data(string $framework): void
    {
        $this->boot($framework);

        $page = $this->json($this->visit('/rendered/counter'));
        $state = Wire::fromJson($page['snapshot']['state']);

        $this->assertSame(1, $page['fusion']);
        $this->assertSame('pages::counter', $state['name']);
        $this->assertSame(7, $state['data']['count']);

        /** Signed: #[Server] calls accept it. */
        $this->assertSame(200, $this->update(['snapshot' => $page['snapshot'], 'updates' => [], 'call' => ['method' => 'save', 'params' => []]])->getStatusCode());
    }

    #[DataProvider('frameworks')]
    public function test_props_must_match_the_pages_properties(string $framework): void
    {
        $this->boot($framework);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no public property $missing');

        $this->fusion()->render(CounterPage::class, ['missing' => 1]);
    }

    #[DataProvider('frameworks')]
    public function test_only_pages_can_be_rendered(string $framework): void
    {
        $this->boot($framework);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fusion::render() renders pages: [counter] is a component');

        $this->fusion()->render(Counter::class);
    }

    #[DataProvider('frameworks')]
    public function test_shared_data_reaches_components_on_the_server(string $framework): void
    {
        $this->boot($framework);
        $this->fusion()->share(['team' => fn () => collect(['name' => 'Analytical']), 'flags.beta' => true]);

        $html = (string) $this->get('/greeting')->getContent();

        $this->assertStringContainsString('<p class="hello">Hello Ada</p>', $html);
        $this->assertStringContainsString('<p class="missing">fallback</p>', $html);
        /** The fixture's middleware shares the user and the countries for each request, after what was shared before it. */
        $this->assertSame(['team' => ['name' => 'Analytical'], 'flags' => ['beta' => true], 'user' => ['name' => 'Ada'], 'countries' => ['PK', 'US']], $this->fusion()->shared());
        $this->assertSame('Analytical', $this->fusion()->shared('team.name'));
        $this->assertSame('none', $this->fusion()->shared('team.missing', 'none'));
    }

    #[DataProvider('frameworks')]
    public function test_shared_data_is_sent_with_the_page_visits_and_server_calls(string $framework): void
    {
        $this->boot($framework);

        $html = (string) $this->get('/greeting')->getContent();
        preg_match('#<script type="application/json" data-fusion-globals>(.*?)</script>#', $html, $globals);
        $this->assertSame(['user' => ['name' => 'Ada'], 'countries' => ['PK', 'US']], Wire::decode(json_decode($globals[1], true)['shared']));

        $page = $this->json($this->visit('/greeting'));
        $this->assertSame(['user' => ['name' => 'Ada'], 'countries' => ['PK', 'US']], Wire::decode($page['shared']));

        /** The call changes what is shared: its answer carries the new data. */
        $answer = $this->json($this->update(['snapshot' => $page['snapshot'], 'updates' => [], 'call' => ['method' => 'rename', 'params' => ['Grace']]]));
        $this->assertSame(['user' => ['name' => 'Grace'], 'countries' => ['PK', 'US']], Wire::decode($answer['shared']));
    }

    #[DataProvider('frameworks')]
    public function test_shared_compiles_to_the_browsers_shared_data(string $framework): void
    {
        $this->boot($framework);

        /** Greeting is a page: its chunk. */
        $chunk = (string) $this->get('/_fusion/app/greeting.js')->getContent();

        $this->assertStringContainsString('B.shared("user.name", "guest")', $chunk);
    }
}
