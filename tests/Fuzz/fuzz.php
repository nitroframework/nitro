<?php

/**
 * composer fuzz -- --count=500 --seed=1 --show=10
 *
 * Random methods run in PHP and, compiled, in the browser's runtime (Node); the differences are
 * reported shrunk, each with the seed that makes it again (--seed=N --count=1).
 */

use Nitro\Tests\Fuzz\Fuzzer;

require __DIR__.'/../../vendor/autoload.php';

ini_set('display_errors', '0');
ini_set('memory_limit', '1G');

$options = getopt('', ['seed:', 'count:', 'show:']);
$seed = isset($options['seed']) ? (int) $options['seed'] : random_int(1, 1_000_000);

/** A fatal error in PHP (not an exception) ends the run: say which seed it was. */
register_shutdown_function(function () {
    $error = error_get_last();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_COMPILE_ERROR, E_CORE_ERROR, E_PARSE], true)) {
        fwrite(STDERR, "\n  PHP fatal error: {$error['message']}\n");
    }
});

exit((new Fuzzer)->run($seed, (int) ($options['count'] ?? 300), (int) ($options['show'] ?? 10)));
