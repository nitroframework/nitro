<?php

/**
 * Nitro in a new Laravel app, installed as Composer ships it: what the repository's own tests cannot
 * see (files left out of installs, an app's boot order, its own esbuild and Vite, cached config and
 * routes). Run from the repository:
 *
 *     php tests/App/run.php [directory]
 *
 * 1. A new app (composer create-project laravel/laravel) in the directory, or in the system's
 *    temporary one.
 * 2. Nitro from git archive of the working tree (export-ignore applies, as on Packagist), through
 *    a path repository that copies it; esbuild, Vue and its Vite plugin from npm, as the docs say.
 * 3. tests/App/files on top: a Blade page and a Vue page, the layout, the app's tests.
 * 4. npm run build, then php artisan test.
 * 5. php artisan optimize, then the pages served with cached config, routes and views.
 */

$nitro = dirname(__DIR__, 2);
$app = $argv[1] ?? sys_get_temp_dir().DIRECTORY_SEPARATOR.'nitro-app';

/** A command in a directory; the run stops when it fails. */
function run(string $command, string $directory): void
{
    echo "\n> {$command}\n";
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $directory);

    if (! is_resource($process) || proc_close($process) !== 0) {
        fwrite(STDERR, "\nFailed: {$command}\n");
        exit(1);
    }
}

function output(string $command, string $directory): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
    $out = stream_get_contents($pipes[1]);
    proc_close($process);

    return trim((string) $out);
}

function remove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @chmod($path, 0777);
        unlink($path);

        return;
    }

    if (is_dir($path)) {
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                remove($path.DIRECTORY_SEPARATOR.$entry);
            }
        }
        rmdir($path);
    }
}

function copyTree(string $from, string $to): void
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $target = $to.substr($file->getPathname(), strlen($from));
        @mkdir(dirname($target), 0777, true);
        copy($file->getPathname(), $target);
    }
}

/** 1. The app. */
remove($app);
run('composer create-project laravel/laravel '.escapeshellarg($app).' --prefer-dist --no-interaction --quiet', dirname($app));

/** 2. Nitro as Composer ships it: the working tree's tracked files, without what export-ignore leaves out. */
$package = $app.DIRECTORY_SEPARATOR.'nitro-package';
$zip = $app.DIRECTORY_SEPARATOR.'nitro.zip';
$tree = output('git stash create', $nitro) ?: 'HEAD';
run('git archive --format=zip -o '.escapeshellarg($zip).' '.$tree, $nitro);
$archive = new ZipArchive;
$archive->open($zip);
$archive->extractTo($package);
$archive->close();
unlink($zip);

/** The version of the last release: the working tree is it and what has changed since. */
$version = ltrim(output('git describe --tags --abbrev=0', $nitro), 'v');
$composer = json_decode((string) file_get_contents($app.'/composer.json'), true);
$composer['repositories'] = [['type' => 'path', 'url' => 'nitro-package', 'options' => ['symlink' => false, 'versions' => ['nitro/nitro' => $version]]]];
file_put_contents($app.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
run('composer require nitro/nitro:'.$version.' --no-interaction', $app);
run('npm install --no-audit --no-fund', $app);
run('npm install --save-dev --no-audit --no-fund esbuild vue @vitejs/plugin-vue', $app);

/** 3. The app's own files. */
copyTree(__DIR__.DIRECTORY_SEPARATOR.'files', $app);

/** 4. Built, then tested as the app tests itself. */
run('npm run build', $app);
run('php artisan test', $app);

/** 5. As it runs in production: cached config, routes and views, served. */
run('php artisan optimize', $app);
$port = 8790;
/** As php artisan serve runs it: Laravel's router script, from public/. */
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $app.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'], [0 => ['pipe', 'r'], 1 => ['file', $app.'/storage/logs/server.log', 'a'], 2 => ['file', $app.'/storage/logs/server.log', 'a']], $pipes, $app.'/public');
/** Stopped however this run ends: a server left behind holds the port and the run's output open. */
register_shutdown_function(function () use ($server) {
    if (is_resource($server) && proc_get_status($server)['running']) {
        proc_terminate($server);
        proc_close($server);
    }
});
$failures = [];

/** @return array{int, string} the status and the body of a page of the app being served */
function fetch(string $url): array
{
    $request = curl_init($url);
    curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $body = curl_exec($request);

    return [(int) curl_getinfo($request, CURLINFO_RESPONSE_CODE), is_string($body) ? $body : ''];
}

/** @return array<string, mixed>|null the page's state block, decoded: each component's signed state by its id */
function stateBlock(string $html): ?array
{
    return preg_match('#<script type="application/json" data-nitro-state>(.*?)</script>#s', $html, $block) === 1 ? json_decode($block[1], true) : null;
}

/** What a page shows: its HTML, and the HTML of its #[Html] methods from the state block. */
function shown(string $html): string
{
    $made = array_merge(...array_values(array_map(fn (array $envelope) => array_column($envelope['html'] ?? [], 1), stateBlock($html) ?? [])));

    return $html.implode('', $made);
}

for ($tries = 0; $tries < 50 && fetch("http://127.0.0.1:{$port}/up")[0] !== 200; $tries++) {
    usleep(100_000);
}

foreach (['/counter' => '<span class="count">0</span>', '/article?topic=laravel' => '<h1>Laravel</h1>'] as $path => $expected) {
    [$status, $html] = fetch("http://127.0.0.1:{$port}{$path}");

    if ($status !== 200) {
        $failures[] = "{$path}: HTTP {$status}";
    } elseif (stateBlock($html) === null || str_contains($html, '<!--nitro:state-->')) {
        $failures[] = "{$path}: no state block";
    } elseif (! str_contains(shown($html), $expected)) {
        $failures[] = "{$path}: without {$expected}";
    } else {
        echo "\n{$path}: served with cached config, routes and views";
    }
}

proc_terminate($server);
proc_close($server);
run('php artisan optimize:clear', $app);

if ($failures !== []) {
    fwrite(STDERR, "\nFailed, served after php artisan optimize:\n  ".implode("\n  ", $failures)."\n");
    exit(1);
}

echo "\n\nNitro works in a new Laravel app ({$app}).\n";
