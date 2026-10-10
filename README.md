# Nitro: PHP components for Laravel that run in the browser

[![Latest version](https://img.shields.io/packagist/v/nitro/nitro)](https://packagist.org/packages/nitro/nitro)
[![Tests](https://github.com/nitroframework/nitro/actions/workflows/tests.yml/badge.svg)](https://github.com/nitroframework/nitro/actions/workflows/tests.yml)
[![Total downloads](https://img.shields.io/packagist/dt/nitro/nitro)](https://packagist.org/packages/nitro/nitro)
[![License](https://img.shields.io/packagist/l/nitro/nitro)](LICENSE.md)

**Write a component's logic in PHP and its view in Blade, Vue, React, Solid or Svelte. Nitro
compiles the PHP to JavaScript that runs in the browser, and calls the server only when you ask it
to.**

Nitro is a full-stack framework for Laravel: interactive pages with no request on every click, no
separate frontend and no API to write.

## Why Nitro

- **Instant interactions.** Clicks, typing, filtering and re-rendering run in the browser, with
  no round trip to the server.
- **The server when you need it.** Mark a method `#[Server]` and it runs on the server, with the
  database, mail, the session and the component's signed state.
- **Your choice of view.** Blade, Vue, React, Solid or Svelte, with the same PHP class behind it.
- **Fast first page, good for SEO.** Blade views are rendered on the server, so the page shows at
  once and search engines read it all; Vue, React, Solid and Svelte views can be too, with
  Nitro's SSR service.
- **Fine-grained rendering.** In a Blade view, a change updates only the DOM nodes that show it:
  no virtual DOM, no HTML diffing. Vue, React, Solid and Svelte views render their own way.
- **Single-page navigation.** Links load the next page's data and render it in the browser, with
  prefetching, a progress bar and view transitions.

## Installation

Nitro needs PHP 8.3+, Laravel 13, and Node.js with esbuild for minifying builds.

```bash
composer require nitro/nitro
npm install --save-dev esbuild
```

Add `@nitroHead` and `@nitroScripts` to your layout:

```blade
{{-- resources/views/components/layouts/app.blade.php --}}
<html>
<head>
    @nitroHead
</head>
<body>
    {{ $slot }}

    @nitroScripts
</body>
</html>
```

## Your first component

```bash
php artisan make:nitro Counter
```

```php
// app/Nitro/Components/Counter.php
namespace App\Nitro\Components;

use App\Models\Score;
use Nitro\Attributes\Server;
use Nitro\Component;

class Counter extends Component
{
    public int $count = 0;

    public function increment(): void   // runs in the browser
    {
        $this->count++;
    }

    #[Server]
    public function save(): void        // runs on the server
    {
        Score::create(['value' => $this->count]);
    }
}
```

```blade
{{-- resources/views/nitro/components/counter.blade.php --}}
<div>
    <button nitro:click="increment">+</button>
    <span>{{ $count }}</span>
    <button nitro:click="save" nitro:loading.attr="disabled">Save</button>
</div>
```

```blade
{{-- any Blade view --}}
<nitro:counter />
```

`increment()` is compiled to JavaScript: clicking `+` updates the number at once, with no request.
`save()` is a `#[Server]` method: clicking Save sends the component's signed state to the server,
runs the method there, and sends the result back.

## A page

Pages are routed components with a layout and a title:

```php
// routes/web.php
Route::nitro('/posts', App\Nitro\Pages\Posts\Index::class);
```

Links marked `nitro:navigate` move between pages without a full reload.

## Features

- **[Components](REFERENCE.md#components)**: properties, browser and `#[Server]` methods,
  `#[Computed]`, lifecycle hooks, events
- **Views**: Blade, or Vue, React, Solid and Svelte through Vite, with optional server rendering
- **[Forms and validation](REFERENCE.md#properties)**: `nitro:model`, `#[Validate]`, errors in the
  view
- **[Pages and navigation](REFERENCE.md#pages)**: `Route::nitro()`, layouts, titles, the page's
  head, single-page visits
- **[Page data](REFERENCE.md#page-data)**: deferred properties, partial reloads, shared and flash
  data
- **[Stores](REFERENCE.md#stores)**: state shared between components
- **[Uploads](REFERENCE.md#uploads)**: files with validation and progress
- **[Islands](REFERENCE.md#islands)** and **[transitions](REFERENCE.md#transitions)**
- **[Broadcasting](REFERENCE.md#broadcasting)**: live updates with Laravel Echo
- **[Browser APIs](REFERENCE.md#browser-apis)**: storage, clipboard and more, from PHP
- **[Testing](REFERENCE.md#testing-your-application)**: `assertNitro()` on responses
- **[Production builds](REFERENCE.md#building-for-production)**: `php artisan nitro:build`, minified,
  versioned and cacheable for a year

## How Nitro compares

| | Nitro | Livewire | Inertia |
| --- | --- | --- | --- |
| Logic | PHP | PHP | JavaScript, with PHP controllers |
| Views | Blade, Vue, React, Solid, Svelte | Blade | Vue, React, Svelte |
| An interaction runs | in the browser | on the server (one request) | in the browser |
| Server code | `#[Server]` methods | every method | controllers |
| First page | rendered by the server (Blade), or with SSR | rendered by the server | rendered in the browser, or with SSR |

Nitro can run on the same page as Livewire: its tags, attributes, scripts and endpoints all use
the `nitro:` prefix.

## Documentation

The complete reference is in [REFERENCE.md](REFERENCE.md), and the documentation site is on its
way.

## Contributing

```bash
composer install
npm install
composer test     # PHP and Node: parity with PHP, rendering, security, the runtime in a browser
composer analyse  # PHPStan
npm run build     # rebuild dist/nitro.js after changing js/src
```

## Security

Please report security issues privately through
[GitHub's security advisories](https://github.com/nitroframework/nitro/security/advisories/new),
not in public issues. See [Security](REFERENCE.md#security) for how Nitro signs and checks state.

## License

Nitro was created by [Zeeshan Ali](https://github.com/ZeeshanX4) and is open-source software
licensed under the [MIT license](LICENSE.md).
