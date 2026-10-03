<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Tests\Fixtures\Invalid\WrongNavigation;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Markup-first navigation (#[Navigate('markup')], fusion.navigation): what the bundle tells the
 * browser to render a page at once, and the visit that brings its data keeping the id the browser
 * gave it (X-Fusion-Id). The browser side runs in js/test/e2e/run.mjs.
 */
class MarkupNavigationTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_a_markup_first_page_carries_its_layout_and_is_listed_by_path(string $framework): void
    {
        $this->boot($framework);

        $files = $this->app->make(Bundle::class)->compileAll();

        $this->assertStringContainsString('markup: "components.layouts.app"', $files['inbox']);
        $this->assertStringNotContainsString('markup:', $files['counter'], 'data first: the default');
        $this->assertStringContainsString('Fusion.markupPages([["^/inbox/?$","pages::inbox"]]);', $files['']);
    }

    #[DataProvider('frameworks')]
    public function test_fusion_navigation_makes_every_page_markup_first(string $framework): void
    {
        $this->boot($framework);
        $this->app->make('config')->set('fusion.navigation', 'markup');
        $this->app->forgetScopedInstances();

        $files = $this->app->make(Bundle::class)->compileAll();

        $this->assertStringContainsString('markup: "components.layouts.app"', $files['counter']);
        $this->assertStringContainsString('"pages::counter"', $files['']);
    }

    #[DataProvider('frameworks')]
    public function test_the_visit_keeps_the_id_the_browser_gave_the_page(string $framework): void
    {
        $this->boot($framework);

        $headers = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_FUSION_NAVIGATE' => '1'];
        $state = fn (array $server) => Wire::fromJson($this->json($this->request('GET', '/counter', $server))['snapshot']['state']);

        $this->assertSame('fabcdefghij', $state([...$headers, 'HTTP_X_FUSION_ID' => 'fabcdefghij'])['id']);
        $this->app->forgetScopedInstances();
        $this->assertMatchesRegularExpression('/^f[a-z0-9]{10}$/', $state([...$headers, 'HTTP_X_FUSION_ID' => 'x" onload="'])['id'], 'not an id: a new one');
    }

    public function test_navigate_is_data_or_markup(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage("#[Navigate] on ".WrongNavigation::class." is 'data' or 'markup', not 'fast'");

        ComponentMeta::read(WrongNavigation::class, 'pages::wrong-navigation', 'fusion.pages.wrong-navigation', true);
    }
}
