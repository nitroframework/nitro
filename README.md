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
- [Stores](#stores)
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
- [How Fusion is tested](#how-fusion-is-tested)
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

Add `@fusionHead` to your layout's `<head>` and `@fusionScripts` before `</body>`:

```blade
{{-- resources/views/components/layouts/app.blade.php --}}
<html>
<head>
    @fusionHead
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

In a plain Blade view or a layout, `@fusion('counter', ['start' => 5])` renders a component from
PHP, as `<fusion:counter :start="5" />` does. A `<fusion:...>` tag written in PHP (an `@php` block,
`<?php ?>`, an echo or a string in them), in a Blade comment or in `@verbatim` is text, not a
component.

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
attributes and are read again from the database on each `#[Server]` call, never taken from the
browser:

- a collection keeps its order, each model of its own class (a polymorphic relation's too), and
  leaves out rows deleted since;
- a single model whose row was deleted is `null` when the property is nullable
  (`public ?Post $post`), so the component can tell and the view can show it; a property that
  is not nullable answers 404.

| Attribute | |
| --- | --- |
| `#[Locked]` | Readable in the browser, changed only on the server |
| `#[Url]` | Kept in the query string (`as`, `history`, `keep`, `except`); read before `mount()` and kept in step with the property |
| `#[Remember]` | Kept in the browser's history entry: back, forward and a reload bring back its value, such as a half-filled form. Not for `#[Locked]` properties, models or uploads |
| `#[Modelable]` | On one property, lets a parent bind it: `<fusion:search-box fusion:model="query" />` |
| `#[Validate]` | Rules checked on the server whenever the browser changes the property: `#[Validate('integer\|min:1\|max:10')]`; for an array's keys, `#[Validate(['tags' => 'array\|max:3', 'tags.*' => 'string'])]`; `as:` names it in messages |

**What the browser can change.** Every public property that is not `#[Locked]` can be set by the
browser to **any value of its type**: anyone can send a request without the page, so
`$quantity` can arrive as `-500`. `updated*()` hooks run in the browser and protect nothing on the
server. The server checks the type, and the `#[Validate]` rules of what changed before a
`#[Server]` method runs: when one fails, the method does not run and the messages are in
`$errors`.

```php
#[Validate('integer|min:1|max:10', as: 'quantity')]
public int $quantity = 1;

#[Server]
public function checkout(): void
{
    // $quantity is between 1 and 10 here
}
```

Put a rule on every property a `#[Server]` method relies on, or validate in the method itself.
`$this->validate()` with no rules checks every property's `#[Validate]` rules, for example
before saving a form.

Pages also take `#[Title]`, `#[Description]`, `#[Layout]`, `#[LazyLoad]` and `#[Navigate]` (see
[Pages](#pages) and [Navigation](#navigation)).

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
- **`#[Server]` methods** run on the server. Their arguments are checked against their types:
  - a **model** parameter receives the record its key names, as route model binding resolves one
    (its route key, or its own `resolveRouteBinding()`): `fusion:click="remove($post['id'])"` gives
    `remove(Post $post)` the post. The row itself works too: `remove($post)`. A missing record
    answers 404; a nullable `?Post` takes `null`;
  - a **backed enum** parameter receives the case its value names; another value answers 403;
  - any other class is resolved from the container and takes nothing from the browser.

  Resolving a record is not authorizing it: the key comes from the browser, so check that the
  visitor may act on it (`Gate::authorize('delete', $post)` with a policy, a query scoped to them).
  A parameter the browser can never fill, such as a variadic model, an abstract model or a pure
  enum, fails the build.
- **`#[Computed]` methods** are read as properties (`$this->total`). The value is worked out once
  and kept until the state changes. They take no parameters and are not part of the state.
- **`$this->validate([...])`** in a `#[Server]` method: failures appear as `$errors`.
- **`$this->reset('prop', ...)`** puts properties back to their defaults; **`$this->redirect($url)`**
  goes to another page.

### Browser classes

Code several components share, such as formatting money or a pricing rule, goes in a class of its
own marked `#[Browser]`. Browser methods and views can call its static methods and read its
constants:

```php
use Nitro\Fusion\Attributes\Browser;

#[Browser]
final class Money
{
    public const CURRENCY = '£';

    public static function format(int $pence): string
    {
        return self::CURRENCY.number_format($pence / 100, 2);
    }
}
```

```php
public function label(): string                          // a browser method
{
    return Money::format($this->total);
}
```

```blade
@use('App\Support\Money')

<p>{{ Money::format($price) }}</p>
```

- **Compiled under the same rules** as a component's browser methods: no database, no clock, no
  server. A method that breaks one fails the build, with its file and line.
- **Only what the browser reaches** is compiled: the methods browser code calls, and what those
  call in turn (`self::`, other `#[Browser]` classes). Private methods and constants work inside
  their class.
- **Constants** travel as their values.
- **On the server**, it is an ordinary PHP class: `#[Server]` methods, controllers and jobs use it
  as they would any other.
- **In views**, `@use` imports a class as in any Blade view: `@use('App\Support\Money')`, with an
  alias (`@use('App\Support\Money', 'Cash')`) or a group (`@use('App\Enums\{Status, Priority}')`).
  It applies to the whole view, as a PHP `use` statement does; an included view's applies too.
  The full name (`\App\Support\Money::format(...)`) works without it.
- **Not yet supported:** instances (`new Money(...)`) and static properties.

#### Enums

An enum marked `#[Browser]` works in the browser as in PHP: its cases, `cases()`, `from()`,
`tryFrom()`, its methods and its static methods. A component property can hold one.

```php
#[Browser]
enum Status: string
{
    case Draft = 'draft';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Paid => 'Paid',
        };
    }
}

class Order extends Component
{
    public Status $status = Status::Draft;

    public function pay(): void                           // browser
    {
        $this->status = Status::Paid;
    }
}
```

```blade
@use('App\Enums\Status')

<select fusion:model="status">
    @foreach (Status::cases() as $case)
        <option value="{{ $case->value }}">{{ $case->label() }}</option>
    @endforeach
</select>
<p>{{ $status->label() }}</p>
```

- **A case is one object** in the browser, so `===`, `match` and `in_array(..., true)` compare cases
  as PHP does, and `->name` and `->value` read as in PHP.
- **An enum property** travels as its value: `fusion:model` sets it from an `<option>`'s value, and
  the server makes the case of it. The browser may set only one of its cases' values, of its
  backing type; any other value is refused (403). It must be a backed enum marked `#[Browser]`.
  `#[Url]` and `#[Remember]` work on it.
- **Methods called on a value** (`$case->label()`) are compiled for the `#[Browser]` enums the code
  uses. A method no such enum has fails the build: in the browser, data from the server arrives as
  arrays, so only an enum case has methods.

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

### Features

A feature runs around every component on the server, where one component's hooks cannot: an audit
of every `#[Server]` call, a tenant set on every component, a check before any method runs. Extend
`Nitro\Fusion\Feature`, override the hooks it needs, and list it in `config/fusion.php`:

```php
use Nitro\Fusion\Component;
use Nitro\Fusion\Feature;

class AuditCalls extends Feature
{
    public function __construct(private readonly Request $request) {}

    public function called(Component $component, string $method, mixed $result): void
    {
        Log::info('fusion call', ['component' => $component::class, 'method' => $method, 'ip' => $this->request->ip()]);
    }
}
```

```php
// config/fusion.php
'features' => [AuditCalls::class],
```

| Hook | Runs |
| --- | --- |
| `boot($component)` | every request, when the component is made or restored |
| `mounting($component)` | a new component, before `mount()` (with its tag's props set, when it has no `mount()`) |
| `hydrate($component)` | a `#[Server]` call, once the state is restored |
| `updated($component, $changed)` | a `#[Server]` call, once the browser's changes are set; `false` keeps the method from running |
| `calling($component, $method, $arguments)` | before a `#[Server]` method |
| `called($component, $method, $result)` | after a `#[Server]` method returned |
| `dehydrate($component)` | before the state goes to the browser, once per request |

- **Order:** each hook runs after the component's own method of that name (`boot()`, `hydrate()`,
  `dehydrate()`), and after Fusion's own, `#[Url]` and `#[Validate]`, which are features too:
  `updated()` sees what `#[Validate]` found in `$component->fusion()->errors`.
- **Made by the container** once per request, so a constructor can take services.
- **Stores** run them too: they are components.
- **`updated()`** runs for every feature, even after one returned `false`; an error it adds to
  `$component->fusion()->errors` reaches the browser as a validation message.

#### Attributes that work as features

A feature can also be an attribute, which runs only for the components that have it, where it is
placed. Extend `Nitro\Fusion\FeatureAttribute` (a `Feature`); `$this->target()` is the property or
method it is on:

```php
#[Attribute(Attribute::TARGET_METHOD)]
class Throttle extends FeatureAttribute
{
    public function __construct(public int $perMinute = 10) {}

    public function calling(Component $component, string $method, array $arguments): void
    {
        $key = 'fusion:'.$component::class.':'.$method.':'.request()->ip();
        abort_if(RateLimiter::tooManyAttempts($key, $this->perMinute), 429);
        RateLimiter::hit($key);
    }
}

class Contact extends Component
{
    #[Server]
    #[Throttle(perMinute: 3)]
    public function send(): void { ... }
}
```

- **On a method**, `calling()` and `called()` run for that method only. It must be a `#[Server]`
  method: on a browser method, which never reaches the server, the attribute fails the build.
- **On a property**, `updated()` runs only when the browser changed that property.
- **On the class**, and for the other hooks anywhere, they run always.
- **Order:** attributes run after the component's own hooks and before `#[Validate]` and the
  features listed in `config/fusion.php`, so a property's `updated()` can tidy a value before it
  is validated. Each component gets its own instance, made from the attribute's arguments (not by
  the container). Fusion's `#[Url]` is one.

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

### Your own attributes

An attribute of your own, such as a tooltip or a textarea that grows, is listed in
`config/fusion.php` and run by a directive of the same name in your JavaScript:

```php
// config/fusion.php
'attributes' => ['tooltip'],
```

```js
Fusion.directive('tooltip', ({ el, value, modifiers, component }) => {
    const tip = makeTooltip(el, value, modifiers.includes('top') ? 'top' : 'bottom');
    return () => tip.remove();
});
```

```blade
<button fusion:tooltip.top="Saved {{ $count }} times">Save</button>
```

- **The directive runs** when the element appears, again when the attribute's value or modifiers
  change (the view renders the value, `{{ }}` and all), and not on renders that leave them as they
  are. The function it returns cleans up before it runs again and when the element goes.
- **It gets** `el`, `value` (a string; `@json(...)` in it passes data), `modifiers` (any words:
  `.top`, `.300ms`) and `component`. `component.dispatch(event, ...params)` dispatches an event as
  `$this->dispatch()` does, so `#[On]` methods, browser or `#[Server]`, hear it.
- **Registering:** a script that runs after `@fusionScripts` calls `Fusion.directive()` directly;
  one that runs before it (a module in the `<head>`) registers in a `fusion:starting` listener on
  `document`, which fires before any component starts. A directive registered later still runs on
  the elements already on the page.
- **An unlisted `fusion:` attribute** fails the build. A name of Fusion's own (`fusion:click`,
  `fusion:model`, ...) cannot be listed.

### Plugins

A plugin hooks into the runtime in the browser. `Fusion.use()` calls it once with the plugin API:

```js
Fusion.use(({ on, directive }) => {
    on('request', ({ headers }) => {
        headers['X-Tenant'] = document.body.dataset.tenant;
    });
    on('store', (store) => {
        store.onChange(() => console.log(store.name, store.get('items')));
    });
});
```

| Hook | Called with |
| --- | --- |
| `component` | a component that started in the page: `{ name, el, dispatch(event, ...params) }` |
| `render` | the same, after each render of the component |
| `store` | a store made in this tab: `{ name, persist, sync, get(property), set(property, value), onChange(listener) }` |
| `request` | before a `#[Server]` call (or a partial reload, as `$reload`): `{ component, method, headers }`; a header added is sent, Fusion's own cannot be changed |
| `response` | when its answer came: `{ component, method, status }` (0: none came) |

- **`on()`** returns a function that removes the listener. A `component` or `store` listener
  hears those already there too, so a plugin can be used after the page started.
- **A store's values** are JSON-safe, as the server sends them (an associative array as
  `{"$m": [[key, value], ...]}`). `set()` is the browser's own change, checked by the server on the
  next call; a `#[Locked]` property refuses it.
- **Names** are the browser's: in an obfuscated build, methods and properties go by their aliases.
- **A listener that throws** is reported (`fusion:error`); the others still run.
- **`directive()`** is `Fusion.directive()` (see [Your own attributes](#your-own-attributes)).
- A plugin used twice runs once. Register plugins as directives are: directly after
  `@fusionScripts`, or in a `fusion:starting` listener.
- `#[Persist]` and `#[Sync]` are Fusion's own plugins, built on this API only.

## Stores

A component's properties are its own. When several components need the same state (a cart a
badge, a product list and a cart page all show), and need it longer than any one of them lives,
put it in a store: a class written like a component, without a view, in `app/Fusion/Stores`.

```php
namespace App\Fusion\Stores;

use Nitro\Fusion\Attributes\Computed;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Store;

class Cart extends Store
{
    public array $items = [];

    public function mount(): void                       // server: the first page that uses it
    {
        $this->items = session('cart', []);
    }

    public function add(array $product): void          // browser
    {
        $this->items[] = $product;
    }

    #[Computed]
    public function count(): int                        // browser, read as $cart->count
    {
        return count($this->items);
    }

    #[Server]
    public function save(): void                        // server, with the store's signed state
    {
        session(['cart' => $this->items]);
    }
}
```

A component uses it through a public property of its type:

```php
class Badge extends Component
{
    public Cart $cart;
}
```

```blade
<span>{{ $cart->count }}</span>
<button fusion:click="$cart->add($product)">Add</button>
```

- **One store, shared.** Every component that uses `Cart` gets the same one: on the server, one
  per request (made with its `mount()`, which renders the first page); in the browser, one per
  tab, kept across `fusion:navigate` visits. A component made later (a drawer opened, a cart page
  reached by a visit) reads it as it is.
- **What reads it renders again.** A change made through the store's methods renders again, in
  every component that uses it, only what reads it, and works out their `#[Computed]` values again.
- **Changed through its methods.** A component reads a store's properties and calls its methods;
  writing to them from outside (`$this->cart->items[] = ...`) fails the build. Methods run in the
  browser; `#[Server]` ones on the server, with the store's signed state (`#[Locked]` and
  `#[Validate]` work as on a component).
- **With a component's server methods.** A component's `#[Server]` method sees the stores it uses
  as the browser had them, and what it changes in them comes back to the browser.
- **Not the component's state.** A store is not part of the component's snapshot: it travels on its
  own, signed for the page it was made for.

A store has no view, is never placed or routed, and cannot use what belongs to a component on the
page (`#[On]`, `#[Url]`, `#[Remember]`, `#[Modelable]`, uploads) or hold another store. A store is
in the browser: like any public property, its state is public, and its `#[Server]` methods check
what they rely on. Classes in `App\Fusion\Stores` are named `stores::cart` (`fusion.stores`).

### Keeping and sharing a store

```php
#[Persist]
class Cart extends Store
{
    #[Sync]
    public array $items = [];

    #[Locked]
    public string $madeBy = '';
}
```

- **`#[Persist]`** keeps the store's values in the browser's localStorage, so they outlast a reload
  and a closed tab. When the store is made, the values kept are set as the browser's own changes.
- **`#[Sync]`** shares them with the site's other open tabs: a change in one is set in the others.
  A tab opened later starts from the server's state, or with `#[Persist]` from the values kept.
- **On the store**, they cover every property the browser can change; **on a property**, that one.
  A `#[Locked]` property is the server's: it is left out, and either attribute on it fails the build.
  They are for stores only: a component's state is its own.
- **Checked like any change.** The browser holds these values, so a visitor can change them: the
  server checks them on the next call (their type, `#[Locked]`, `#[Validate]`) as it checks every
  change from the browser. Read what must be true (prices, stock) from the database in `#[Server]`
  methods.
- Without localStorage (blocked, a private window) or BroadcastChannel, the store works as without
  them. Both are Fusion's own plugins (see [Plugins](#plugins)).

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

### The page's head

Every Fusion page is rendered on the server on its first load, so search engines and link
previews always get complete HTML. `@fusionHead` in the layout prints the page's `<title>` and
the tags they read; a page sets only what it needs, and the rest comes from defaults.

```php
// config/fusion.php: for every page
'head' => [
    'title' => '%s · Campus Admin',      // the page's title in place of %s
    'description' => 'School administration',
    'image' => 'https://campus.test/img/share.png',
],
```

A page with fixed text uses attributes; a page whose head depends on its data sets it in
`mount()`:

```php
#[Title('Pricing')]
#[Description('Plans for every school size')]
class Pricing extends Component
```

```php
public function mount(Invoice $invoice): void
{
    $this->head()
        ->title("Invoice {$invoice->number}")
        ->description("Invoice for {$invoice->student->name}")
        ->image(asset('img/invoice.png'))
        ->canonical(route('invoices.show', $invoice))
        ->noindex();
}
```

| | Prints |
| --- | --- |
| `title()`, `#[Title]` | `<title>` in the `head.title` format, and `og:title` |
| `description()`, `#[Description]` | `<meta name="description">` and `og:description` |
| `image()` | `og:image` and a large Twitter card |
| `canonical()` | `<link rel="canonical">` |
| `noindex()` | `<meta name="robots" content="noindex">` |
| `meta($name, $content)` | any other `<meta name>` |

A `fusion:navigate` visit brings the new page's head with its data, and the browser puts it in
place of the last page's. Pages behind a login rarely need more than a title; the head matters for
public pages people search for and share. A layout that writes its own `<title>{{ $title }}</title>`
keeps working: `$title` is the page's title.

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

### How a visit shows a page

A page chooses how a `fusion:navigate` visit shows it with `#[Navigate]`. Two questions decide
which mode fits:

```
Does the page need the server?
├── No  → client   (appears at once, no request)
└── Yes → when should it appear?
          ├── after its data  → data     (default)
          └── before its data → markup
```

| Mode | The browser | Feels | Suits |
| --- | --- | --- | --- |
| **`data`** (the default) | asks the server for the page's data, then renders the page complete | one server round trip before the page changes; nothing shows half-filled | pages that mean nothing without their data (a student's profile), pages behind `auth` or `can` |
| **`markup`** | renders the page at once with its properties' defaults, then fills it in when the data arrives | instant | dashboards, lists and forms with placeholders where the data goes |
| **`client`** | renders the page at once and does not ask the server at all | instant, no request | pages that need nothing from the server: help, about, legal text, docs |

The first page load is the server's HTML in every mode; the mode only decides what a later
visit does.

### Data first or markup first

Both ask the server; they differ in when the page appears. Set the default in
`config/fusion.php`, and choose per page with `#[Navigate]`:

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

### Client pages

A page that needs nothing from the server (help, about, legal text, docs) can skip it entirely
on a visit:

```php
#[Title('Help')]
#[Navigate('client')]
class Help extends Component
```

The browser renders it from its compiled view the moment the link is clicked, with no request.
Its title and head tags are worked out when the app is built. Its own state and browser methods
work as on any page; back and forward render it again without a request. A reload or a first
visit is still the server's HTML.

A client page cannot have what only the server can do, and the build refuses one that has:

- `mount()`, `#[Server]` methods or `#[Url]` properties;
- route parameters, or middleware beyond the `web` group (`auth`, `can`, `verified`, ...), which
  only the server can check.

Use `#[Navigate('markup')]` for those: it renders at once too, and asks the server for the rest.

### Progress bar

A progress bar runs at the top of the window during a visit. Publish its configuration with
`php artisan vendor:publish --tag=nprogress-config`:

| Option | |
| --- | --- |
| `show` | Show the bar |
| `server_calls` | Show it during `#[Server]` calls too |
| `color`, `height`, `position`, `z_index` | Its look |
| `glow`, `glow_width`, `spinner`, `spinner_size` | The glow at its tip, and a spinner |
| `delay` | Milliseconds before it appears |
| `minimum_duration` | Once shown, it runs at least this long (ms, 250 by default), so a fast visit is seen to complete rather than flash |
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
    #[Uploads]
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
| `stores` | `App\Fusion\Stores` | Namespace and path of stores |
| `layout` | `components.layouts.app` | The layout of pages without `#[Layout]` |
| `head` | `'%s'`, no description or image | `@fusionHead`'s defaults: the title format, the description and the link-preview image |
| `persistent_middleware` | auth, can, verified, ... | Page middleware checked again on each `#[Server]` call |
| `max_payload` | 1 MB | The largest `#[Server]` call accepted |
| `state.bind_to_session` | `true` | Sign state with the session's secret too: good only in its own session |
| `state.lifetime` | `null` | How long a snapshot is good for, in seconds; null: as long as its session |
| `uploads` | | `directory`, `rules`, `max_files`, `throttle` |
| `public_config` | `['app.name']` | Config keys `config()` may read in the browser; their values are public |
| `compiled` | `storage/framework/fusion` | Where compiled files are written |
| `static` | `fusion` | The directory in `public/` for the build |
| `build.obfuscate` | `true` | Obfuscate builds (`FUSION_OBFUSCATE`) |
| `minify_on_change` | `true` | Minify when recompiling during development (`FUSION_MINIFY_ON_CHANGE`) |
| `rendering` | `'clone'` | How the browser creates elements: `'clone'` or `'create'` |
| `navigation` | `'data'` | How a visit shows a page: `'data'` (data first) or `'markup'` (markup first); `#[Navigate]` per page |
| `attributes` | `[]` | Your own `fusion:` attributes, each run by a `Fusion.directive()` (see [Your own attributes](#your-own-attributes)) |
| `features` | `[]` | Classes that run around every component on the server (see [Features](#features)) |
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
- **A component with `mount()` is created by the server**: on the first request, a visit, or a
  `#[Server]` call of the component whose view places it. A change made in the browser alone
  (`$set`, a browser method) cannot place a new one: a `<fusion:dynamic-component>` that switches
  to such a component needs one of those.
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

A `#[Server]` call carries the component's state, signed with the application key, the session's
own secret and the logged-in user. The endpoint refuses:

- a changed state, or one made in another session or for another user: another visitor's, or one
  from before a logout, even an `Auth::logout()` that keeps the session (419);
- a state older than `fusion.state.lifetime`, when one is set (419);
- methods that are not `#[Server]`, locked or unknown properties, and values or arguments of the
  wrong type (403); an upload anywhere but an `Upload` or `#[Uploads]` property (403);
- a visitor who no longer passes the page's authorization middleware (`auth`, `can`, `verified`,
  ..., and an application's own middleware extending them), and a page whose route is gone (403);
- a partial reload rendered for another page than the component's (403).

Upload references are good in the session that uploaded them only, and a redirect goes to http(s)
URLs and paths only (`redirect('javascript:...')` throws; the browser refuses one too).

**What the browser sees.** Every public property is in the page's state, `#[Locked]` ones too, and a
model property carries its `toArray()` (what `$hidden` leaves out is not sent; loaded relations
are). Keep secrets in protected or private properties, or load them in the `#[Server]` method that
needs them.

Within its type, an unlocked property takes any value the browser sends; `#[Validate]` rules on
it are checked before a `#[Server]` method runs (see [Properties](#properties)).

On a 419 the page reloads with fresh state; a `fusion:expired` listener can `preventDefault()` to
do something else.

```php
// config/fusion.php
'state' => [
    'bind_to_session' => true,   // off only for pages cached as whole HTML for every visitor
    'lifetime' => null,          // seconds; set it where an old state must not come back
],
```

Signing keeps the browser from changing a `#[Locked]` value. It does not keep the same visitor
from sending an older state of their own session within its lifetime. `#[Locked]` means
unchangeable, not current: read what must be current (a balance, a step that was paid for, a
permission) from the database in the `#[Server]` method.

A child is created with the props its parent's view passes it, from the parent's state as it is
then. On a `#[Server]` call that state includes what the browser changed, so a child's `mount()`
can receive a value the browser chose, and a `#[Locked]` property it sets from that value is only
as trustworthy as where it came from. Pass children what the parent keeps `#[Locked]`, or check the
value in the child's `mount()`, when the child relies on it.

The endpoint runs in the `web` middleware group, with sessions and CSRF protection. See
[ARCHITECTURE.md](ARCHITECTURE.md) for how state, signing and calls work.

## How Fusion is tested

Fusion runs one component in two places, so it is tested for doing the same thing in both, for
refusing what it must, and for not breaking on input nobody planned for. The test suites are kept
with the framework's source; they are not part of the published package.

| Kind | What it is | What it checks here |
| --- | --- | --- |
| Feature (integration) tests | Requests through the real HTTP kernel of a fixture application, on Laravel and on Nitro | Pages, visits, `#[Server]` calls, validation, uploads, partial reloads, shared and flash data, builds |
| Compiler tests | The compiler fed known PHP and Blade | What compiles, what is refused, and the error it gives |
| Parity tests | The same code run by PHP and, compiled, by the browser's runtime | PHP's functions, `Str::` helpers, methods and templates give the same results |
| End-to-end (e2e) tests | Real pages served by `php -S`, loaded in a browser (jsdom) and driven like a user | Hydration, events, children, navigation, transitions, islands, the progress bar |
| Security tests | Requests made by hand to get what the page would never send | Changed or foreign state, locked properties, methods that are not `#[Server]`, wrong types, CSRF, middleware checked again |
| Forged-request tests | The same, under every spelling: methods and properties renamed, arguments by name, the wire format bent, model properties pointed elsewhere | Each is refused, nothing runs, nothing answers 500 |
| Differential fuzzing | Thousands of random PHP methods, run in PHP and compiled in the browser's runtime | Any difference is a bug, reported with the seed that makes it again |
| Template fuzzing | Random views rendered by Blade and by the browser | The same DOM on both sides |
| Round-trip (property) fuzzing | Random values sent through `#[Server]` calls and back | Every value arrives as it left: types, keys, order |
| Robustness fuzzing | Valid input bent at random: requests to the endpoints, component classes and views, views with `<fusion:...>` tags, the wire format | Bad input is refused cleanly (a 4xx, or a compile error with its file and line); never a crash, a 500 or invalid output |
| Static analysis | PHPStan, level 6 | Types and calls across the PHP source |

```bash
composer test                            # feature, compiler, parity, security and end-to-end tests
composer analyse                         # static analysis
composer fuzz:all -- --seed=1 --scale=1  # every fuzzer; --scale=10 for a long run
composer fuzz -- --seed=1 --count=500    # differential fuzzing alone
```

A fuzzer failure names its seed: run the same fuzzer with that seed to make it again.

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
