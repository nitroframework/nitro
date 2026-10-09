<?php

namespace Nitro\Tests\Browser;

use Illuminate\Database\Schema\Blueprint;
use Nitro\Tests\Fixtures\Models\Student;
use Nitro\Tests\Support\Node;
use Nitro\Tests\Support\RenderService;
use Nitro\Tests\Support\Server;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The runtime in a browser (jsdom) against the fixture app served over HTTP: hydration, events,
 * models, children, events between components, #[Server] calls with validation, navigation.
 */
class RuntimeTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_components_come_alive_in_the_browser(string $framework): void
    {
        $database = self::APP.'/storage/browser-'.$framework.'.sqlite';
        @unlink($database);
        touch($database);
        $this->seed($database);

        /** The fixture's Vue views with the adapter, for /_test/vue.js. */
        $this->assertSame([], Node::run('js/test/support/build-adapters.mjs', []), 'the adapters build');

        $server = new Server($framework, $database);

        try {
            $failures = Node::run('js/test/e2e/run.mjs', ['url' => $server->url, 'ssr' => getenv('NITRO_SSR') === '1']);
        } finally {
            unset($server);
        }

        $this->assertSame([], $failures);
    }

    /**
     * The same, against an obfuscated build (nitro.build.obfuscate): methods, #[Server] methods
     * and components under aliases, handlers' text left out of the page, the build's static files
     * served without PHP.
     */
    #[DataProvider('frameworks')]
    public function test_components_come_alive_in_an_obfuscated_build(string $framework): void
    {
        putenv('NITRO_OBFUSCATE=1');

        try {
            $this->boot($framework);
            $this->assertSame(0, $this->console()->call('nitro:build'), $this->console()->output());
            $this->assertNotNull($this->app->make(\Nitro\Build\Bundle::class)->obfuscator(), 'the build is obfuscated');

            $this->test_components_come_alive_in_the_browser($framework);
        } finally {
            putenv('NITRO_OBFUSCATE');
            $this->console()->call('nitro:clear');
        }
    }

    /**
     * The same, in create mode (nitro.rendering = 'create'): what the browser renders itself (a
     * visit, an @if turning true, new rows) is created element by element, rather than the views'
     * static parts copied (clone mode, the default), and everything behaves the same.
     */
    #[DataProvider('frameworks')]
    public function test_components_come_alive_in_create_mode(string $framework): void
    {
        putenv('NITRO_RENDERING=create');

        try {
            $this->boot($framework);
            $this->assertStringNotContainsString('"s": 1', $this->app->make(\Nitro\Build\Bundle::class)->compile(), 'no static parts marked');

            $this->test_components_come_alive_in_the_browser($framework);
        } finally {
            putenv('NITRO_RENDERING');
        }
    }

    /**
     * The same, with server rendering (nitro.ssr) and the render service running: the Vue, React
     * and Solid views are in the first HTML, the browser hydrates them, and everything behaves
     * the same ("server rendering" checks the hydration itself).
     */
    #[DataProvider('frameworks')]
    public function test_components_come_alive_with_server_rendering(string $framework): void
    {
        $this->assertSame([], [], 'the adapters and the render service build');
        $service = new RenderService;
        putenv('NITRO_SSR=1');
        putenv('NITRO_SSR_URL='.$service->url);

        try {
            $this->test_components_come_alive_in_the_browser($framework);
        } finally {
            putenv('NITRO_SSR');
            putenv('NITRO_SSR_URL');
            unset($service);
        }
    }

    private function seed(string $database): void
    {
        $pdo = new \PDO('sqlite:'.$database);
        $pdo->exec('create table students (id integer primary key autoincrement, name varchar not null, email varchar not null, secret varchar, mentor_id integer)');
        $pdo->exec('create table users (id integer primary key autoincrement, name varchar, email varchar, password varchar, remember_token varchar)');
        $pdo->exec("insert into students (name, email, secret) values ('Ada Lovelace', 'ada@example.com', 'hidden')");
    }
}
