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
    'stores' => [
        'namespace' => 'Nitro\\Fusion\\Tests\\Fixtures\\Stores',
        'path' => dirname(__DIR__, 2).'/Stores',
    ],
    'layout' => 'components.layouts.app',
    /** For the pages in components.layouts.headed (@fusionHead): Article, Notice. */
    'head' => [
        'title' => '%s · Fixture',
        'description' => 'The Fusion test app',
        'image' => null,
    ],
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
    /** Real names, unless a test turns obfuscation on (FUSION_OBFUSCATE: the browser tests' obfuscated run). */
    'build' => [
        'obfuscate' => (bool) getenv('FUSION_OBFUSCATE'),
    ],
    /** Clone mode (the default), unless a test turns create mode on (FUSION_RENDERING: the browser tests' create-mode run). */
    'rendering' => getenv('FUSION_RENDERING') ?: 'clone',
    /** The application's own fusion: attributes (the showcase uses them; the layout and the browser tests register their directives). */
    'attributes' => ['tooltip', 'tell'],
    /** Views in JavaScript: the framework ones, and "plain" (vanilla JavaScript, which the layout registers) for the browser tests. */
    'views' => [
        'vue' => ['adapter' => Nitro\Fusion\View\Adapters\VueAdapter::class, 'path' => resource_path('views/vue'), 'extensions' => ['vue']],
        'react' => ['adapter' => Nitro\Fusion\View\Adapters\ReactAdapter::class, 'path' => resource_path('views/react'), 'extensions' => ['jsx', 'tsx']],
        'solid' => ['adapter' => Nitro\Fusion\View\Adapters\SolidAdapter::class, 'path' => resource_path('views/solid'), 'extensions' => ['jsx', 'tsx']],
        'svelte' => ['adapter' => Nitro\Fusion\View\Adapters\SvelteAdapter::class, 'path' => resource_path('views/svelte'), 'extensions' => ['svelte']],
        'plain' => ['path' => resource_path('views/plain'), 'extensions' => ['js']],
    ],
    /** Server rendering for the JavaScript views: the browser tests' "with server rendering" run starts the service and turns it on. */
    'ssr' => [
        'enabled' => getenv('FUSION_SSR') === '1',
        'url' => getenv('FUSION_SSR_URL') ?: 'http://127.0.0.1:13714',
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
