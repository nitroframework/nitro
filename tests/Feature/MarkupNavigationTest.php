<?php

namespace Nitro\Tests\Feature;

use Nitro\Build\Bundle;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentReader;
use Nitro\Support\Wire;
use Nitro\Tests\Fixtures\Invalid\ClientWithMount;
use Nitro\Tests\Fixtures\Invalid\WrongNavigation;
use Illuminate\Testing\TestResponse;
use Nitro\Testing\AssertableNitro;
use Nitro\Tests\Fixtures\Pages\Chapter;
use Nitro\Tests\Fixtures\Pages\Terms;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Markup-first navigation (#[Navigate('markup')], nitro.navigation): what the bundle tells the
 * browser to render a page at once, and the visit that brings its data keeping the id the browser
 * gave it (X-Nitro-Id). The browser side runs in js/test/e2e/run.mjs.
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
        $this->assertStringContainsString('Nitro.markupPages([["^/inbox/?$","pages::inbox"],["^/terms/?$","pages::terms"],["^/chapters/(?<number>(?:[0-9]+))(?:/(?<part>[^/]+))?/?$","pages::chapter",{"number":{"prop":"number","type":"int"},"part":{"prop":"part","type":"string"}}]]);', $files['']);
    }

    #[DataProvider('frameworks')]
    public function test_nitro_navigation_makes_every_page_markup_first(string $framework): void
    {
        $this->boot($framework);
        $this->app->make('config')->set('nitro.navigation', 'markup');
        $this->app->forgetScopedInstances();

        $files = $this->app->make(Bundle::class)->compileAll();

        $this->assertStringContainsString('markup: "components.layouts.app"', $files['counter']);
        $this->assertStringContainsString('"pages::counter"', $files['']);
    }

    #[DataProvider('frameworks')]
    public function test_the_visit_keeps_the_id_the_browser_gave_the_page(string $framework): void
    {
        $this->boot($framework);

        $headers = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_NITRO_NAVIGATE' => '1'];
        $state = fn (array $server) => Wire::fromJson($this->json($this->request('GET', '/counter', $server))['snapshot']['state']);

        $this->assertSame('fabcdefghij', $state([...$headers, 'HTTP_X_NITRO_ID' => 'fabcdefghij'])['id']);
        $this->app->forgetScopedInstances();
        $this->assertMatchesRegularExpression('/^f[a-z0-9]{10}$/', $state([...$headers, 'HTTP_X_NITRO_ID' => 'x" onload="'])['id'], 'not an id: a new one');
    }

    public function test_navigate_is_data_markup_or_client(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage("#[Navigate] on ".WrongNavigation::class." is 'data', 'markup' or 'client', not 'fast'");

        ComponentReader::read(WrongNavigation::class, 'pages::wrong-navigation', 'nitro.pages.wrong-navigation', true);
    }

    #[DataProvider('frameworks')]
    public function test_a_client_page_carries_its_title_and_head_worked_out_at_build_time(string $framework): void
    {
        $this->boot($framework);

        $files = $this->app->make(Bundle::class)->compileAll();

        $this->assertStringContainsString('markup: "components.layouts.headed"', $files['terms']);
        $this->assertStringContainsString('client: {"title":"Terms · Fixture","head":"<meta name=\"description\" content=\"The terms of the Nitro test app\" data-nitro-head>', $files['terms']);
        $this->assertStringContainsString('"pages::terms"]', $files[''], 'listed by path: rendered at once');
    }

    public function test_a_client_page_cannot_have_mount(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage("a client page renders without asking the server, so it cannot have mount()");

        ComponentReader::read(ClientWithMount::class, 'pages::client-with-mount', 'nitro.pages.client-with-mount', true);
    }

    #[DataProvider('frameworks')]
    public function test_a_client_page_takes_its_route_parameters_in_the_browser(string $framework): void
    {
        $this->boot($framework);
        $main = $this->app->make(Bundle::class)->compileAll(build: true)[''];

        $this->assertStringContainsString(
            '["^/chapters/(?<number>(?:[0-9]+))(?:/(?<part>[^/]+))?/?$","pages::chapter",{"number":{"prop":"number","type":"int"},"part":{"prop":"part","type":"string"}}]',
            $main,
            'the route as a pattern held to its where(), and what each parameter fills',
        );
        $this->assertStringContainsString('["^/terms/?$","pages::terms"]', $main, 'a client page without parameters, as before');
    }

    #[DataProvider('frameworks')]
    public function test_a_full_load_fills_the_properties_and_a_value_not_of_their_type_is_no_page(string $framework): void
    {
        $this->boot($framework);

        TestResponse::fromBaseResponse($this->get('/chapters/7/intro'))->assertOk()->assertNitro(fn (AssertableNitro $page) => $page
            ->page('pages::chapter')
            ->where('number', 7)
            ->where('part', 'intro')
            ->where('edition', 'first'));
        /** The same route again, in the same process: a new page, nothing left from the last request. */
        TestResponse::fromBaseResponse($this->get('/chapters/7'))->assertOk()->assertNitro(fn (AssertableNitro $page) => $page->where('part', null));

        TestResponse::fromBaseResponse($this->get('/chapters/seven'))->assertNotFound();
        $this->app->make('router')->nitro('/loose-chapters/{number}', Chapter::class);
        $this->app->make('router')->getRoutes()->refreshNameLookups();
        TestResponse::fromBaseResponse($this->get('/loose-chapters/seven'))->assertNotFound();
        TestResponse::fromBaseResponse($this->get('/loose-chapters/05'))->assertNotFound();
    }

    public static function clientRoutes(): array
    {
        return [
            'a #[Locked] property' => ['/edition/{edition}', null, 'the parameter {edition} for a #[Locked] property'],
            'no property for it' => ['/volume/{volume}', null, 'the parameter {volume} and no public property $volume to take it'],
            'not a string, int or float' => ['/read/{read}', null, 'the parameter {read} for $read, which is not a string, int or float'],
            'a model by its field' => ['/chapter-by/{number:slug}', null, 'a model bound by its slug ({number:slug})'],
            'a where() the browser cannot read' => ['/possessive/{number}', '\d++', 'a where() constraint on {number} the browser cannot check'],
        ];
    }

    #[DataProvider('clientRoutes')]
    public function test_a_client_routes_parameters_are_checked_at_build_time(string $uri, ?string $where, string $message): void
    {
        $this->boot('laravel');
        $route = $this->app->make('router')->nitro($uri, Chapter::class);

        if ($where !== null) {
            $route->where('number', $where);
        }

        $this->app->make('router')->getRoutes()->refreshNameLookups();

        $this->expectException(CompileException::class);
        $this->expectExceptionMessage($message);

        $this->app->make(Bundle::class)->compileAll(build: true);
    }

    public function test_a_parameter_bound_with_route_bind_fails_the_build(): void
    {
        $this->boot('laravel');
        $this->app->make('router')->bind('number', fn (string $value) => (int) $value);
        $this->app->make('router')->nitro('/bound/{number}', Chapter::class);
        $this->app->make('router')->getRoutes()->refreshNameLookups();

        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('a parameter bound to a model (Route::model() or Route::bind() for {number})');

        $this->app->make(Bundle::class)->compileAll(build: true);
    }

    #[DataProvider('frameworks')]
    public function test_a_client_page_behind_middleware_fails_the_build(string $framework): void
    {
        $this->boot($framework);
        $this->app->make('router')->nitro('/private-terms', Terms::class)->middleware('auth');
        $this->app->make('router')->getRoutes()->refreshNameLookups();

        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('its route /private-terms has middleware beyond the web group (auth)');

        $this->app->make(Bundle::class)->compileAll(build: true);
    }
}
