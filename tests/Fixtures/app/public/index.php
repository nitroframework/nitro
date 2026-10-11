<?php

/**
 * Front controller for the fixture app under `php -S` (browser tests).
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path !== '/' && is_file(__DIR__.$path)) {
    return false;
}

require dirname(__DIR__, 4).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/livewire.php';

$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request = Request::capture());
$response->send();
$kernel->terminate($request, $response);
