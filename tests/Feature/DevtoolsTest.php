<?php

namespace Nitro\Tests\Feature;

use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The dev overlay: in development (APP_DEBUG, or nitro.devtools) @nitroScripts adds its script
 * after the runtime's, Nitro serves it, and each answer says its server and database time
 * (Server-Timing). Off, none of that happens: it is neither added nor served.
 */
class DevtoolsTest extends TestCase
{
    private function devtools(?bool $on): void
    {
        $this->app->make('config')->set('nitro.devtools', $on);
        $this->app->forgetScopedInstances();
    }

    #[DataProvider('frameworks')]
    public function test_in_development_the_page_has_it_and_answers_are_timed(string $framework): void
    {
        $this->boot($framework);
        $this->devtools(null);

        $page = $this->get('/embed');
        $html = (string) $page->getContent();

        $this->assertMatchesRegularExpression('#<script src="/_nitro/nitro-devtools\.js\?v=[0-9a-f]{12}" defer></script>#', $html, 'APP_DEBUG is on in the fixture app');
        $this->assertLessThan(strpos($html, 'nitro-devtools.js'), strpos($html, 'data-endpoint'), 'after the runtime: it is a plugin on it');
        $this->assertMatchesRegularExpression('/^nitro;dur=\d+\.\d, db;dur=\d+\.\d$/', (string) $page->headers->get('Server-Timing'));

        $script = $this->get('/_nitro/nitro-devtools.js');
        $this->assertSame(200, $script->getStatusCode());
        $this->assertStringContainsString('application/javascript', (string) $script->headers->get('Content-Type'));
    }

    #[DataProvider('frameworks')]
    public function test_off_it_is_neither_added_nor_served(string $framework): void
    {
        $this->boot($framework);
        $this->devtools(false);

        $page = $this->get('/embed');

        $this->assertStringNotContainsString('nitro-devtools', (string) $page->getContent());
        $this->assertFalse($page->headers->has('Server-Timing'));
        $this->assertSame(404, $this->get('/_nitro/nitro-devtools.js')->getStatusCode());
    }
}
