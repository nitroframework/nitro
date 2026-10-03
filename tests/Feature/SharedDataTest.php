<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * The data Fusion::share() gives every component, as Inertia's shared props: on the page, in
 * fusion:navigate visits and in #[Server] call answers.
 */
class SharedDataTest extends TestCase
{
    private function visit(string $uri): Response
    {
        return $this->request('GET', $uri, ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_FUSION_NAVIGATE' => '1']);
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
