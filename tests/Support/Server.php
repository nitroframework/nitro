<?php

namespace Nitro\Fusion\Tests\Support;

use RuntimeException;

/**
 * The fixture app served by `php -S` for browser tests; stopped when the object goes away.
 */
final class Server
{
    /** @var resource */
    private $process;

    public readonly string $url;

    public function __construct(string $framework, string $database)
    {
        $port = self::freePort();
        $root = dirname(__DIR__).'/Fixtures/app/public';
        $env = [...getenv(), 'FUSION_TEST_DB' => $database];

        $this->process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root, $root.'/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir().'/fusion-server.log', 'a'], 2 => ['file', sys_get_temp_dir().'/fusion-server.log', 'a']],
            $pipes,
            $root,
            $env
        );

        $this->url = "http://127.0.0.1:{$port}";

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);

            if ($socket) {
                fclose($socket);

                return;
            }

            usleep(50000);
        }

        throw new RuntimeException('The test server did not start.');
    }

    public function __destruct()
    {
        $status = proc_get_status($this->process);

        if ($status['running']) {
            /** php -S may run as a child of a shell on Windows: stop the whole tree. */
            PHP_OS_FAMILY === 'Windows'
                ? exec("taskkill /F /T /PID {$status['pid']} 2>NUL")
                : proc_terminate($this->process);
        }

        proc_close($this->process);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }
}
