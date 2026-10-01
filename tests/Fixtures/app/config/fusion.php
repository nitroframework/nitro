<?php

return [
    'endpoint' => '/_fusion',
    'middleware' => ['web'],
    'components' => [
        'namespace' => 'Nitro\\Fusion\\Tests\\Fixtures\\Components',
        'path' => dirname(__DIR__, 2).'/Components',
        'views' => 'fusion.components',
    ],
    'pages' => [
        'namespace' => 'Nitro\\Fusion\\Tests\\Fixtures\\Pages',
        'path' => dirname(__DIR__, 2).'/Pages',
        'views' => 'fusion.pages',
    ],
    'layout' => 'components.layouts.app',
    'persistent_middleware' => [
        Illuminate\Auth\Middleware\Authenticate::class,
        Illuminate\Auth\Middleware\Authorize::class,
    ],
    'max_payload' => 64 * 1024,
    'uploads' => [
        'directory' => dirname(__DIR__).'/storage/app/fusion-uploads',
        'rules' => ['file', 'max:1024'],
        'max_files' => 3,
        'throttle' => '1000,1',
    ],
    'public_config' => ['app.name'],
    'compiled' => dirname(__DIR__).'/storage/framework/fusion',
    /** Readable on first use, so tests can read it (BuildTest turns it on). */
    'minify_on_change' => false,
    /** esbuild from the package's own node_modules (the fixture app has none). */
    'esbuild' => [
        'node' => 'node',
        'root' => dirname(__DIR__, 4),
    ],
];
