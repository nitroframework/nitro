<?php

namespace Nitro\Fusion\Tests\Support;

use RuntimeException;

/**
 * The render service for server rendering (js/test/e2e/.built/ssr.mjs: the fixture's views),
 * run for the browser tests; stopped when the object goes away.
 */
final class RenderService
{
    /** @var resource */
    private $process;

    public readonly string $url;

    public function __construct()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $log = sys_get_temp_dir().'/fusion-ssr.log';
        $this->process = proc_open(
            ['node', 'js/test/e2e/.built/ssr.mjs'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            dirname(__DIR__, 2),
            [...getenv(), 'FUSION_SSR_PORT' => (string) $port],
        );

        $this->url = "http://127.0.0.1:{$port}";

        for ($i = 0; $i < 100; $i++) {
            if (@file_get_contents($this->url.'/health') !== false) {
                return;
            }

            usleep(50000);
        }

        throw new RuntimeException('The render service did not start: see '.$log);
    }

    public function __destruct()
    {
        $status = proc_get_status($this->process);

        if ($status['running']) {
            PHP_OS_FAMILY === 'Windows'
                ? exec("taskkill /F /T /PID {$status['pid']} 2>NUL")
                : proc_terminate($this->process);
        }

        proc_close($this->process);
    }
}
