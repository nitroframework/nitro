<?php

namespace Nitro\Fusion\Tests\Browser;

use Illuminate\Database\Schema\Blueprint;
use Nitro\Fusion\Tests\Fixtures\Models\Student;
use Nitro\Fusion\Tests\Support\Node;
use Nitro\Fusion\Tests\Support\Server;
use Nitro\Fusion\Tests\TestCase;
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

        $server = new Server($framework, $database);

        try {
            $failures = Node::run('js/test/e2e/run.mjs', ['url' => $server->url]);
        } finally {
            unset($server);
        }

        $this->assertSame([], $failures);
    }

    private function seed(string $database): void
    {
        $pdo = new \PDO('sqlite:'.$database);
        $pdo->exec('create table students (id integer primary key autoincrement, name varchar not null, email varchar not null, secret varchar)');
        $pdo->exec('create table users (id integer primary key autoincrement, name varchar, email varchar, password varchar, remember_token varchar)');
        $pdo->exec("insert into students (name, email, secret) values ('Ada Lovelace', 'ada@example.com', 'hidden')");
    }
}
