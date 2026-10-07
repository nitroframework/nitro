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
    | Components and Pages
    |--------------------------------------------------------------------------
    |
    | Components are placed in views: App\Fusion\Components\Students\Table is
    | <fusion:students.table /> and renders "fusion.components.students.table".
    |
    | Pages are whole screens, routed with Route::fusion() or returned by a
    | controller with Fusion::render(): App\Fusion\Pages\Students\Index is
    | "pages::students.index" and renders "fusion.pages.students.index".
    |
    */

    'components' => [
        'namespace' => 'App\\Fusion\\Components',
        'path' => app_path('Fusion/Components'),
        'views' => 'fusion.components',
    ],

    'pages' => [
        'namespace' => 'App\\Fusion\\Pages',
        'path' => app_path('Fusion/Pages'),
        'views' => 'fusion.pages',
    ],

    /*
    | Stores hold state several components share (a cart, preferences): classes
    | extending Nitro\Fusion\Store, with no view. App\Fusion\Stores\Cart is
    | "stores::cart"; a component uses it through a property of its type.
    */

    'stores' => [
        'namespace' => 'App\\Fusion\\Stores',
        'path' => app_path('Fusion/Stores'),
    ],

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
    | Head
    |--------------------------------------------------------------------------
    |
    | What @fusionHead prints in the layout's <head> for a page that does not
    | say otherwise (#[Title], #[Description], $this->head() in mount()):
    |
    | title: the format of the <title>, the page's title in place of %s
    |        ('%s · Campus Admin'); a page without a title gets app.name.
    | description: the description search results and link previews show.
    | image: the image link previews show (an absolute URL).
    |
    */

    'head' => [
        'title' => '%s',
        'description' => null,
        'image' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | persistent_middleware: when a component calls the server, the middleware
    | of the page it was rendered on is checked again, for these classes and
    | the classes extending them (so a component from an admin page stops
    | working when its visitor loses access). Add your own (a role check).
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
    | Component state
    |--------------------------------------------------------------------------
    |
    | Each component's state travels signed: the browser cannot change it.
    |
    | bind_to_session: sign it with the session's own secret too, so a
    | snapshot is good only in the session it was made for: not in another
    | visitor's, and not after logout. Turn it off only for pages cached as
    | whole HTML for every visitor (a CDN, full-page caching), whose state
    | would otherwise belong to the first visitor's session.
    |
    | lifetime: how long a snapshot is good for, in seconds; null (the
    | default): as long as its session. Set it for flows where an old state
    | must not come back (payments, balances): 1800 is half an hour. A page
    | older than that reloads on its next call.
    |
    | Signing keeps the browser from changing a #[Locked] value, not from
    | sending an older one of the same session: read what must be current
    | (a balance, a paid step) from the database in the #[Server] method.
    |
    */

    'state' => [
        'bind_to_session' => true,
        'lifetime' => null,
    ],

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
    | Each page is a chunk of its own, loaded when the page is first visited
    | (as Next.js and Nuxt split by page), so app.js stays small however many
    | pages there are: it keeps the components pages share and the PHP
    | functions they use. #[LazyLoad(false)] keeps a page in app.js;
    | #[LazyLoad('group')] puts pages that go together in one chunk.
    |
    | esbuild: the Node binary, and the directory whose node_modules has
    | esbuild (the application's own, normally).
    |
    */

    'compiled' => storage_path('framework/fusion'),

    /*
    |--------------------------------------------------------------------------
    | Static files
    |--------------------------------------------------------------------------
    |
    | `php artisan fusion:build` also publishes the build here, under public/
    | (as `vite build` writes public/build), and @fusionScripts points at it:
    | the web server or a CDN serves it without PHP (asset() applies, so does
    | ASSET_URL). Names carry the version; chunks are named by a hash. Until
    | the next build after a change, the Fusion routes serve the files. Set
    | to false to always serve them through the routes.
    |
    */

    'static' => 'fusion',

    /*
    |--------------------------------------------------------------------------
    | Obfuscation
    |--------------------------------------------------------------------------
    |
    | A build (fusion:build, minify_on_change) names methods, #[Server]
    | methods and pages by aliases derived from APP_KEY, in the bundle and in
    | what the page carries (fusion:name, a #[Server] call's method), and
    | leaves handlers' text (fusion:click="...") out of the page. Components
    | are aliased too, unless a view has <fusion:dynamic-component>. Lifecycle
    | hooks, property names, text on the page and URLs keep their names. A
    | readable bundle (no build) is never obfuscated.
    |
    */

    'build' => [
        'obfuscate' => env('FUSION_OBFUSCATE', true),
    ],

    'minify_on_change' => env('FUSION_MINIFY_ON_CHANGE', true),

    /*
    |--------------------------------------------------------------------------
    | Rendering in the browser
    |--------------------------------------------------------------------------
    |
    | How the browser makes the elements of a view it renders itself: a page
    | reached by fusion:navigate, an @if that turns true, new rows of a list.
    | (The first page load is the server's HTML, taken over as it is.)
    |
    | 'clone' (the default): each static part, markup without data, is copied
    | from one made the first time it renders; only the parts with data are
    | created. 'create': each element and attribute is created in turn. The
    | page is the same either way.
    |
    */

    'rendering' => 'clone',

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    |
    | How a fusion:navigate visit shows a page. A page can choose otherwise
    | with #[Navigate('data')] or #[Navigate('markup')].
    |
    | 'data' (the default): the browser asks the server for the page's data,
    | then renders the page complete. Nothing shows half-filled.
    |
    | 'markup': the browser renders the page at once, with its properties'
    | defaults, and fills it in when the data arrives. Until then every
    | property counts as deferred: @deferred / @placeholder show what to show
    | meanwhile. Its views must render with the defaults (no $student['name']
    | on an empty array). A page in another layout, a redirect or an error is
    | handled as with 'data'. The first page load is the server's HTML either way.
    |
    */

    'navigation' => 'data',

    /*
    |--------------------------------------------------------------------------
    | Your own fusion: attributes
    |--------------------------------------------------------------------------
    |
    | Attributes your views may use besides Fusion's, such as fusion:tooltip.
    | Each needs a Fusion.directive() of the same name in your JavaScript:
    |
    |     Fusion.directive('tooltip', ({ el, value, modifiers, component }) => {
    |         const tip = makeTooltip(el, value, modifiers);
    |         return () => tip.remove();
    |     });
    |
    | It runs when the element appears, again when the attribute's value
    | changes ({{ }} in it), and its cleanup when the element goes.
    |
    */

    'attributes' => [],

    /*
    |--------------------------------------------------------------------------
    | Views in Vue, React, Solid and Svelte
    |--------------------------------------------------------------------------
    |
    | A component's view can be a Vue, React, Solid or Svelte component
    | instead of Blade: #[View(vue: 'cart/badge')] is cart/badge.vue in one
    | of the vue paths. Where each engine's views are (the first that has
    | the file), and their extensions. Your app's Vite builds them; your
    | JavaScript registers each engine (fusionVue(), fusionReact(),
    | fusionSolid(), fusionSvelte()).
    | A view may hold its component's PHP itself: a <php> block.
    |
    | The adapter is the engine's server side: it names the framework and
    | finds a view's <php> block. An entry of your own, for another
    | framework, may name an adapter class of yours (extending
    | Nitro\Fusion\View\ScriptEngine) or none, for views that hold no PHP.
    |
    */

    'views' => [
        'vue' => [
            'adapter' => Nitro\Fusion\View\Adapters\VueAdapter::class,
            'path' => [resource_path('views/vue'), resource_path('js/vue')],
            'extensions' => ['vue'],
        ],
        'react' => [
            'adapter' => Nitro\Fusion\View\Adapters\ReactAdapter::class,
            'path' => [resource_path('views/react'), resource_path('js/react')],
            'extensions' => ['jsx', 'tsx'],
        ],
        'solid' => [
            'adapter' => Nitro\Fusion\View\Adapters\SolidAdapter::class,
            'path' => [resource_path('views/solid'), resource_path('js/solid')],
            'extensions' => ['jsx', 'tsx'],
        ],
        'svelte' => [
            'adapter' => Nitro\Fusion\View\Adapters\SvelteAdapter::class,
            'path' => [resource_path('views/svelte'), resource_path('js/svelte')],
            'extensions' => ['svelte'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server rendering for Vue, React and Solid views
    |--------------------------------------------------------------------------
    |
    | On, a page's first HTML has its JavaScript views rendered in it, and
    | the browser hydrates them. Your app's render service renders them: its
    | SSR bundle (nitro-fusion/ssr), started with php artisan fusion:ssr.
    | When it is not running, or a view fails there, the browser renders
    | the view, as it does with this off. Blade views are always rendered
    | on the server.
    |
    */

    'ssr' => [
        'enabled' => env('FUSION_SSR', false),
        'url' => env('FUSION_SSR_URL', 'http://127.0.0.1:13714'),
        /** Seconds a view may take to render before the browser is left to render it. */
        'timeout' => 2,
        /** What fusion:ssr runs: the bundle `vite build --ssr` made. */
        'bundle' => base_path('bootstrap/ssr/ssr.js'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Classes extending Nitro\Fusion\Feature that run around every component
    | on the server: when it is made or restored, around a #[Server] method,
    | before its state goes to the browser. They run after Fusion's own
    | (#[Url], #[Validate]), in the order listed.
    |
    */

    'features' => [],

    'esbuild' => [
        'node' => env('FUSION_NODE', 'node'),
        'root' => base_path(),
    ],

];
