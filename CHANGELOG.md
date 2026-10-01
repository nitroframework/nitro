# Changelog

All notable changes to `nitro/fusion` will be documented in this file.

## Unreleased

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
