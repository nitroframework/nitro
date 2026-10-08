<?php

/**
 * composer fuzz:all -- --seed=1 --scale=1
 *
 * Every fuzzer, one after the other, from one seed (each failure names the seed that makes it
 * again). --scale multiplies the number of cases: 1 takes a few minutes, 10 a long while.
 *
 *   differential  random PHP methods, run in PHP and compiled in the browser's runtime
 *   templates     random views, rendered by Blade and by the browser
 *   roundtrip     random values through #[Server] calls
 *   endpoint      mutated requests to the server's endpoints
 *   compiler      mutated component classes and views, compiled
 *   precompiler   random views with <fusion:...> tags, through Blade
 *   wire          random values and bent input through the wire format
 */
$options = getopt('', ['seed:', 'scale:', 'only:']);
$seed = (int) ($options['seed'] ?? random_int(1, 1_000_000));
$scale = max(1, (int) ($options['scale'] ?? 1));
$only = isset($options['only']) ? explode(',', $options['only']) : null;
$root = dirname(__DIR__, 2);
$phpunit = [PHP_BINARY, $root.'/vendor/phpunit/phpunit/phpunit', '--colors=always'];

$runs = [
    'differential' => [[PHP_BINARY, $root.'/tests/Fuzz/fuzz.php', '--seed='.$seed, '--count='.(300 * $scale)], []],
    'templates' => [[...$phpunit, $root.'/tests/Fuzz/TemplateFuzzTest.php'], ['FUZZ_TEMPLATES' => 200 * $scale]],
    'roundtrip' => [[...$phpunit, $root.'/tests/Fuzz/RoundTripFuzzTest.php'], ['FUZZ_ROUNDTRIP' => 200 * $scale]],
    'endpoint' => [[...$phpunit, $root.'/tests/Fuzz/EndpointFuzzTest.php'], ['FUZZ_ENDPOINT' => 2000 * $scale]],
    'compiler' => [[...$phpunit, $root.'/tests/Fuzz/CompilerFuzzTest.php'], ['FUZZ_COMPILER' => 500 * $scale]],
    'precompiler' => [[...$phpunit, $root.'/tests/Fuzz/PrecompilerFuzzTest.php'], ['FUZZ_PRECOMPILER' => 3000 * $scale]],
    'wire' => [[...$phpunit, $root.'/tests/Fuzz/WireFuzzTest.php'], ['FUZZ_WIRE' => 10000 * $scale]],
];

$failed = [];

foreach ($runs as $name => [$command, $env]) {
    if ($only !== null && ! in_array($name, $only, true)) {
        continue;
    }

    fwrite(STDOUT, "\n== {$name} (seed {$seed})\n");
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root, [...getenv(), ...$env, 'FUZZ_SEED' => (string) $seed]);

    if (proc_close($process) !== 0) {
        $failed[] = $name;
    }
}

fwrite(STDOUT, "\n".($failed === [] ? "All fuzzers passed (seed {$seed}, scale {$scale}).\n" : 'Failed: '.implode(', ', $failed)." (seed {$seed}, scale {$scale}).\n"));

exit($failed === [] ? 0 : 1);
