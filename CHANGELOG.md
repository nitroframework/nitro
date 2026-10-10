# Changelog

All notable changes to `nitro/nitro` will be documented in this file. Before the rename below,
the package was `nitro/fusion` and the entries say Fusion.

## v2.0.2 (2026-10-11)

- The documentation is at https://www.nitrophp.com: the README links its pages, and
  `composer.json` names it as the homepage.

## v2.0.1 (2026-10-10)

- The progress bar is part of Nitro's runtime (`js/src/runtime/navigation/bar.js`), under Nitro's
  license; `nitro-progress` is no longer a dependency.

## v2.0.0 (2026-10-10)

- Fusion is now Nitro: the package `nitro/nitro`, the namespace `Nitro\` (`Nitro\Fusion\X` is
  `Nitro\X`), the facade and `window.Nitro`, `<nitro:counter />` and `nitro:click`, `@nitroScripts`,
  `config/nitro.php` (`nitro.*`), `php artisan nitro:build`, the endpoint `/_nitro`, the
  `X-Nitro-*` headers and `data-nitro-*` attributes, the adapters' `nitro/vue` (and react, solid,
  svelte, ssr). An application's own `App\Fusion\...` components and pages are `App\Nitro\...` by
  default (`nitro.components`, `nitro.pages`). State signed before the rename is not accepted
  after it: open pages load again once.
- The tests run on Laravel only.

- A collection property of several model classes (a polymorphic relation's) is read again with
  each model's own class, in order; it was read as the first model's class.
- A nullable model property whose row was deleted is `null` on the next `#[Server]` call; every
  call answered 404. A non-nullable one still answers 404.
- A `#[Server]` parameter typed `object`, `iterable`, `callable`, `self` or `parent` fails the
  build; it answered 403 or 500 on each call.

- `#[Validate]` on properties: rules checked on the server whenever the browser changes the
  property; a failure keeps the `#[Server]` method from running, its messages in `$errors`.
  `$this->validate()` with no rules checks them all. The README says plainly that an unlocked
  property takes any value of its type from the browser.

- Component state is bound to its session (`fusion.state.bind_to_session`, on by default): a
  snapshot from another visitor's session, or from before a logout, is refused (419); a login
  keeps it. It is signed with the time it was issued, and `fusion.state.lifetime` (seconds,
  `null` by default) refuses older ones. On a 419 the page reloads; `fusion:expired` can be
  `preventDefault()`ed.

- `#[Server]` parameters typed with a model receive the record the browser's key names, as route
  model binding resolves it (404 when it is missing); a backed enum receives the case its value
  names (403 otherwise). They were given a new, empty model, or failed. A variadic model, an
  abstract model or a pure enum parameter fails the build.

- Client pages: `#[Navigate('client')]` on a page that needs nothing from the server. A visit
  renders it in the browser without a request, its title and head tags worked out at build time.
  The build refuses one with `mount()`, `#[Server]` methods, `#[Url]` properties, route parameters
  or middleware beyond the `web` group.

- The page's head: `@fusionHead` in the layout prints the `<title>` (in the `fusion.head.title`
  format) and the tags search engines and link previews read: description, Open Graph, Twitter
  card, canonical, robots. A page sets them with `#[Title]`, `#[Description]` or `$this->head()`
  in `mount()` (`title()`, `description()`, `image()`, `canonical()`, `noindex()`, `meta()`); the
  rest comes from `fusion.head`. A `fusion:navigate` visit brings the new page's head with its data.

- Markup-first navigation: `fusion.navigation` (`'data'`, the default, or `'markup'`) and
  `#[Navigate('markup')]` on a page. A visit to a markup-first page renders it at once with its
  defaults, every property deferred (`@deferred` / `@placeholder` show placeholders), and fills it
  in when the data arrives; what is typed meanwhile is kept, and `#[Server]` calls wait for the
  data. Another layout, a redirect or an error goes on as a data-first visit.

- `$visit(url)` in handlers: a `fusion:navigate` visit from any page (search on Enter).

- `fusion:build` is about four times faster and says how it goes: every chunk is minified in one
  Node process (starting Node was most of the time), chunks unchanged since the last build come
  from a cache, and each step prints with its time (a progress bar while compiling; `-v` lists the
  components).

- Merge props from a page's `mount()`: `$this->merge()`, `$this->deepMerge()` and
  `$this->scroll()`; their closures run only when a partial reload wants the property.
  `$this->wants()` skips other work a partial reload does not need.

- `fusion.rendering`: `'clone'` (the default) copies each static part of a view the browser
  renders itself (a visit, an `@if` turning true, new rows) from one made once; `'create'` makes
  every element in turn, as before. On the dashboard's Students page a visit renders about 20%
  faster; the page is the same either way.

- `fusion:navigate` to any page, not only Fusion pages: a Blade view, a view a controller returns or a page
  in another layout comes as HTML and replaces the document without reloading (as
  `wire:navigate`), and links from such pages navigate too.

- The progress bar is now nitro-progress instead of NProgress: it finishes as the new page appears
  rather than trailing after it, and creeps as one CSS animation, so a page busy rendering cannot
  stall it. New settings `maximum` and `fade_speed`; new defaults (starts at
  15%, no delay). The markup is unchanged.

- Transitions: `.out-in` and `.in-out` (Vue's modes) for an element replacing another, and
  `.move` (Vue's `<TransitionGroup>`): the rows of a list slide to their new places, around a row
  leaving too.

- Islands: `fusion:hydrate.idle`, `.visible` (`.visible.200px`), `.interaction` and
  `.media="(query)"` start a component placed in a Blade view later than the page, and
  `fusion:static` renders it without state; a waiting island's chunks load when it starts.
- A component placed in a Blade view keeps its slots when it renders in the browser (their HTML
  now goes with its state; they were lost).

- Browser APIs: `Fusion::browser()` with `clipboard()`, `storage()`, `session()`, `canvas($ref)`
  and `socket($url)`, chained from browser methods and event handlers; checked against
  `src/Browser` at build time, in the bundle only when used, and throwing on the server.

- Transitions: `fusion:transition`, as Vue's `<Transition>`: a built-in fade and scale
  (`.opacity`, `.scale`, `.duration.300ms`, `.origin.top`, `.in`, `.out`, `.appear`) or Vue's
  `name-enter-*` / `name-leave-*` classes; a leaving element stays until its animation ends, and
  a removed row of a list leaves in place.

- Broadcasts: `#[On('echo:channel,Event')]`, `echo-private:`, `echo-presence:` (`here`, `joining`,
  `leaving`) and `echo-notification:`, through the application's Laravel Echo; `{property}` in a
  channel reads the component's state.

- Obfuscated builds (`fusion.build.obfuscate`, on by default): methods, `#[Server]` methods,
  properties, pages and components under aliases derived from `APP_KEY`, in the bundle, the page
  and what the browser sends; handlers' text left out of the page. The server translates them back.

- `fusion:build` publishes the build to `public/fusion` (`fusion.static`), served by the web
  server without PHP; `@fusionScripts` points at it, and falls back to the `/_fusion` routes
  until the next build after a change. Built chunks are named by a hash (`7f3a9c21-<version>.js`).
- A chunk the page already loaded is not fetched again on a visit.

- Merge props (`->prepend()`, `->matchOn()`) and scroll props with `$loadMore('prop')`, merged
  on partial reloads in the browser and on the server. A scroll prop holds the paginator's items;
  `$this->hasMore('prop')` says whether there is a next page.
- Once shared data: `Fusion::once()`, `Fusion::shareOnce()` (`->until()`, `->fresh()`).
- Flash data: `Fusion::flash()`, read with `$this->flashed()`, announced with `fusion:flash`.
- A partial reload signs the properties it reloads on their own; the next `#[Server]` call lays
  them over the snapshot, so reloaded `#[Locked]` properties keep their values.
- Fusion's controllers resolve Fusion for each request (a controller kept by the router no longer
  holds an earlier request's Fusion).
- A visit renders its layout for the title without `@fusionScripts` (shared data is no longer
  resolved twice).

- `fusion:visible="method"` calls a method when its element comes into view (`.once`, `.200px`).

- `#[Remember]` properties: kept in the history entry, brought back by back, forward and reload.

- Partial reloads: `$this->reload('orders')` and `$reload('orders')` reload page properties from
  the page's route. Deferred properties load as a partial reload.

- Deferred page properties: `$this->defer()` in `mount()`, loaded
  after the page is shown, with `@deferred` / `@placeholder` in views.

- A `fusion:navigate` visit from an older build is answered with `409` and `X-Fusion-Location`,
  and the browser loads the page in full with the new assets.
- Back and forward restore each page's scroll position; `fusion:navigate.preserve-scroll` keeps
  the current one.
- `assertFusion()` and `fusionState()` on test responses, as `assertInertia()`.

- Pages and components are separate, as in Livewire 4: pages (`App\Fusion\Pages`,
  `fusion.pages` views, named `pages::...`) are routed and have
  `#[Layout]` and `#[Title]`; components (`App\Fusion\Components`, `fusion.components` views) are
  placed in views. The `components` and `pages` config entries replace `components` and
  `view_prefix`.
- `Fusion::share()` data for every component, read with `$this->shared()` on the server and in the
  browser, and sent with pages, `fusion:navigate` visits and `#[Server]` calls.
- A `Fusion` facade.
- Each page is compiled into a chunk of its own, however it is reached. `#[LazyLoad(false)]`
  keeps a page in the main bundle.
- Changed components are rebuilt minified when esbuild is available (`minify_on_change`).
- File uploads: `Nitro\Fusion\Upload` properties, upload progress, previews and validation errors.
- Anonymous Blade components and `@include` in component views, compiled for the browser.
- `count()` on objects with a `count()` method, such as `$errors`.
- Blade's component state is kept across nested Fusion renders.
- The test suite creates the fixture application's storage directories and runs the Nitro cases
  only when Nitro is installed.
- Livewire-style components: a PHP class and a Blade view, compiled to JavaScript, with
  `#[Server]` methods, signed state, `fusion:navigate`, `fusion:current`, the NProgress bar,
  `#[Url]`, `#[LazyLoad]`, polling and layouts. Runs on Laravel 13 and on Nitro.
