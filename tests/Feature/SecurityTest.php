<?php

namespace Nitro\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Nitro\Component;
use Nitro\Support\Wire;
use Nitro\Tests\Fixtures\Models\Student;
use Nitro\Tests\Fixtures\Models\User;
use Nitro\Tests\Support\Node;
use Nitro\Tests\TestCase;
use Nitro\Upload;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Attacks from the security review, each refused: a partial reload from another page laid over a
 * snapshot, authorization lost since the page was rendered (an application's own middleware too),
 * a page whose route is gone, state signed with the application key alone, an upload reference
 * from another session, and a redirect to a javascript: URL (on the server and in the browser).
 */
class SecurityTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_a_partial_reload_of_another_page_is_refused(string $framework): void
    {
        $this->boot($framework);
        Student::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $snapshot = $this->snapshots((string) $this->get('/students/1')->getContent())[0]['envelope'];
        $id = Wire::fromJson($snapshot['state'])['id'];

        /** A partial reload of /students/2, asked for with the id of /students/1's component. */
        $this->app->forgetScopedInstances();
        $partial = $this->json($this->request('GET', '/students/2', [
            'HTTP_ACCEPT' => 'application/json', 'HTTP_X_NITRO_NAVIGATE' => '1',
            'HTTP_X_NITRO_PARTIAL' => $id, 'HTTP_X_NITRO_ONLY' => 'student',
        ]))['partial'];

        $this->app->forgetScopedInstances();
        $response = $this->update(['snapshot' => $snapshot, 'partials' => [$partial], 'updates' => [], 'call' => ['method' => 'rename', 'params' => ['pwned']]]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Bob', Student::find(2)->name);
    }

    /** Whether the fuzz gate lets the user in (test_authorization_lost_since_the_page_was_rendered_is_refused). */
    public static bool $allowed = true;

    /** The wallet's page (a #[Server] withdraw()) on a route of the test's own; its snapshot. */
    private function wallet(string $uri, array $middleware = []): array
    {
        Route::middleware(['web', ...$middleware])->get($uri, fn () => view('secure'));
        $this->app['router']->getRoutes()->refreshNameLookups();
        $response = $this->get($uri);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->snapshots((string) $response->getContent())[0]['envelope'];
    }

    private function withdraw(array $snapshot): int
    {
        $this->app->forgetScopedInstances();

        return $this->update(['snapshot' => $snapshot, 'updates' => [], 'call' => ['method' => 'withdraw', 'params' => [1]]])->getStatusCode();
    }

    #[DataProvider('frameworks')]
    public function test_authorization_lost_since_the_page_was_rendered_is_refused(string $framework): void
    {
        $this->boot($framework);
        /** An application's own middleware, extending Laravel's: checked again as the one it extends. */
        if (! class_exists(GatedAuthorize::class, false)) {
            eval('namespace Nitro\Tests\Feature; class GatedAuthorize extends \Illuminate\Auth\Middleware\Authorize {}');
        }
        Gate::define('fuzz-wallet', fn () => self::$allowed);
        self::$allowed = true;

        $user = User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x']);
        $this->app->instance('request', Request::create('/'));
        $this->app->make('auth')->guard('web')->setUser($user);
        $snapshot = $this->wallet('/gated-wallet', [GatedAuthorize::class.':fuzz-wallet']);

        $this->assertSame(200, $this->withdraw($snapshot));

        /** The same user, the same session: the permission is gone. */
        self::$allowed = false;
        $this->assertSame(403, $this->withdraw($snapshot));
    }

    #[DataProvider('frameworks')]
    public function test_a_page_whose_route_is_gone_is_refused_not_matched_by_the_fallback(string $framework): void
    {
        $this->boot($framework);
        Route::middleware('web')->group(fn () => Route::fallback(fn () => 'any URL'));
        $snapshot = $this->wallet('/temporary-wallet');
        $this->assertSame(200, $this->withdraw($snapshot));

        /** The route removed (a deploy without it): only the fallback answers its URL now. */
        $routes = new RouteCollection;

        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->uri() !== 'temporary-wallet') {
                $routes->add($route);
            }
        }

        $this->app['router']->setRoutes($routes);
        $this->assertSame(403, $this->withdraw($snapshot));
    }

    #[DataProvider('frameworks')]
    public function test_state_signed_with_the_application_key_alone_is_refused_in_a_session(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->wallet('/plain-wallet');
        $this->assertSame(200, $this->withdraw($snapshot));

        /** Good in every session, for ever, if it were taken: the session's key only. */
        $forged = ['state' => $snapshot['state'], 'checksum' => hash_hmac('sha256', 'state|'.$snapshot['state'], app(\Nitro\Support\Signer::class)->key())];
        $this->assertSame(419, $this->withdraw($forged));
    }

    #[DataProvider('frameworks')]
    public function test_an_upload_reference_is_good_in_its_own_session_only(string $framework): void
    {
        $this->boot($framework);
        $this->app->instance('request', Request::create('/'));
        $this->app->make('session')->driver()->start();
        $this->app['request']->setLaravelSession($this->app->make('session')->driver());
        $wire = Upload::receive(UploadedFile::fake()->image('a.png', 2, 2))->toWire();
        $this->assertInstanceOf(Upload::class, Upload::fromWire($wire));

        /** Another visitor's session. */
        $this->app->make('session')->driver()->flush();
        $this->expectException(InvalidArgumentException::class);
        Upload::fromWire($wire);
    }

    public function test_a_redirect_goes_to_http_urls_and_paths_only(): void
    {
        $this->boot('laravel');
        $component = new class extends Component
        {
            public function go(string $url): void
            {
                $this->redirect($url);
            }
        };

        $component->go('/students');
        $component->go('https://example.com/a');

        foreach (['javascript:alert(1)', ' JavaScript:alert(1)', 'data:text/html,x', 'vbscript:x'] as $url) {
            try {
                $component->go($url);
                $this->fail("redirect() took {$url}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        /** In the browser, whatever the server sends: browsers drop tabs and newlines in a URL ("java\tscript:"). */
        $this->assertSame([], Node::run('js/test/support/run-weburl.mjs', [
            'allowed' => ['/students', 'https://example.com/a', '?page=2', '#top', 'http://localhost/x'],
            'refused' => ['javascript:alert(1)', "java\tscript:alert(1)", " javascript:alert(1)", 'JAVASCRIPT:x', 'data:text/html,x', 'vbscript:x', 'file:///etc/passwd'],
        ]));
    }
}
