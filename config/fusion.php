<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Endpoint
    |--------------------------------------------------------------------------
    |
    | Where Fusion answers: POST {endpoint}/update runs #[Server] methods, and
    | {endpoint}/fusion.js and {endpoint}/app.js serve the runtime and the
    | compiled components. The update route runs through `middleware`
    | (sessions, cookies and CSRF protection come from the web group).
    |
    */

    'endpoint' => env('FUSION_ENDPOINT', '/_fusion'),

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Components
    |--------------------------------------------------------------------------
    |
    | Where components are discovered. App\Fusion\Students\StudentForm is named
    | "students.student-form" and renders the view
    | "{view_prefix}.students.student-form".
    |
    */

    'components' => [
        ['namespace' => 'App\\Fusion', 'path' => app_path('Fusion')],
    ],

    'view_prefix' => 'fusion',

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | The layout a Route::fusion() page renders in, unless the component names
    | one with #[Layout]. The page is its $slot, #[Title] its $title.
    |
    */

    'layout' => 'components.layouts.app',

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | persistent_middleware: when a component calls the server, the middleware
    | of the page it was rendered on is checked again, for these classes (so a
    | component from an admin page stops working after logout).
    |
    | max_payload: the largest update accepted, in bytes.
    |
    */

    'persistent_middleware' => [
        Illuminate\Auth\Middleware\Authenticate::class,
        Illuminate\Auth\Middleware\Authorize::class,
        Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        Illuminate\Auth\Middleware\RequirePassword::class,
        Illuminate\Routing\Middleware\ValidateSignature::class,
    ],

    'max_payload' => 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | Files chosen in a fusion:model file input are uploaded at once into
    | `directory`, checked against `rules` (each file), and given to the
    | component as Nitro\Fusion\Upload: the component validates and store()s
    | them. Files no component stored are removed after a day. `max_files`
    | limits a multiple input; `throttle` the uploads per minute. PHP's own
    | upload_max_filesize and post_max_size must allow the size in `rules`.
    |
    */

    'uploads' => [
        'directory' => storage_path('app/fusion-uploads'),
        'rules' => ['file', 'max:12288'],
        'max_files' => 20,
        'throttle' => '60,1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Public config
    |--------------------------------------------------------------------------
    |
    | The config keys config() may read in the browser (templates and browser
    | methods). Their values are sent to every visitor with the page: list
    | only what is meant to be public, never credentials or keys. The build
    | fails for a key the browser code reads that is not listed here.
    |
    */

    'public_config' => [
        'app.name',
    ],

    /*
    |--------------------------------------------------------------------------
    | Build
    |--------------------------------------------------------------------------
    |
    | Where the compiled components are written. `php artisan fusion:build`
    | writes the production bundle, minified with esbuild as `vite build`
    | does (npm install --save-dev esbuild); it is a step of its own, not
    | part of `optimize`, and APP_DEBUG has no say in it. Without a build,
    | components are compiled on first use: built as fusion:build does when
    | minify_on_change is on (readable if esbuild cannot run), readable when
    | it is off. Only the current version's files are kept.
    |
    | split_pages: each Route::fusion() page is a chunk of its own, loaded
    | when the page is first visited (as Next.js and Nuxt split by page), so
    | app.js stays small however many pages there are: it keeps the runtime
    | glue, the shared (non-page) components and the PHP functions they use.
    | #[LazyLoad(false)] keeps a page in app.js; #[LazyLoad('group')] puts
    | pages that go together in one chunk.
    |
    | esbuild: the Node binary, and the directory whose node_modules has
    | esbuild (the application's own, normally).
    |
    */

    'compiled' => storage_path('framework/fusion'),

    'minify_on_change' => env('FUSION_MINIFY_ON_CHANGE', true),

    'split_pages' => env('FUSION_SPLIT_PAGES', true),

    'esbuild' => [
        'node' => env('FUSION_NODE', 'node'),
        'root' => base_path(),
    ],

];
