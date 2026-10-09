<?php

return [
    'endpoint' => '/_nitro',
    'middleware' => ['web'],
    'components' => [
        'namespace' => 'Nitro\\Tests\\Fixtures\\Components',
        'path' => dirname(__DIR__, 2).'/Components',
        'views' => 'nitro.components',
    ],
    'pages' => [
        'namespace' => 'Nitro\\Tests\\Fixtures\\Pages',
        'path' => dirname(__DIR__, 2).'/Pages',
        'views' => 'nitro.pages',
    ],
    'stores' => [
        'namespace' => 'Nitro\\Tests\\Fixtures\\Stores',
        'path' => dirname(__DIR__, 2).'/Stores',
    ],
    'layout' => 'components.layouts.app',
    /** For the pages in components.layouts.headed (@nitroHead): Article, Notice. */
    'head' => [
        'title' => '%s · Fixture',
        'description' => 'The Nitro test app',
        'image' => null,
    ],
    'persistent_middleware' => [
        Illuminate\Auth\Middleware\Authenticate::class,
        Illuminate\Auth\Middleware\Authorize::class,
    ],
    'max_payload' => 64 * 1024,
    'uploads' => [
        'directory' => dirname(__DIR__).'/storage/app/nitro-uploads',
        'rules' => ['file', 'max:1024'],
        'max_files' => 3,
        'throttle' => '1000,1',
    ],
    'public_config' => ['app.name'],
    'compiled' => dirname(__DIR__).'/storage/framework/nitro',
    /** Real names, unless a test turns obfuscation on (NITRO_OBFUSCATE: the browser tests' obfuscated run). */
    'build' => [
        'obfuscate' => (bool) getenv('NITRO_OBFUSCATE'),
    ],
    /** Clone mode (the default), unless a test turns create mode on (NITRO_RENDERING: the browser tests' create-mode run). */
    'rendering' => getenv('NITRO_RENDERING') ?: 'clone',
    /** The application's own nitro: attributes (the showcase uses them; the layout and the browser tests register their directives). */
    'attributes' => ['tooltip', 'tell'],
    /** Views in JavaScript: the framework ones, and "plain" (vanilla JavaScript, which the layout registers) for the browser tests. */
    'views' => [
        'vue' => ['adapter' => Nitro\View\Adapters\VueAdapter::class, 'path' => resource_path('views/vue'), 'extensions' => ['vue']],
        'react' => ['adapter' => Nitro\View\Adapters\ReactAdapter::class, 'path' => resource_path('views/react'), 'extensions' => ['jsx', 'tsx']],
        'solid' => ['adapter' => Nitro\View\Adapters\SolidAdapter::class, 'path' => resource_path('views/solid'), 'extensions' => ['jsx', 'tsx']],
        'svelte' => ['adapter' => Nitro\View\Adapters\SvelteAdapter::class, 'path' => resource_path('views/svelte'), 'extensions' => ['svelte']],
        'plain' => ['path' => resource_path('views/plain'), 'extensions' => ['js']],
    ],
    /** Server rendering for the JavaScript views: the browser tests' "with server rendering" run starts the service and turns it on. */
    'ssr' => [
        'enabled' => getenv('NITRO_SSR') === '1',
        'url' => getenv('NITRO_SSR_URL') ?: 'http://127.0.0.1:13714',
        'timeout' => 5,
    ],
    /** Readable on first use, so tests can read it (BuildTest turns it on). */
    'minify_on_change' => false,
    /** esbuild from the package's own node_modules (the fixture app has none). */
    'esbuild' => [
        'node' => 'node',
        'root' => dirname(__DIR__, 4),
    ],
];
