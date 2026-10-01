# Fusion

Livewire-style components (a PHP class and a Blade view) that run **in the browser**. The server
renders the page with Blade as usual; Fusion compiles the same class and view to JavaScript, so
clicks, typing and re-rendering happen in the page without a request. Methods that need the server
(the database, mail, ...) are marked `#[Server]` and called with the component's signed state.

Fusion works on **Laravel 13** and on **Nitro** (it only uses Laravel's public APIs), and it can
share a page with Livewire: its tags, attributes, scripts and endpoints are all `fusion:`.

```php
// app/Fusion/Components/Counter.php
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;

class Counter extends Component
{
    public int $count = 0;

    public function increment(): void     // compiled to JavaScript: runs in the browser
    {
        $this->count++;
    }

    #[Server]
    public function save(): void          // runs on the server, called from the browser
    {
        Score::create(['value' => $this->count]);
        $this->dispatch('saved');
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
{{-- any view --}}
<fusion:counter />
@fusionScripts
```

## Requirements

- PHP 8.3 or higher
- Laravel 13, or Nitro
- Node.js and esbuild (`npm install --save-dev esbuild`), to minify the compiled components

Fusion's browser versions of Laravel's helpers (`Str::`, `route()`, `__()`) follow Laravel 13's
behavior, so earlier Laravel versions are not supported.

## Installing

```bash
composer require nitro/fusion
php artisan vendor:publish --tag=fusion-config   # optional
```

Put `@fusionScripts` before `</body>` in your layout.

Fusion separates **components** from **pages**, as Livewire 4 does:

| | Components | Pages |
| --- | --- | --- |
| Class | `app/Fusion/Components` | `app/Fusion/Pages` |
| View | `resources/views/fusion/components` | `resources/views/fusion/pages` |
| Name | `students.table` (`App\Fusion\Components\Students\Table`) | `pages::students.index` (`App\Fusion\Pages\Students\Index`) |
| Used | placed in views: `<fusion:students.table />` | routed with `Route::fusion()` or returned by a controller with `Fusion::render()` |
| Layout and title | no | `#[Layout]`, `#[Title]` |

A page cannot be placed in a view, a component cannot be routed or rendered by a controller, and
`#[Layout]` or `#[Title]` on a component fails: each says what to do instead. The directories,
namespaces and view paths are set in `config/fusion.php`.

Components compile to `/_fusion/app.js`, written to `storage/framework/fusion`. Each page is a
chunk of its own (`/_fusion/app/<page>.js`) loaded when the page is first visited, however it is
reached; `#[LazyLoad(false)]` keeps a page in `app.js`, and `#[LazyLoad('group')]` puts pages (or
components) that go together in one chunk. When a component changes, the next request compiles them again, minified with
esbuild (`npm install --save-dev esbuild`); without esbuild, or with
`FUSION_MINIFY_ON_CHANGE=false`, the output is readable. `php artisan fusion:build` builds
everything ahead of time, like `vite build`: run it when deploying so no visitor waits for the
compile. `optimize` does not run it, and `APP_DEBUG` has no say in it. `php artisan fusion:clear`
removes the compiled files.

## Components

- **Public properties** are the state: `null`, `bool`, `int`, `float`, `string`, `array`, an
  Eloquent model or an Eloquent collection. Models reach the browser as their visible attributes
  and are always read again on the server.
- **`mount(...)`** runs on the server only, when the component is created: its parameters come
  from the route (with route-model binding) or from the tag (`<fusion:student-card :student="$s" />`).
- **Methods** run in the browser. The compiler follows what the view uses: a method the browser
  never reaches is never compiled, so server-only helpers are fine.
- **`#[Server]` methods** run on the server; their arguments are checked against their types,
  and class-typed parameters come from the container.
- **`#[Computed]`** methods are read as properties: `$this->total` in the view or a method calls
  `total()` once and keeps the result until the state changes (in the browser) or for the rest of
  the render (on the server). They run in the browser, take no parameters and are not state.
- **`#[Locked]`** properties can be read in the browser but only changed on the server.
- **`updatedEmail($value)`** runs after `fusion:model` or `$set` changes `$email`, and
  **`updated($property, $value)`** after any such change (in the browser, like any method).
- **Tables:** `use WithPagination, WithSorting;` (`Nitro\Fusion\Concerns`). `$this->paginate($rows,
  12)` gives one page (`items`, `page`, `pages`, `total`, `from`, `to`) with `gotoPage()`,
  `previousPage()`, `nextPage()` and `resetPage()`; `$this->sorted($rows)` with `sortBy('name')` and
  `sortIcon('name')` sorts it. Reset the page when a filter changes, in `updated()`.
- **`#[On('event')]`** listens for `$this->dispatch('event', ...)` from any component;
  `->to(Other::class)` (or `->to('other')`) sends it to those components only, `->self()` to this
  one. In a view: `$dispatchTo('other', 'event')`, `$dispatchSelf('event')`, and `$parent->save()`
  calls the parent's method.
- **Hooks:** `boot()` (every request), `mount()`, `hydrate()` and `dehydrate()` run on the server
  as Livewire's do; `mounted()` runs in the browser once the component is live, `destroyed()` when
  it leaves the page. `$this->js('...')` runs JavaScript after the render, with `$fusion` (the
  component), `$refs` (its `fusion:ref` elements) and `$el`.
- **`#[Url]`** keeps a property in the query string (`as`, `history`, `keep`, `except`): it is
  read before `mount()` and follows the property in the browser.
- **`#[Modelable]`** on one property lets a parent bind it: `<fusion:search-box fusion:model="query" />`.
- `$this->reset('prop')`, `$this->redirect($url)` and, on the server, `$this->validate([...])`
  (failures show up as `$errors`).

## Views

A component's view is Blade, with one root element. It renders on the server through Blade itself
and in the browser through its compiled form, so it uses what both can do:

| | |
| --- | --- |
| Echoes | `{{ }}`, `{!! !!}` |
| Control flow | `@if` `@elseif` `@else` `@unless` `@isset` `@empty` `@foreach` (with `$loop`) `@forelse` `@for` `@while` `@break` `@continue` `@switch` |
| Attributes | `@class` `@style` `@checked` `@selected` `@disabled` `@readonly` `@required` |
| Other | `@error` / `$errors`, `@csrf`, `@method`, `@json` |
| Children | `<fusion:task-item :task="$task" :key="$task['id']" />`; `<fusion:dynamic-component :is="$name" />` renders the component `$name` names (another one when it changes, none for `''`) |
| Slots | `<fusion:card>...<fusion:slot name="footer">...</fusion:slot></fusion:card>`; the card's view puts `{{ $slot }}`, `{{ $slots['footer'] }}` (`$slots->has('footer')`, `$slot->isEmpty()`). The content is the parent's: its state, variables and handlers |
| Teleport | `@teleport('modals') ... @endteleport` renders at `@portal('modals')` in the layout (Viewi's portals), on the server and in the browser |
| Laravel | `route()`, `url()`, `asset()`, `__()` / `trans()`, `config()` (keys listed in `fusion.public_config`): the page ships what they name |
| PHP | Operators, casts and PHP's functions as PHP does them (tested against PHP): strings and mbstring, arrays, math, ctype, URLs, HTML entities, `preg_*`, `sprintf`, `json_encode`, ... A bundle has those its components call |
| Str | Laravel's `Str::` helpers (`slug`, `limit`, `title`, `headline`, `camel`, `snake`, `mask`, `is`, `replace`, `squish`, ... 54 of them), tested against Laravel's own |

Anything else (`@include`, `@php`, `<x-...>`, custom directives) cannot render in the browser and
fails the build with the file and line.

## Directives

| Attribute | |
| --- | --- |
| `fusion:click="save"`, `fusion:submit.prevent="add"`, `fusion:keydown.enter="search"`, ... | Call a method (or `$set('prop', value)`, `$toggle('prop')`, `$refresh`, `$dispatch('event')`). Modifiers: `prevent` `stop` `self` `once` `window` `document` `outside` key names, `debounce.300ms`, `throttle.1s` |
| `fusion:model="email"` | Two-way binding (`.blur`, `.debounce.300ms`, `.number`, `.trim`, `.boolean`); a dotted path binds a key: `fusion:model="form.email"` |
| `fusion:loading` | Shown while a `#[Server]` call runs (`.remove`, `.class="..."`, `.attr="disabled"`, with `fusion:target="save"`) |
| `fusion:key` | Keeps an element's identity in lists |
| `fusion:navigate` | A link to another page, rendered in the browser (see Pages). `.keypress` starts the visit as the mouse button goes down rather than on the click (touch and keyboard keep the click) |
| `fusion:current="active"` | A class while the link points at the current page |
| `fusion:ignore` | Leave this element as it is on re-render |
| `fusion:confirm="Delete it?"` | Ask (the browser's dialog) before the element's handlers run; `.prompt="Type DELETE to confirm\|DELETE"` asks for the word after the last `\|` |
| `fusion:poll.30s="refresh"` | Call a method (browser or `#[Server]`) every interval, 2.5s by default; a bare `fusion:poll` refreshes. It waits while the tab is hidden (`.keep-alive`: it does not), `.visible` polls only while the element is on screen, and it stops when the element leaves the page |
| `fusion:init="load"` | Call a method once, when the element appears |
| `fusion:ref="name"` | The element as `$refs.name` in `$this->js()` |

## Uploads

```php
use Nitro\Fusion\Upload;

class CakeForm extends Component
{
    public ?Upload $photo = null;     // one file
    public array $photos = [];        // several: <input type="file" multiple>

    #[Server]
    public function save(): void
    {
        $this->validate(['photo' => 'required|image|max:2048']);
        $path = $this->photo->store('cakes', 'public');
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

Choosing files uploads them at once (`POST {endpoint}/upload`, with the component's signed state, so
only for a page the visitor may still see) into `fusion.uploads.directory`, each checked against
`fusion.uploads.rules`; the property becomes an `Upload`, an `UploadedFile`, so validation rules and
`store()` work as usual. The browser holds only a signed reference to the file. In views,
`temporaryUrl()` (images only), `getClientOriginalName()`, `getClientOriginalExtension()`,
`getSize()` and `getMimeType()` work on both sides. The input fires `fusion-upload-start`,
`fusion-upload-progress` (`detail.progress`), `fusion-upload-finish` and `fusion-upload-error`; a
file the rules refuse gives the property's `$errors` message. Uploads no component stored are
removed after a day.

## Pages

A page is routed by its class or its name:

```php
use App\Fusion\Pages\Students\Show;

Route::fusion('/students/{student}', Show::class)->name('students.show');
Route::fusion('/students', 'pages::students.index')->name('students.index');
```

The page's `mount(Student $student)` binds like a controller method; the page renders in
`components.layouts.app` (or `#[Layout('...')]`) as its `$slot`, with `#[Title('...')]` as `$title`.

### Pages from controllers

A controller returns a page with `Fusion::render()`, as it would return `Inertia::render()`:

```php
use App\Fusion\Pages\Students\Index;
use Nitro\Fusion\Facades\Fusion;

class StudentController extends Controller
{
    public function index()
    {
        return Fusion::render(Index::class, [
            'students' => Student::orderBy('name')->get(),
        ]);
    }
}
```

The props are the arguments of the page's `mount()`, by name (with other parameters still resolved
from the container), or, when it has no `mount()`, its public properties. The page then answers
exactly as a `Route::fusion()` page: in its layout on the first visit, as page data for
`fusion:navigate` visits.

### Shared data

`Fusion::share()` gives every component and page the same data, as Inertia's shared props:

```php
// in a middleware, so it is set on every request
Fusion::share('user', fn () => $request->user()?->only('id', 'name'));
Fusion::share('flash', fn () => session('status'));
```

```blade
<span>{{ $this->shared('user.name', 'Guest') }}</span>
```

`$this->shared()` reads it with dot notation and a default, in views and in browser methods. The
data is sent with the page, with each `fusion:navigate` visit and with each `#[Server]` call's
answer; what reads it renders again when it changes. Closures are called when the data is read.
Everything shared is sent to the browser: share nothing secret. Set it per request (in a
middleware): under Octane, Fusion is created for each request.

The first visit is rendered by the server. After that, a `fusion:navigate` link asks the same URL
for the page's data (`X-Fusion-Navigate`): Laravel routes it as usual (middleware, bindings,
redirects), the page runs `mount()` and answers with its signed state and title, and the browser
renders the compiled component in place of the current page. The layout, and the components in
it, stay. A page in another layout, or a URL that is not a Fusion page, loads normally.

While a visit loads, an [NProgress](https://ricostacruz.com/nprogress) bar runs at the top of the
window. `php artisan vendor:publish --tag=nprogress-config` gives `config/nprogress.php`: `show`,
`color`, `height`, `position`, the glow and spinner and their sizes, `delay` (ms before it
appears), NProgress's motion options, and `server_calls` to show it during `#[Server]` calls too.
The runtime also emits `fusion:request` and `fusion:response` around each `#[Server]` call.

## Caveats

Fusion is browser-first: methods run in the browser, and only `#[Server]` methods reach the
server. What that means in practice:

- **Server work goes in `#[Server]` methods.** Queries, other services, `Auth`, `Cache`, files,
  mail and the clock (`now()`, `date()`) are the server's. A browser method that uses them fails
  the build, with the file and line.
- **A component with `mount()` is created by the server.** The browser can't create one, so it
  reaches the page through a server render: the first request, a `fusion:navigate` visit, or a
  `#[Server]` call. A `<fusion:dynamic-component>` naming such a component needs one of those too.
- **Numbers are JavaScript's.** Integers are exact up to 2^53 (`PHP_INT_MAX` is 2^53-1 in the
  browser), and a float that is whole reaches the browser as an int. The C library's
  transcendental functions (`tanh`, `atanh`, ...) may differ from the browser's in the last digit,
  as they do between PHP builds.
- **Strings are UTF-8 text.** Byte functions (`strlen`, `substr`, `strrev`, ...) count bytes as PHP
  does. A result that is not valid UTF-8 (`chr(200)`, half of a character) reaches the page as
  U+FFFD, as the server sends it.
- **Regular expressions are PCRE's.** `preg_match` (with `$matches`), `preg_match_all`,
  `preg_replace`, `preg_replace_callback`, `preg_split`, `preg_grep` and `preg_quote` translate
  the pattern to what JavaScript can do. PCRE's features without a faithful equivalent fail with a
  message: recursion, conditional groups, `\K`, backtracking verbs, callouts, `(?|`, inline
  modifiers inside the pattern, and duplicate group names. Without `/u`, patterns match bytes,
  as PCRE's do.
- **Unicode is the browser's.** Case mapping (`mb_strtoupper`, `Str::title`, ...) and character
  classes follow the browser's Unicode version. PHP's mbstring may be on a later one, with a few
  new characters and case pairs.
- **Not in the browser:** `preg_replace()`'s `$count` and `similar_text()`'s percentage (both
  references), `strip_tags()` with allowed tags, `mb_convert_case()` other than upper, lower and
  title, `Str::` methods other than the ported ones (such as `plural`, `uuid` and `of`), `Str::slug`
  and `Str::ascii` in other languages than `en`, and `htmlentities()` / `html_entity_decode()` with
  `ENT_HTML5`, `ENT_XHTML` or `ENT_XML1` (their entity tables). These fail the build, or fail with a
  message in the browser, rather than differ from PHP.
- **`@teleport` goes to a named `@portal`**, not a CSS selector: the server renders the content
  there too. A `@portal` is defined once per page. A `@teleport` without its `@portal` renders
  nothing, and the browser says so in the console.
- **Slot content is the parent's.** A slot's handlers and `fusion:model` act on the parent, and a
  slot's content hydrates with the child that places it.

## Security

A `#[Server]` call carries the component's state signed with the application key. The endpoint
refuses a changed or foreign state (419), methods that are not `#[Server]`, locked or unknown
properties, values and arguments of the wrong type (403), and re-checks the page's
authorization middleware (`auth`, `can`, `verified`, ...), so a component stops working for a
visitor who lost access to its page. The endpoint is in the `web` middleware group (sessions,
CSRF). See [ARCHITECTURE.md](ARCHITECTURE.md).

## Testing

```bash
composer install
npm install
composer test     # PHP and Node: parity, rendering, security, the runtime in a browser
composer analyse  # PHPStan
npm run build     # rebuild dist/fusion.js after changing js/src
```

The feature tests run on Laravel, and on Nitro too when `nitro/framework` is installed. It is a
development dependency loaded from `../nitro-framework`; without that checkout, remove it from
`require-dev` to install, and the Nitro cases are skipped.
