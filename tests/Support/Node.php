<?php

namespace Nitro\Tests\Support;

use RuntimeException;

/**
 * Runs a Node script with JSON on stdin and returns its JSON output. stderr goes to a file:
 * as a second pipe it could fill up while stdout is read, and both processes would wait forever.
 */
final class Node
{
    public static function run(string $script, mixed $input, int $timeout = 120): mixed
    {
        $stderr = tempnam(sys_get_temp_dir(), 'nitro-node');

        $process = proc_open(
            ['node', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']],
            $pipes,
            dirname(__DIR__, 2)
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start node.');
        }

        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        $stdout = '';
        $deadline = time() + $timeout;

        while (! feof($pipes[1])) {
            $chunk = fread($pipes[1], 65536);

            if ($chunk === '' || $chunk === false) {
                if (time() > $deadline) {
                    proc_terminate($process);

                    throw new RuntimeException("node {$script} timed out:\n".file_get_contents($stderr));
                }

                usleep(10000);

                continue;
            }

            $stdout .= $chunk;
        }

        fclose($pipes[1]);
        $exit = proc_close($process);
        $errors = (string) file_get_contents($stderr);
        @unlink($stderr);

        if ($exit !== 0) {
            throw new RuntimeException("node {$script} failed ({$exit}):\n{$errors}\n{$stdout}");
        }

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }
}
