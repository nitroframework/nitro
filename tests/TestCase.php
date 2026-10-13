<?php

namespace Nitro\Tests;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Nitro\Nitro;
use Nitro\Support\Wire;
use Nitro\Tests\Fixtures\Components\Store;
use Nitro\Tests\Fixtures\Models\Student;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The feature tests boot the fixture app on Laravel, as the applications that use Nitro.
 */
abstract class TestCase extends BaseTestCase
{
    public const APP = __DIR__.DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'app';

    /** The fixture app's storage directories (git-ignored: a fresh clone has none). */
    private const STORAGE = ['app/private', 'framework/cache', 'framework/nitro', 'framework/sessions', 'framework/views', 'logs'];

    protected ?Application $app = null;

    private static bool $viewsCleared = false;

    /** @var array<string, string> cookies carried between requests, as a browser would */
    protected array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();

        Store::$saved = [];
        Store::$log = [];
        $this->cookies = [];

        /** Once a run: views compiled by an earlier version of Nitro call what may have moved since. */
        if (! self::$viewsCleared) {
            array_map('unlink', glob(self::APP.'/storage/framework/views*/*.php') ?: []);
            self::$viewsCleared = true;
        }

        /** No caches, and no scripts nitro:build published (a test that builds and is stopped part way leaves them). */
        foreach ([...glob(self::APP.'/bootstrap/cache/*.php') ?: [], ...glob(self::APP.'/public/nitro/*.js') ?: []] as $file) {
            @unlink($file);
        }

        foreach (self::STORAGE as $directory) {
            @mkdir(self::APP.'/storage/'.$directory, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->app = null;
        HandleExceptions::flushState($this);
        /** withProviders() keeps its list for the whole process: cleared after each test, as Laravel's TestCase does. */
        RegisterProviders::flushState();
        /** Blade components keep views made of strings (Blade::render()) in static caches, under a namespace the app registered: as Laravel's TestCase does. */
        \Illuminate\View\Component::flushCache();
        \Illuminate\View\Component::forgetComponentsResolver();
        \Illuminate\View\Component::forgetFactory();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    /**
     * The framework's provider and package manifests, out of the bootstrap/cache that setUp()
     * clears: written once and reused, not rewritten on each boot. Rewritten on each boot, Laravel's
     * rename of a new one
     * into place failed now and then on Windows while an indexer or a virus scanner had it open
     * ("Access is denied"). Relative to the app: Laravel reads a path starting with a drive letter
     * as relative.
     */
    private static function useManifestsOf(string $framework): void
    {
        $directory = 'storage/framework/manifests/'.$framework;
        @mkdir(self::APP.'/'.$directory, 0777, true);

        foreach (['APP_SERVICES_CACHE' => 'services.php', 'APP_PACKAGES_CACHE' => 'packages.php'] as $variable => $file) {
            $path = $directory.'/'.$file;
            putenv("{$variable}={$path}");
            $_ENV[$variable] = $_SERVER[$variable] = $path;
        }
    }

    /** The framework each test boots the fixture app on: Laravel, as the applications that use Nitro. */
    public static function frameworks(): array
    {
        return ['laravel' => ['laravel']];
    }

    protected function boot(string $framework): Application
    {
        self::useManifestsOf($framework);
        $app = $this->app = require self::APP.'/bootstrap/'.$framework.'.php';
        /** As an app's tests: booted by the console kernel, the HTTP kernel made at the first request. */
        $app->make(ConsoleKernel::class)->bootstrap();

        $schema = $app->make('db')->connection()->getSchemaBuilder();
        $schema->create('students', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('secret')->nullable();
            $table->foreignId('mentor_id')->nullable();
        });
        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->rememberToken();
        });

        Student::create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'secret' => 'hidden']);

        return $app;
    }

    protected function console(): ConsoleKernel
    {
        return $this->app->make(ConsoleKernel::class);
    }

    protected function nitro(): Nitro
    {
        return $this->app->make(Nitro::class);
    }

    protected function request(string $method, string $uri, array $server = [], ?string $content = null): Response
    {
        $kernel = $this->app->make(HttpKernel::class);
        $request = Request::create($uri, $method, [], $this->cookies, [], $server, $content);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    protected function get(string $uri): Response
    {
        return $this->request('GET', $uri);
    }

    /** A #[Server] call as the runtime sends it. */
    protected function update(array $payload): Response
    {
        return $this->request('POST', '/_nitro/update', [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * The signed snapshots on a page, in order.
     *
     * @return list<array{envelope: array, state: array}>
     */
    protected function snapshots(string $html): array
    {
        return array_values(array_map(
            fn (array $envelope) => ['envelope' => $envelope, 'state' => Wire::fromJson($envelope['state'])],
            $this->app->make(\Nitro\Rendering\PageData::class)->statesIn($html),
        ));
    }

    protected function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }
}
