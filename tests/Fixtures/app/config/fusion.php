<?php

return [
    'endpoint' => '/_fusion',
    'middleware' => ['web'],
    'components' => [
        ['namespace' => 'Nitro\\Fusion\\Tests\\Fixtures\\Components', 'path' => dirname(__DIR__, 2).'/Components'],
    ],
    'view_prefix' => 'fusion',
    'layout' => 'components.layouts.app',
    'persistent_middleware' => [
        Illuminate\Auth\Middleware\Authenticate::class,
        Illuminate\Auth\Middleware\Authorize::class,
    ],
    'max_payload' => 64 * 1024,
    'public_config' => ['app.name'],
    'compiled' => dirname(__DIR__).'/storage/framework/fusion',
    /** esbuild from the package's own node_modules (the fixture app has none). */
    'esbuild' => [
        'node' => 'node',
        'root' => dirname(__DIR__, 4),
    ],
];
