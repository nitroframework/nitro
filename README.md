# Nitro Fusion

Nitro Fusion is a full-stack framework for Laravel and Nitro. You write components and pages as a
PHP class and a Blade view, the way you already write Laravel code, and Fusion compiles them to
JavaScript that runs in the browser.

- **The server renders the first page** with Blade, so it is fast to show and works for search
  engines.
- **The browser takes over from there.** Clicks, typing, filtering and re-rendering run in the
  page, with no request.
- **The server is one attribute away.** A method marked `#[Server]` runs on the server, called
  with the component's signed state, for anything that needs the database, mail or the session.
- **Rendering is fine-grained.** A change updates only the DOM nodes that show it; there is no
  virtual DOM and no HTML diffing.

```php
// app/Fusion/Components/Counter.php
namespace App\Fusion\Components;

use App\Models\Score;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;

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
{{-- resources/views/fusion/components/counter.blade.php --}}
<div>
    <button fusion:click="increment">+</button>
    <span>{{ $count }}</span>
    <button fusion:click="save" fusion:loading.attr="disabled">Save</button>
</div>
```

```blade
{{-- any Blade view --}}
<fusion:counter />
```

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Components and pages](#components-and-pages)
- [Components](#components)
- [Views](#views)
- [Directives](#directives)
- [Pages](#pages)
- [Navigation](#navigation)
- [Page data](#page-data)
- [Transitions](#transitions)
- [Islands](#islands)
- [Uploads](#uploads)
- [Browser APIs](#browser-apis)
- [Rendering](#rendering)
- [Building for production](#building-for-production)
- [Configuration](#configuration)
- [Testing your application](#testing-your-application)
- [Limitations](#limitations)
- [Security](#security)
- [Contributing](#contributing)

## Requirements

- PHP 8.3 or higher
- Laravel 13, or Nitro
- Node.js and esbuild, to minify the compiled code: `npm install --save-dev esbuild`

Fusion's browser versions of Laravel's helpers (`Str::`, `route()`, `__()`) follow Laravel 13, so
earlier Laravel versions are not supported. Fusion uses only Laravel's public APIs, and it can run
on the same page as Livewire: its tags, attributes, scripts and endpoints all use the `fusion:`
prefix.

## Installation

```bash
composer require nitro/fusion
php artisan vendor:publish --tag=fusion-config   # optional: config/fusion.php
```

Add `@fusionScripts` before `</body>` in your layout:

```blade
{{-- resources/views/components/layouts/app.blade.php --}}
<html>
<head>
    <title>{{ $title ?? config('app.name') }}</title>
</head>
<body>
    {{ $slot }}

    @fusionScripts
</body>
</html>
```

## Components and pages

Fusion has two kinds of classes:

| | Components | Pages |
| --- | --- | --- |
| Class | `app/Fusion/Components` | `app/Fusion/Pages` |
| View | `resources/views/fusion/components` | `resources/views/fusion/pages` |
| Name | `students.table` for `App\Fusion\Components\Students\Table` | `pages::students.index` for `App\Fusion\Pages\Students\Index` |
| Used | placed in a view: `<fusion:students.table />` | routed: `Route::fusion('/students', Index::class)` |
| Layout and title | no | `#[Layout]`, `#[Title]` |

A page is a whole screen of the application: it has a route, a layout and a title, and it loads its
own data in `mount()`. A component is a reusable part placed inside a page or any Blade view.

A page cannot be placed in a view and a component cannot be routed; Fusion says what to use
instead. The directories, namespaces and view paths are set in `config/fusion.php`.

### Where code runs

| Code | Runs |
| --- | --- |
| `mount()`, `boot()`, `hydrate()`, `dehydrate()` | on the server |
| `#[Server]` methods | on the server, called from the browser |
| Every other method, `#[Computed]` methods, `updated*()` hooks | in the browser |
| The view | on the server for the first render, then in the browser |

A method the browser never reaches is never compiled, so private helpers called only from
`mount()` or `#[Server]` methods can use anything PHP can.

## Components

### Properties

Public properties are the component's state. They can be `null`, `bool`, `int`, `float`, `string`,
`array`, an Eloquent model or an Eloquent collection. Models reach the browser as their visible
attributes and are read again on the server.

| Attribute | |
| --- | --- |
| `#[Locked]` | Readable in the browser, changed only on the server |
| `#[Url]` | Kept in the query string (`as`, `history`, `keep`, `except`); read before `mount()` and kept in step with the property |
| `#[Remember]` | Kept in the browser's history entry: back, forward and a reload bring back its value, such as a half-filled form. Not for `#[Locked]` properties, models or uploads |
| `#[Modelable]` | On one property, lets a parent bind it: `<fusion:search-box fusion:model="query" />` |

Pages also take `#[Title]`, `#[Layout]`, `#[LazyLoad]` and `#[Navigate]` (see [Pages](#pages) and
[Navigation](#navigation)).

### Methods

```php
use Nitro\Fusion\Attributes\Computed;
use Nitro\Fusion\Attributes\Server;

class Cart extends Component
{
    public array $items = [];

    public function mount(Customer $customer): void        // server: parameters from the route or the tag
    {
        $this->items = $customer->cart()->get()->toArray();
    }

    public function remove(int $index): void              // browser
    {
        array_splice($this->items, $index, 1);
    }

    #[Computed]
    public function total(): int                          // browser, read as $this->total
    {
        return array_sum(array_column($this->items, 'price'));
    }

    #[Server]
    public function checkout(Payments $payments): void    // server: arguments checked against their types
    {
        $this->validate(['items' => 'required|array|min:1']);
        $payments->charge($this->items);
        $this->redirect(route('orders.index'));
    }
}
```

- **`mount(...)`** runs once, on the server, when the component is created. Its parameters come
  from the route (with route-model binding) or from the tag: `<fusion:cart :customer="$customer" />`.
- **Methods** run in the browser.
- **`#[Server]` methods** run on the server. Their arguments are checked against their types, and
  class-typed parameters are resolved from the container.
- **`#[Computed]` methods** are read as properties (`$this->total`). The value is worked out once
  and kept until the state changes. They take no parameters and are not part of the state.
- **`$this->validate([...])`** in a `#[Server]` method: failures appear as `$errors`.
- **`$this->reset('prop', ...)`** puts properties back to their defaults; **`$this->redirect($url)`**
  goes to another page.

### Lifecycle hooks

| Hook | Runs |
| --- | --- |
| `boot()` | on the server, on every request |
| `mount(...)` | on the server, when the component is created |
| `hydrate()` | on the server, when a `#[Server]` call starts |
| `dehydrate()` | on the server, before the state is sent to the browser |
| `updatedEmail($value)` | in the browser, after `fusion:model` or `$set` changes `$email` |
| `updated($property, $value)` | in the browser, after any such change |
| `mounted()` | in the browser, once the component is live |
| `destroyed()` | in the browser, when it leaves the page |

`$this->js('...')` runs JavaScript after the next render, with `$fusion` (the component), `$refs`
(its `fusion:ref` elements) and `$el`.

### Events

`#[On('event')]` listens for `$this->dispatch('event', ...$params)` from any component.

```php
$this->dispatch('saved', $id);                       // every listener
$this->dispatch('saved', $id)->to(Sidebar::class);   // only Sidebar components
$this->dispatch('saved', $id)->self();               // only this component

#[On('saved')]
public function refresh(int $id): void { ... }
```

In a view: `$dispatch('event')`, `$dispatchTo('sidebar', 'event')`, `$dispatchSelf('event')`, and
`$parent->save()` to call the parent's method.

### Broadcasting

`#[On('echo:...')]` listens to Laravel broadcasting through the application's Laravel Echo
(`window.Echo`, with Reverb or Pusher). The component subscribes when it appears and stops
listening when it leaves the page.

```php
#[On('echo:orders,OrderPlaced')]                         // a public channel
public function orderPlaced(array $event): void { $this->orders[] = $event['order']; }

#[On('echo-private:orders.{order.id},OrderShipped')]     // private; {order.id} reads the state
#[Server]
public function orderShipped(array $event): void { $this->order = Order::find($this->order['id'])->toArray(); }

#[On('echo-presence:chat,here')]                          // presence: here, joining, leaving
public function here(array $users): void { $this->online = $users; }

#[On('echo-notification:App.Models.User.{userId}')]       // notifications
public function notified(array $notification): void { $this->unread++; }
```

A `{property}` in a channel must name a public property; the build says so otherwise. An event
with a custom name (`broadcastAs()`) is written with a leading dot: `echo:orders,.order.placed`.

### Pagination and sorting

`Nitro\Fusion\Concerns\WithPagination` and `WithSorting` page and sort an array in the browser:

```php
use WithPagination, WithSorting;

#[Computed]
public function rows(): array
{
    return $this->paginate($this->sorted($this->students), 12);
}
```

`paginate()` returns `items`, `page`, `pages`, `total`, `from` and `to`, with `gotoPage()`,
`previousPage()`, `nextPage()` and `resetPage()`. `sorted()` works with `sortBy('name')` and
`sortIcon('name')`. Call `resetPage()` in `updated()` when a filter changes.

## Views

A view is Blade with one root element. It renders on the server through Blade, and in the browser
through its compiled form, so it uses what both can do:

| | |
| --- | --- |
| Echoes | `{{ }}`, `{!! !!}` |
| Control flow | `@if` `@elseif` `@else` `@unless` `@isset` `@empty` `@foreach` (with `$loop`) `@forelse` `@for` `@while` `@break` `@continue` `@switch` |
| Attributes | `@class` `@style` `@checked` `@selected` `@disabled` `@readonly` `@required` |
| Forms | `@error` and `$errors`, `@csrf`, `@method` |
| Data | `@json` |
| Children | `<fusion:task-item :task="$task" :key="$task['id']" />` |
| Dynamic children | `<fusion:dynamic-component :is="$name" />` renders the component `$name` names; none for `''` |
| Slots | `<fusion:card>...<fusion:slot name="footer">...</fusion:slot></fusion:card>`; the card prints `{{ $slot }}` and `{{ $slots['footer'] }}` (`$slots->has('footer')`, `$slot->isEmpty()`) |
| Teleport | `@teleport('modals') ... @endteleport` renders at `@portal('modals')` in the layout |
| Deferred | `@deferred('stats') ... @placeholder ... @enddeferred` in a page (see [Page data](#page-data)) |
| Laravel helpers | `route()`, `url()`, `asset()`, `__()` / `trans()`, and `config()` for the keys in `fusion.public_config` |
| PHP | operators, casts and PHP's functions: strings, mbstring, arrays, math, ctype, URLs, HTML entities, `preg_*`, `sprintf`, `json_encode` and more, tested against PHP |
| `Str::` | 54 of Laravel's string helpers (`slug`, `limit`, `title`, `headline`, `camel`, `snake`, `mask`, `squish`, ...), tested against Laravel |

`@include` and anonymous Blade components (`<x-panel>`) are inlined when the view is compiled.
Anything else (`@php`, class-based Blade components, custom directives) cannot run in the browser,
and the build fails with the file and line.

Slot content belongs to the parent: its handlers and `fusion:model` act on the parent component.

## Directives

### Events

```blade
<button fusion:click="save">Save</button>
<form fusion:submit.prevent="add">...</form>
<input fusion:keydown.enter="search">
```

Any DOM event works: `fusion:click`, `fusion:submit`, `fusion:input`, `fusion:keydown`, and so on.
The value is a method name, a call (`remove($index)`), or one of the actions below.

| Modifier | |
| --- | --- |
| `.prevent`, `.stop` | `preventDefault()`, `stopPropagation()` |
| `.self` | only when the event starts on the element itself |
| `.once` | only the first time |
| `.window`, `.document` | listen on the window or the document |
| `.outside` | a click outside the element |
| `.enter`, `.escape`, ... | a key |
| `.debounce.300ms`, `.throttle.1s` | rate limits |

### Actions

| Action | |
| --- | --- |
| `$set('prop', value)` | Set a property |
| `$toggle('prop')` | Flip a boolean |
| `$refresh` | Render again (with a `#[Server]` round trip when the component has server methods) |
| `$dispatch('event', ...)`, `$dispatchTo('name', 'event', ...)`, `$dispatchSelf('event', ...)` | Dispatch an event |
| `$reload('prop', ...)` | Reload page properties from the page's route (pages only) |
| `$loadMore('prop')` | Load the next page of a scroll property (pages only) |
| `$visit(url)` | Go to another page, as a `fusion:navigate` link does |

### Attributes

| Attribute | |
| --- | --- |
| `fusion:model="email"` | Two-way binding. `.blur`, `.debounce.300ms`, `.number`, `.trim`, `.boolean`; a dotted path binds a key: `fusion:model="form.email"` |
| `fusion:loading` | Shown while a `#[Server]` call runs. `.remove`, `.class="..."`, `.attr="disabled"`; `fusion:target="save"` limits it to one method |
| `fusion:key` | Keeps an element's identity in a list |
| `fusion:navigate` | A link to another page, rendered without a reload (see [Navigation](#navigation)) |
| `fusion:current="active"` | A class while the link points at the current page |
| `fusion:ignore` | Leaves the element as it is when the component renders again |
| `fusion:confirm="Delete it?"` | Asks before the element's handlers run. `.prompt="Type DELETE to confirm\|DELETE"` asks for the word after the last `\|` |
| `fusion:poll.30s="refresh"` | Calls a method every interval (2.5s by default); a bare `fusion:poll` refreshes. It pauses while the tab is hidden (`.keep-alive` does not); `.visible` polls only while the element is on screen |
| `fusion:init="load"` | Calls a method once, when the element appears |
| `fusion:visible="loadMore"` | Calls a method when the element comes into view. `.once` for the first time only, `.200px` to start that far before. While it stays in view, it is called again after each call that changed something, so a "load more" row fills the screen |
| `fusion:ref="chart"` | The element, as `$refs.chart` in `$this->js()` and for `Fusion::browser()->canvas('chart')` |
| `fusion:transition` | Animates the element as it enters and leaves (see [Transitions](#transitions)) |

## Pages

A page is routed by its class or its name:

```php
use App\Fusion\Pages\Students\Show;

Route::fusion('/students/{student}', Show::class)->name('students.show');
Route::fusion('/students', 'pages::students.index')->name('students.index');
```

`Route::fusion()` returns a normal Laravel route, so names, middleware and route-model binding
work as usual. The page's `mount()` receives the route's parameters:

```php
namespace App\Fusion\Pages\Students;

use App\Models\Student;
use Nitro\Fusion\Attributes\Layout;
use Nitro\Fusion\Attributes\Locked;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Attributes\Title;
use Nitro\Fusion\Component;

#[Title('Student')]
#[Layout('components.layouts.app')]
class Show extends Component
{
    #[Locked]
    public array $student = [];

    public string $note = '';

    public function mount(Student $student): void
    {
        $this->student = $student->toArray();
    }

    #[Server]
    public function addNote(): void
    {
        $this->validate(['note' => 'required|max:500']);
        Student::findOrFail($this->student['id'])->notes()->create(['body' => $this->note]);
        $this->reset('note');
    }
}
```

The page renders inside its layout as `$slot`, with `#[Title]` as `$title`. Without `#[Layout]`,
it uses `fusion.layout` (`components.layouts.app`).

Each page is compiled into a chunk of its own, loaded the first time the page is visited.
`#[LazyLoad(false)]` keeps a page in the main bundle, and `#[LazyLoad('group')]` puts pages or
components that are used together in one chunk.

## Navigation

```blade
<a href="{{ route('students.index') }}" fusion:navigate>Students</a>
```

The first visit is rendered by the server. After that, a `fusion:navigate` link asks the same URL
for the page's data: Laravel routes it as usual (middleware, bindings, redirects), the page runs
`mount()` and answers with its state and title, and the browser renders the page in place of the
current one. The layout, and the components in it, stay.

- **Any page can be visited.** A plain Blade view, a page in another layout, or a view returned by
  a controller is fetched as HTML and replaces the document, still without a reload: new
  stylesheets load before it shows, its new scripts run, and its components and islands start. An
  error or a download loads normally.
- **Scrolling.** A visit scrolls to the top, or to the URL's `#fragment`;
  `fusion:navigate.preserve-scroll` keeps the current position. Back and forward return to where
  each page was left.
- **Starting early.** `fusion:navigate.keypress` starts the visit as the mouse button goes down,
  not on the click; touch and keyboard still use the click.
- **Deploys.** Each visit sends the build's version. When a new build has been deployed since the
  page loaded, the server answers `409` and the browser loads the page in full, with the new code.
- **From code.** `$visit(url)` in a handler and `$this->redirect($url)` in a method visit a page.

Links are for going to another page. To filter or sort what a page shows, change a property
(`$set()`, `fusion:model`) and let the view render again.

### Data first or markup first

A visit can show the new page in two ways:

| | The browser | Feels | Suits |
| --- | --- | --- | --- |
| **Data first** (the default) | asks the server for the page's data, then renders the page complete | one server round trip before the page changes; nothing shows half-filled | pages that mean nothing without their data (a student's profile), pages behind `auth` or `can` |
| **Markup first** | renders the page at once with its properties' defaults, then fills it in when the data arrives | instant | dashboards, lists and forms with placeholders where the data goes |

Set the default in `config/fusion.php`, and choose per page with `#[Navigate]`:

```php
// config/fusion.php
'navigation' => 'data',   // or 'markup'
```

```php
use Nitro\Fusion\Attributes\Navigate;

#[Navigate('markup')]
class Dashboard extends Component
```

On a markup-first visit every property counts as deferred until the data arrives, so
`@deferred` and `@placeholder` show what to show meanwhile:

```blade
<h1>Dashboard</h1>

@deferred('stats')
    <x-stats :stats="$stats" />
@placeholder
    <div class="skeleton"></div>
@enddeferred
```

- The view must render with the defaults: guard data that is not there yet with `@deferred` or
  `??` (no `$student['name']` on an empty array).
- What is typed or toggled before the data arrives is kept. A `#[Server]` call made meanwhile
  waits for the data.
- Components created by the server (those with `mount()`) appear when the data arrives.
- The title is set when the data arrives.
- A page in another layout, a redirect or an error is handled as a data-first visit would be.
- The first page load is the server's HTML either way.

### Progress bar

A progress bar runs at the top of the window during a visit. Publish its configuration with
`php artisan vendor:publish --tag=nprogress-config`:

| Option | |
| --- | --- |
| `show` | Show the bar (`NPROGRESS_SHOW`) |
| `server_calls` | Show it during `#[Server]` calls too |
| `color`, `height`, `position`, `z_index` | Its look |
| `glow`, `glow_width`, `spinner`, `spinner_size` | The glow at its tip, and a spinner |
| `delay` | Milliseconds before it appears |
| `minimum`, `maximum`, `trickle`, `trickle_speed`, `speed`, `easing`, `fade_speed` | Its motion |

The bar's markup is `#nprogress .bar`, so existing NProgress styles apply. The runtime emits
`fusion:request` and `fusion:response` around each `#[Server]` call.

## Page data

These tools are for pages: they shape how a page's data is loaded and reloaded from its route.

### Deferred properties

A page can show first and load slow data right after:

```php
public ?array $stats = null;

public function mount(): void
{
    $this->defer('stats', fn () => Order::stats());
}
```

```blade
@deferred('stats')
    <x-stats :stats="$stats" />
@placeholder
    <p>Loading…</p>
@enddeferred
```

The page is rendered without the deferred properties, then asks its own URL for them. The page
can be used while they load, and what changes meanwhile is kept. `@deferred` without names waits
for every deferred property.

### Partial reloads

A page can reload some of its properties from its route: `$this->reload('orders')` in a method or
a `#[Server]` method, or `$reload('orders')` in a handler.

```php
#[Server]
public function add(string $item): void
{
    Order::create(['item' => $item]);
    $this->reload('orders', 'total');
}
```

The page asks its URL again and takes the named properties from the answer; the others keep their
values in the browser. Without names, every property is reloaded, including what was typed.

A reload runs `mount()` again. `$this->wants()` skips work it does not need:

```php
public function mount(): void
{
    if ($this->wants('orders')) {
        $this->orders = Order::latest()->get()->toArray();
    }

    if ($this->wants('total')) {
        $this->total = Order::sum('amount');
    }
}
```

The answer signs the reloaded properties, so `#[Locked]` properties reach the next `#[Server]`
call as the server gave them.

### Merge and scroll properties

A property can be merged into the value the page has, instead of replaced, when it is reloaded.
The first render and a visit set it as usual.

```php
public array $posts = [];
public array $tags = [];
public array $filters = [];

public function mount(): void
{
    $this->scroll('posts', fn () => Post::latest()->paginate(20))->matchOn('id');
    $this->merge('tags', fn () => Tag::popular()->get()->toArray())->matchOn('id');
    $this->deepMerge('filters', fn () => $this->defaultFilters());
}
```

| | |
| --- | --- |
| `$this->merge()` | Appends the new items. `->prepend()` puts them first; `->append('data')` merges the list at a key |
| `$this->deepMerge()` | Merges arrays key by key, all the way down; lists are appended |
| `$this->scroll()` | A paginator (`paginate()`, `simplePaginate()` or `cursorPaginate()`): the property holds its items. `$loadMore('posts')` asks for the next page and appends it, and `$this->hasMore('posts')` says whether there is one |
| `->matchOn('id')` | An item with the same id replaces the one there instead of being added again |

```blade
@foreach ($posts as $post)
    <article fusion:key="{{ $post['id'] }}">...</article>
@endforeach

@if ($this->hasMore('posts'))
    <div fusion:visible="$loadMore('posts')">Loading…</div>
@endif
```

Each closure runs only when the request wants its property. The merge rule travels with the
signed reload, so the next `#[Server]` call merges the same way and a `#[Locked]` list keeps what
was loaded.

Use scroll properties for long lists paged on the server. A list of a few hundred rows is better
sent whole and filtered in the browser with a `#[Computed]` method: filtering then needs no request.

### Shared data

`Fusion::share()` gives every component and page the same data. Set it in a middleware, so it is
set on every request:

```php
use Nitro\Fusion\Facades\Fusion;

Fusion::share('user', fn () => $request->user()?->only('id', 'name'));
Fusion::share('locale', app()->getLocale());
```

```blade
<span>{{ $this->shared('user.name', 'Guest') }}</span>
```

`$this->shared()` reads it with dot notation and a default, in views and in methods. The data is
sent with the page, each visit and each `#[Server]` answer; closures are called when it is read.
Everything shared is sent to the browser, so share nothing secret.

Data that rarely changes can be sent once:

```php
Fusion::shareOnce('countries', fn () => Country::pluck('name', 'code'));
Fusion::share('permissions', Fusion::once(fn () => $user->permissions())->until(3600));
```

The browser keeps it across visits and `#[Server]` calls, and the server does not send it again
until it expires (`->until()`: seconds, an interval or a date) or is sent `->fresh()`. Once data
is shared at the top level, under a key without dots.

### Flash data

`Fusion::flash()` sends data with the next response only, such as a message after a save:

```php
Fusion::flash('toast', 'Invoice saved');
```

```blade
@if ($this->flashed('toast'))
    <div class="toast">{{ $this->flashed('toast') }}</div>
@endif
```

It goes with this request's page, visit or `#[Server]` answer, or, after a redirect, with the
page the redirect leads to. Then it is gone, and back does not bring it again. The browser emits
`fusion:flash` with it (`event.detail.flash`). Flash data needs the session (the `web` middleware).

## Transitions

`fusion:transition` animates an element as it enters (an `@if` that turns true, a new row of a
`@foreach`) and as it leaves. A leaving element stays in the page until its animation ends.

Without a name, a built-in fade and scale needs no CSS:

```blade
@if ($open)
    <div class="panel" fusion:transition.duration.200ms>...</div>
@endif
```

| Modifier | |
| --- | --- |
| `.opacity`, `.scale` | Fade only, or scale only (both by default) |
| `.duration.300ms` | The duration, 150ms by default |
| `.origin.top` | The transform origin: `top`, `bottom`, `left`, `right`, `center` |
| `.in`, `.out` | Animate entering only, or leaving only |
| `.appear` | Animate on the component's first render in the browser too |
| `.out-in`, `.in-out` | For an element replacing another (`@if` / `@else`, on both): the old one leaves before the new one enters, or after |
| `.move` | On the rows of a `@foreach`: rows that stay slide to their new places when the list changes |

With a name, CSS classes decide what moves and for how long:

```blade
<p fusion:transition="fade">...</p>
```

```css
.fade-enter-active, .fade-leave-active { transition: opacity .3s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.fade-move { transition: transform .3s; }
```

```blade
@foreach ($students as $student)
    <li fusion:key="{{ $student['id'] }}" fusion:transition.move.duration.250ms>{{ $student['name'] }}</li>
@endforeach

@if ($tab === 'fees')
    <section fusion:transition.out-in>...</section>
@else
    <section fusion:transition.out-in>...</section>
@endif
```

Nothing animates on the server's HTML, in a hidden tab, or for visitors who ask for reduced motion.

## Islands

A component placed in a plain Blade view (a page that is not a Fusion page, or a layout) is an
island: the rest of the page stays HTML. An island starts with the page by default;
`fusion:hydrate` starts it later, and `fusion:static` never does.

```blade
<fusion:comments :post="$post" fusion:hydrate.visible />
<fusion:chat-widget fusion:hydrate.interaction />
<fusion:report-chart fusion:hydrate.idle />
<fusion:mobile-menu fusion:hydrate.media="(max-width: 768px)" />
<fusion:price-table fusion:static />
```

| Attribute | The island starts |
| --- | --- |
| `fusion:hydrate.idle` | When the browser is idle |
| `fusion:hydrate.visible` | When it comes into view; `.visible.200px` that far before |
| `fusion:hydrate.interaction` | On the first click, focus, key or input in it. The click is replayed once the island is live, and typed text reaches `fusion:model` |
| `fusion:hydrate.media="(query)"` | When the media query matches |
| `fusion:static` | Never: the server's HTML, with no state sent |

Until it starts, an island is the server's HTML, and what is typed in it is kept. Its slots are
rendered again in the browser once it starts. With `#[LazyLoad]`, an island's code is fetched only
when it starts. Inside a component's view, children start with their parent, so `fusion:hydrate`
there is a build error.

## Uploads

```php
use Nitro\Fusion\Upload;

class CakeForm extends Component
{
    public ?Upload $photo = null;   // one file
    public array $photos = [];      // several: <input type="file" multiple>

    #[Server]
    public function save(): void
    {
        $this->validate(['photo' => 'required|image|max:2048']);
        $this->photo->store('cakes', 'public');
        $this->reset('photo');
    }
}
```

```blade
<input type="file" fusion:model="photo">
<span fusion:loading fusion:target="photo">Uploading…</span>
@error('photo') <p>{{ $message }}</p> @enderror

@if ($photo)
    <img src="{{ $photo->temporaryUrl() }}" alt="{{ $photo->getClientOriginalName() }}">
@endif
```

Choosing a file uploads it at once into `fusion.uploads.directory`, checked against
`fusion.uploads.rules`. The property becomes an `Upload`, which is an `UploadedFile`, so
validation and `store()` work as usual; the browser holds only a signed reference to it.
`temporaryUrl()` (images), `getClientOriginalName()`, `getClientOriginalExtension()`, `getSize()`
and `getMimeType()` work in views on both sides.

The input fires `fusion-upload-start`, `fusion-upload-progress` (`detail.progress`),
`fusion-upload-finish` and `fusion-upload-error`. A file the rules refuse gives the property an
`$errors` message. Uploads that no component stored are removed after a day.

## Browser APIs

`Fusion::browser()` reaches the browser's APIs from methods and handlers, written in PHP:

```php
use Nitro\Fusion\Facades\Fusion;

public function copy(): void
{
    Fusion::browser()->clipboard()->write($this->invoice['number'], fn () => $this->copied = true);
}

public function draw(): void
{
    $canvas = Fusion::browser()->canvas('chart')->size(320, 160)->clear()->fillStyle('#4f46e5');

    foreach ($this->totals as $i => $total) {
        $canvas->fillRect($i * 40, 160 - $total, 30, $total);
    }
}

public function connect(): void
{
    Fusion::browser()->socket('/ws/orders')
        ->onMessage(fn (array $order) => $this->orders[] = $order)
        ->send(['subscribe' => 'orders']);
}
```

```blade
<canvas fusion:ref="chart" fusion:init="draw"></canvas>
<button fusion:click="Fusion::browser()->storage()->forget('draft')">Discard draft</button>
```

| API | |
| --- | --- |
| `clipboard()` | `write($text, $then)`, `read($then)`; on https pages and localhost |
| `storage()`, `session()` | localStorage and sessionStorage: `get($key, $default)`, `set($key, $value)`, `has($key)`, `forget($key)`. Values are stored as JSON |
| `canvas($ref)` | The 2D context of `<canvas fusion:ref="...">`, with the context's own method names (`fillStyle`, `fillRect`, `arc`, `fillText`, ...). `size($width, $height)` sets the size in CSS pixels, scaled for the screen |
| `socket($url)` | A WebSocket, one per URL and component: `onMessage($then)`, `onOpen($then)`, `onClose($then)`, `send($data)`, `close()`. JSON messages arrive as arrays; it reconnects after a drop and closes when the component leaves the page |

Callbacks are closures, and a property they change renders like any other change. Calls are
checked when the bundle is built, and the bundle includes only the APIs the application uses.
On the server, `Fusion::browser()` throws.

## Rendering

Rendering is fine-grained. When a view is compiled, every dynamic part of it (an echo, an
attribute, an `@if`, a `@foreach`, a child's props) records which properties it reads. When a
property changes, only the parts that read it run, and only their DOM nodes change:

```blade
<div>
    <button fusion:click="increment">+</button>
    <p>Clicked {{ $count }} times</p>      {{-- reads $count --}}
    <p>Hello, {{ $name }}</p>               {{-- reads $name: untouched by a click --}}

    @foreach ($students as $student)        {{-- reads $students: untouched by both --}}
        <li>{{ $student['name'] }}</li>
    @endforeach
</div>
```

A click on `+` changes one text node:

| | A change runs | Then compares |
| --- | --- | --- |
| Livewire | the whole view, on the server | the new HTML against the page |
| React, Vue | the component's render function | the new virtual DOM against the last one |
| Fusion | the expressions that read the property | nothing: each expression updates its own node |

What a part reads is worked out at compile time, through the methods it calls. `#[Computed]`
values are kept until the state changes. `$errors`, `$this->shared()` and `$this->flashed()` are
tracked like properties. A `#[Server]` answer, a reload and a parent's new props render only what
reads what changed. Keyed lists (`fusion:key`, `:key`) move, add and remove rows and leave the
others alone.

When the browser creates elements (a visit, an `@if` turning true, new rows), the
`fusion.rendering` setting decides how:

- **`'clone'`** (the default): each static part of a view is built once and copied after that.
- **`'create'`**: every element is created in turn.

The result is the same either way. The first page load uses the server's HTML as it is.

## Building for production

During development, Fusion compiles components on the first request after a change. For
production, build ahead of time:

```bash
php artisan fusion:build
```

- **Compiles** every component and page, minified with esbuild, and shows each step with its time
  (`-v` lists the components). Unchanged chunks come from a cache.
- **Publishes** the build to `public/fusion`. `@fusionScripts` points at those files through
  `asset()`, so `ASSET_URL` and a CDN apply, and the web server sends them without PHP. Their names
  carry a version, so they can be cached for a year
  (`Cache-Control: public, max-age=31536000, immutable`). Add `/public/fusion` to `.gitignore`.
- **Obfuscates** the build (`fusion.build.obfuscate`, on by default): methods, properties, pages
  and components go by aliases derived from `APP_KEY` in the bundle, the page and what the
  browser sends, and the server translates them back. Your PHP, views and tests use the real
  names.

Run `fusion:build` when deploying; `php artisan optimize` does not run it. `php artisan
fusion:clear` removes the compiled files. Until the next build after a change, the `/_fusion`
routes serve the current code.

With obfuscation, write property and key names out in full (`$errors->has('form.email')`,
`'items.'.$i.'.price'`) rather than building them from variables, since only names written out
can be aliased. Keys inside array properties, `#[Url]` query keys, `mounted()`, `destroyed()` and
`updated()` keep their names.

The compiled files hold views and browser methods, never data: state, shared data and flash data
travel with each response. Like any application's JavaScript they are public, so keep secrets out
of views and browser methods.

## Configuration

`config/fusion.php`:

| Key | Default | |
| --- | --- | --- |
| `endpoint` | `/_fusion` | The URL prefix for `#[Server]` calls, uploads and assets (`FUSION_ENDPOINT`) |
| `middleware` | `['web']` | Middleware for the endpoint |
| `components` | `App\Fusion\Components` | Namespace, path and view path of components |
| `pages` | `App\Fusion\Pages` | Namespace, path and view path of pages |
| `layout` | `components.layouts.app` | The layout of pages without `#[Layout]` |
| `persistent_middleware` | auth, can, verified, ... | Page middleware checked again on each `#[Server]` call |
| `max_payload` | 1 MB | The largest `#[Server]` call accepted |
| `uploads` | | `directory`, `rules`, `max_files`, `throttle` |
| `public_config` | `['app.name']` | Config keys `config()` may read in the browser; their values are public |
| `compiled` | `storage/framework/fusion` | Where compiled files are written |
| `static` | `fusion` | The directory in `public/` for the build |
| `build.obfuscate` | `true` | Obfuscate builds (`FUSION_OBFUSCATE`) |
| `minify_on_change` | `true` | Minify when recompiling during development (`FUSION_MINIFY_ON_CHANGE`) |
| `rendering` | `'clone'` | How the browser creates elements: `'clone'` or `'create'` |
| `navigation` | `'data'` | How a visit shows a page: `'data'` (data first) or `'markup'` (markup first); `#[Navigate]` per page |
| `esbuild` | | The Node binary (`FUSION_NODE`) and where esbuild is installed |

## Testing your application

`assertFusion()` reads a page from a response, whether rendered in full or answered to a visit,
and asserts on its name and state. Keys use dot notation.

```php
use Nitro\Fusion\Testing\AssertableFusion;

$this->get('/orders')->assertOk()->assertFusion(fn (AssertableFusion $page) => $page
    ->page('pages::orders.index')
    ->has('orders', 12)
    ->where('orders.0.status', 'new')
    ->where('total', fn ($total) => $total > 0)
    ->missing('secret')
    ->deferred('stats'));

$orders = $this->get('/orders')->fusionState('orders');
```

`deferred()` asserts that the page leaves properties for later, and `notDeferred()` that it does not.

## Limitations

Fusion runs your methods in the browser, which has some consequences:

- **Server work belongs in `#[Server]` methods.** Queries, services, `Auth`, `Cache`, files, mail
  and the clock (`now()`, `date()`) are the server's. A browser method that uses them fails the
  build with the file and line.
- **A component with `mount()` is created by the server**: on the first request, a visit or a
  `#[Server]` call. A `<fusion:dynamic-component>` that names such a component needs one of those.
- **Numbers are JavaScript's.** Integers are exact up to 2^53 (`PHP_INT_MAX` is 2^53-1 in the
  browser), and a whole float arrives as an int. Transcendental functions (`tanh`, `atanh`, ...)
  may differ in the last digit, as they do between PHP builds.
- **Strings are UTF-8.** Byte functions (`strlen`, `substr`, ...) count bytes as PHP does. A result
  that is not valid UTF-8 appears as U+FFFD.
- **Regular expressions are PCRE's**, translated to JavaScript. Features without an exact
  equivalent fail with a message: recursion, conditional groups, `\K`, backtracking verbs,
  callouts, `(?|`, inline modifiers inside the pattern, and duplicate group names. Without `/u`,
  patterns match bytes.
- **Unicode is the browser's.** Case mapping and character classes follow the browser's Unicode
  version, which can be older than PHP's.
- **Not available in the browser:** `preg_replace()`'s `$count` and `similar_text()`'s
  percentage, `strip_tags()` with allowed tags, `mb_convert_case()` other than upper, lower and
  title, `Str::` methods that are not ported (such as `plural`, `uuid` and `of`), `Str::slug` and
  `Str::ascii` in languages other than `en`, and `htmlentities()` / `html_entity_decode()` with
  `ENT_HTML5`, `ENT_XHTML` or `ENT_XML1`. These fail the build or fail with a message; they never
  give a different result from PHP.
- **`@teleport` targets a named `@portal`**, not a CSS selector, so the server can render it too.
  Each `@portal` is defined once per page.

## Security

A `#[Server]` call carries the component's state, signed with the application key. The endpoint
refuses:

- a changed or foreign state (419);
- methods that are not `#[Server]`, locked or unknown properties, and values or arguments of the
  wrong type (403);
- a visitor who no longer passes the page's authorization middleware (`auth`, `can`, `verified`,
  ...), so a component stops working after logout.

The endpoint runs in the `web` middleware group, with sessions and CSRF protection. See
[ARCHITECTURE.md](ARCHITECTURE.md) for how state, signing and calls work.

## Contributing

```bash
composer install
npm install
composer test     # PHP and Node: parity with PHP, rendering, security, the runtime in a browser
composer analyse  # PHPStan
npm run build     # rebuild dist/fusion.js after changing js/src
```

The feature tests run on Laravel, and on Nitro too when `nitro/framework` is installed. It is a
development dependency loaded from `../nitro-framework`; without that checkout, remove it from
`require-dev`, and the Nitro cases are skipped.

## License

Nitro Fusion is open-source software licensed under the [MIT license](LICENSE.md).
