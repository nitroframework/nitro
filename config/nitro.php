<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Endpoint
    |--------------------------------------------------------------------------
    |
    | Where Nitro answers: POST {endpoint}/update runs #[Server] methods, and
    | {endpoint}/nitro.js and {endpoint}/app.js serve the runtime and the
    | compiled components. The update route runs through `middleware`
    | (sessions, cookies and CSRF protection come from the web group).
    |
    */

    'endpoint' => '/_nitro',

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Components and Pages
    |--------------------------------------------------------------------------
    |
    | Components are placed in views: App\Nitro\Components\Posts\Table is
    | <nitro:posts.table /> and renders "nitro.components.posts.table".
    |
    | Pages are whole screens, routed with Route::nitro():
    | App\Nitro\Pages\Posts\Index is "pages::posts.index" and renders
    | "nitro.pages.posts.index".
    |
    */

    'components' => [
        'namespace' => 'App\\Nitro\\Components',
        'path' => app_path('Nitro/Components'),
        'views' => 'nitro.components',
    ],

    'pages' => [
        'namespace' => 'App\\Nitro\\Pages',
        'path' => app_path('Nitro/Pages'),
        'views' => 'nitro.pages',
    ],

    /*
    | Stores hold state several components share (a cart, preferences): classes
    | extending Nitro\Store, with no view. App\Nitro\Stores\Cart is
    | "stores::cart"; a component uses it through a property of its type.
    */

    'stores' => [
        'namespace' => 'App\\Nitro\\Stores',
        'path' => app_path('Nitro/Stores'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | The layout a Route::nitro() page renders in, unless the component names
    | one with #[Layout]. The page is its $slot, #[Title] its $title.
    |
    */

    'layout' => 'components.layouts.app',

    /*
    |--------------------------------------------------------------------------
    | Head
    |--------------------------------------------------------------------------
    |
    | What @nitroHead prints in the layout's <head> for a page that does not
    | say otherwise (#[Title], #[Description], $this->head() in mount()):
    |
    | title: the format of the <title>, the page's title in place of %s
    |        ('%s · My Blog'); a page without a title gets app.name.
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
    | Cached results
    |--------------------------------------------------------------------------
    |
    | #[Cached] on mount() or a #[Server] method keeps its result in this
    | cache store (null: the application's default). Nitro::expire('tag')
    | forgets every result kept under that tag, on any store.
    |
    */

    'cache' => [
        'store' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallbacks
    |--------------------------------------------------------------------------
    |
    | A component that fails (its mount(), its view, a #[Server] call, its
    | render in the browser) shows a fallback in its place, and the rest of
    | the page works on; the error goes to Laravel's exception handler. The
    | fallback is the component's fallback() when it has one, else `view`,
    | else a short message with a link that loads the page again.
    |
    | enabled: null (the default) is on when APP_DEBUG is off: in development
    | you see the error itself.
    |
    */

    'fallbacks' => [
        'enabled' => null,
        'view' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | Files chosen in a nitro:model file input are uploaded at once into
    | `directory`, checked against `rules` (each file), and given to the
    | component as Nitro\Upload: the component validates and store()s
    | them. Files no component stored are removed after a day. `max_files`
    | limits a multiple input; `throttle` the uploads per minute. PHP's own
    | upload_max_filesize and post_max_size must allow the size in `rules`.
    |
    */

    'uploads' => [
        'directory' => storage_path('app/nitro-uploads'),
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
    | Where the compiled components are written. `php artisan nitro:build`
    | writes the production bundle, minified with esbuild as `vite build`
    | does (npm install --save-dev esbuild); it is a step of its own, not
    | part of `optimize`, and APP_DEBUG has no say in it. Without a build,
    | components are compiled on first use: built as nitro:build does when
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

    'compiled' => storage_path('framework/nitro'),

    /*
    |--------------------------------------------------------------------------
    | Static files
    |--------------------------------------------------------------------------
    |
    | `php artisan nitro:build` also publishes the build here, under public/
    | (as `vite build` writes public/build), and @nitroScripts points at it:
    | the web server or a CDN serves it without PHP (asset() applies, so does
    | ASSET_URL). Names carry the version; chunks are named by a hash. Until
    | the next build after a change, the Nitro routes serve the files. Set
    | to false to always serve them through the routes.
    |
    */

    'static' => 'nitro',

    /*
    |--------------------------------------------------------------------------
    | Obfuscation
    |--------------------------------------------------------------------------
    |
    | A build (nitro:build, minify_on_change) names methods, #[Server]
    | methods and pages by aliases derived from APP_KEY, in the bundle and in
    | what the page carries (nitro:name, a #[Server] call's method), and
    | leaves handlers' text (nitro:click="...") out of the page. Components
    | are aliased too, unless a view has <nitro:dynamic-component>. Lifecycle
    | hooks, property names, text on the page and URLs keep their names. A
    | readable bundle (no build) is never obfuscated.
    |
    */

    'build' => [
        'obfuscate' => true,
    ],

    'minify_on_change' => true,

    /*
    |--------------------------------------------------------------------------
    | Rendering in the browser
    |--------------------------------------------------------------------------
    |
    | How the browser makes the elements of a view it renders itself: a page
    | reached by nitro:navigate, an @if that turns true, new rows of a list.
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
    | How a nitro:navigate visit shows a page. A page can choose otherwise
    | with #[Navigate('data')] or #[Navigate('markup')].
    |
    | 'data' (the default): the browser asks the server for the page's data,
    | then renders the page complete. Nothing shows half-filled.
    |
    | 'markup': the browser renders the page at once, with its properties'
    | defaults, and fills it in when the data arrives. Until then every
    | property counts as deferred: @deferred / @placeholder show what to show
    | meanwhile. Its views must render with the defaults (no $post['title']
    | on an empty array). A page in another layout, a redirect or an error is
    | handled as with 'data'. The first page load is the server's HTML either way.
    |
    */

    'navigation' => 'data',

    /*
    |--------------------------------------------------------------------------
    | View transitions
    |--------------------------------------------------------------------------
    |
    | true: a nitro:navigate visit changes the page inside the browser's View
    | Transitions (a cross-fade by default; style ::view-transition-old(root)
    | and ::view-transition-new(root), or name elements with
    | view-transition-name, for more). A browser without them changes the page
    | as usual. nitro:navigate.transition turns them on for one link.
    |
    */

    'view_transitions' => false,

    /*
    |--------------------------------------------------------------------------
    | Dev overlay
    |--------------------------------------------------------------------------
    |
    | A badge in the corner of each page, and a drawer: the components on the
    | page and their state, each request (its time, the server's, the
    | database's, its size), the page's data, the stores, the errors. null
    | (the default) is on when APP_DEBUG is on; it is never served otherwise.
    |
    */

    'devtools' => null,

    /*
    |--------------------------------------------------------------------------
    | Your own nitro: attributes
    |--------------------------------------------------------------------------
    |
    | Attributes your views may use besides Nitro's, such as nitro:tooltip.
    | Each needs a Nitro.directive() of the same name in your JavaScript:
    |
    |     Nitro.directive('tooltip', ({ el, value, modifiers, component }) => {
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
    | JavaScript registers each engine (nitroVue(), nitroReact(),
    | nitroSolid(), nitroSvelte()).
    | A view may hold its component's PHP itself: a <php> block.
    |
    | The adapter is the engine's server side: it names the framework and
    | finds a view's <php> block. An entry of your own, for another
    | framework, may name an adapter class of yours (extending
    | Nitro\View\ScriptEngine) or none, for views that hold no PHP.
    |
    */

    'views' => [
        'vue' => [
            'adapter' => Nitro\View\Adapters\VueAdapter::class,
            'path' => [resource_path('views/vue'), resource_path('js/vue')],
            'extensions' => ['vue'],
        ],
        'react' => [
            'adapter' => Nitro\View\Adapters\ReactAdapter::class,
            'path' => [resource_path('views/react'), resource_path('js/react')],
            'extensions' => ['jsx', 'tsx'],
        ],
        'solid' => [
            'adapter' => Nitro\View\Adapters\SolidAdapter::class,
            'path' => [resource_path('views/solid'), resource_path('js/solid')],
            'extensions' => ['jsx', 'tsx'],
        ],
        'svelte' => [
            'adapter' => Nitro\View\Adapters\SvelteAdapter::class,
            'path' => [resource_path('views/svelte'), resource_path('js/svelte')],
            'extensions' => ['svelte'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server rendering for JavaScript views
    |--------------------------------------------------------------------------
    |
    | On, a page's first HTML has its JavaScript views rendered in it, and
    | the browser hydrates them. Your app's render service renders them: its
    | SSR bundle (nitro/ssr), started with php artisan nitro:ssr.
    | When it is not running, or a view fails there, the browser renders
    | the view, as it does with this off. Blade views are always rendered
    | on the server.
    |
    | enabled and url come from .env: whether this machine runs the render
    | service, and where it listens, differ from one machine to the next.
    |
    */

    'ssr' => [
        'enabled' => env('NITRO_SSR', false),
        'url' => env('NITRO_SSR_URL', 'http://127.0.0.1:13714'),
        /** Seconds a view may take to render before the browser is left to render it. */
        'timeout' => 2,
        /** What nitro:ssr runs: the bundle `vite build --ssr` made. */
        'bundle' => base_path('bootstrap/ssr/ssr.js'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Classes extending Nitro\Feature that run around every component
    | on the server: when it is made or restored, around a #[Server] method,
    | before its state goes to the browser. They run after Nitro's own
    | (#[Url], #[Validate]), in the order listed.
    |
    */

    'features' => [],

    /** esbuild, run with Node: the Node binary on this machine (.env), and the folder esbuild is installed in. */
    'esbuild' => [
        'node' => env('NITRO_NODE', 'node'),
        'root' => base_path(),
    ],

];
