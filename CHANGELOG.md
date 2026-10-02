# Changelog

All notable changes to `nitro/fusion` will be documented in this file.

## Unreleased

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

- Merge props: `Fusion::merge()` (`->prepend()`, `->matchOn()`), `Fusion::deepMerge()` and
  `Fusion::scroll()` with `$loadMore('prop')`, merged on partial reloads in the browser and on
  the server.
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
  the page's route or controller. Closure props of `Fusion::render()` are called only when
  needed. Deferred properties load as a partial reload.

- Deferred page properties: `$this->defer()` in `mount()` and `Fusion::defer()` props, loaded
  after the page is shown, with `@deferred` / `@placeholder` in views.

- A `fusion:navigate` visit from an older build is answered with `409` and `X-Fusion-Location`,
  and the browser loads the page in full with the new assets.
- Back and forward restore each page's scroll position; `fusion:navigate.preserve-scroll` keeps
  the current one.
- `assertFusion()` and `fusionState()` on test responses, as `assertInertia()`.

- Pages and components are separate, as in Livewire 4: pages (`App\Fusion\Pages`,
  `fusion.pages` views, named `pages::...`) are routed or rendered by controllers and have
  `#[Layout]` and `#[Title]`; components (`App\Fusion\Components`, `fusion.components` views) are
  placed in views. The `components` and `pages` config entries replace `components` and
  `view_prefix`.
- `Fusion::render()` returns a page with props from a Laravel controller, as `Inertia::render()`.
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
