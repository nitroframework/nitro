# Fusion

Livewire-style components (a PHP class and a Blade view) that run **in the browser**. The server
renders the page with Blade as usual; Fusion compiles the same class and view to JavaScript, so
clicks, typing and re-rendering happen in the page without a request. Methods that need the server
(the database, mail, ...) are marked `#[Server]` and called with the component's signed state.
Rendering is fine-grained: a change updates only the DOM nodes that show what changed (see
Rendering).

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

`fusion:build` prints each step with its time (compiling, with a progress bar, or each component
with `-v`; minifying; publishing). It minifies every chunk in one Node process, and keeps what it
minified: a chunk unchanged since the last build is not minified again.

`fusion:build` also publishes the build to `public/fusion` (`fusion.static`), as `vite build` writes
`public/build`, and `@fusionScripts` points at those files (through `asset()`, so `ASSET_URL` and a
CDN apply): the web server sends them without starting PHP. Their names carry the version
(`app-<version>.js`), and a chunk is named by a hash of its page or group
(`7f3a9c21-<version>.js`), as Vite names its chunks. Until the next build after a change, the
`/_fusion` routes serve the files. Add `/public/fusion` to `.gitignore`, and let the web server
cache it for long (`Cache-Control: public, max-age=31536000, immutable`): a new build has new names.

A build is obfuscated (`fusion.build.obfuscate`, `FUSION_OBFUSCATE`, on by default). Methods,
`#[Server]` methods, properties, pages and components go by aliases derived from `APP_KEY`, in the
bundle, in the page and in what the browser sends: `fusion:name="c8bb6ebd64"`,
`fusion:model="p3f31055.email"`, a `#[Server]` call's method `"md165461"` and its updates, `$errors`
keys, `#[Remember]` keys, `updatedEmail()` as `updatedP3f31055()`. Handlers' text is left out of
the page (`fusion:click=""`; the compiled code is what runs). The server translates every alias
back: your PHP, your views and `assertFusion()` use the real names.

Kept by their names: the keys inside an array property (`p3f31055.email`: data, as JSON field names
are), `mounted()`, `destroyed()` and `updated()`, `#[Url]` query keys, text on the page, URLs, and
components when a view has a `<fusion:dynamic-component>` (it names them as it runs; pages are
aliased either way). Names built as code runs cannot be aliased: write a property or field out
(`$errors->has('form.email')`, `'items.'.$i.'.price'`, `$fusion.note` in `$this->js()`), not put
together from variables. In `updated($property, $value)`, a string naming a property is its alias.
A readable bundle (no build) is never obfuscated; changing `APP_KEY` or the setting makes another
build.

The compiled files hold component templates and the methods that run in the browser, never data:
state, shared data and flash data travel with each page and answer, through Laravel's middleware.
They are public, as any application's JavaScript is, so keep secrets out of views and browser
methods.

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
- **Broadcasts:** `#[On('echo:...')]` listens to Laravel broadcasting through the application's
  Laravel Echo (`window.Echo`, with Reverb or Pusher), as Livewire's does. The component subscribes
  when it appears and stops listening when it leaves the page; the method gets the event's data
  (a `#[Server]` method, as a call).

  ```php
  #[On('echo:orders,OrderPlaced')]                          // a public channel
  public function orderPlaced(array $event): void { $this->orders[] = $event['order']; }

  #[On('echo-private:orders.{order.id},OrderShipped')]      // private; {order.id}: the component's state
  #[Server]
  public function orderShipped(array $event): void { $this->order = Order::find($this->order['id'])->toArray(); }

  #[On('echo-presence:chat,here')]                           // a presence channel: here, joining, leaving
  public function here(array $users): void { $this->online = $users; }

  #[On('echo-notification:App.Models.User.{userId}')]        // notifications
  public function notified(array $notification): void { $this->unread++; }
  ```

  A `{property}` in the channel is read when the component subscribes, and must name a public
  property (the build says so otherwise). A custom event name (`broadcastAs()`) is written with a
  leading dot, as Echo has it: `echo:orders,.order.placed`.
- **Hooks:** `boot()` (every request), `mount()`, `hydrate()` and `dehydrate()` run on the server
  as Livewire's do; `mounted()` runs in the browser once the component is live, `destroyed()` when
  it leaves the page. `$this->js('...')` runs JavaScript after the render, with `$fusion` (the
  component), `$refs` (its `fusion:ref` elements) and `$el`.
- **`#[Url]`** keeps a property in the query string (`as`, `history`, `keep`, `except`): it is
  read before `mount()` and follows the property in the browser.
- **`#[Remember]`** keeps a property in the browser's history entry, as Inertia's `useRemember`:
  back, forward and a reload bring back the value it had there, such as a half-filled form,
  instead of the one the page starts with. Components are told apart by name and position on the
  page. Not for `#[Locked]` properties, models or uploads.
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
| Deferred | `@deferred('stats') ... @placeholder ... @enddeferred` in a page: its content once the deferred properties are loaded, the placeholder meanwhile (see Pages) |
| Laravel | `route()`, `url()`, `asset()`, `__()` / `trans()`, `config()` (keys listed in `fusion.public_config`): the page ships what they name |
| PHP | Operators, casts and PHP's functions as PHP does them (tested against PHP): strings and mbstring, arrays, math, ctype, URLs, HTML entities, `preg_*`, `sprintf`, `json_encode`, ... A bundle has those its components call |
| Str | Laravel's `Str::` helpers (`slug`, `limit`, `title`, `headline`, `camel`, `snake`, `mask`, `is`, `replace`, `squish`, ... 54 of them), tested against Laravel's own |

`@include` and anonymous Blade components (`<x-panel>`) are inlined into the view when it is
compiled. Anything else (`@php`, class-based Blade components, custom directives) cannot render in
the browser and fails the build with the file and line.

## Rendering

Fusion's rendering is fine-grained. When a view is compiled, every dynamic part of it (an echo,
an attribute, an `@if`, a `@foreach`, a child's props) records which properties it reads. When a
property changes, the runtime runs only the parts that read it and changes only their DOM nodes:

```blade
<div>
    <button fusion:click="increment">+</button>
    <p>Clicked {{ $count }} times</p>         {{-- reads $count: renders again when it changes --}}
    <p>Hello, {{ $name }}</p>                  {{-- reads $name: untouched by a click --}}

    @foreach ($students as $student)           {{-- reads $students: untouched by both --}}
        <li>{{ $student['name'] }}</li>
    @endforeach
</div>
```

A click on `+` changes one text node. The component is not rendered again as a whole, and there is
no virtual DOM to compare:

| | What a change runs | What it compares |
| --- | --- | --- |
| Livewire | the component's view, on the server, then a request | the new HTML against the page (morphing) |
| React, Vue | the component's render function | the new virtual DOM against the last one |
| Fusion | the expressions that read the changed property | nothing: each expression updates its own node |

What a part reads is worked out at compile time, through what it calls:

- `{{ $this->total() }}` reads what `total()` reads; a `#[Computed]` method's value is worked out
  once and kept until the component's state changes.
- `$errors`, `$this->shared()` and `$this->flashed()` are read as such: a part that shows a
  validation message renders again when the messages change, and only then.
- A `#[Server]` call's answer changes the properties the server changed; only what reads those
  renders again. The same goes for `$reload`, deferred properties and a parent's new props.
- Keyed lists (`fusion:key`, `:key` on children) keep their rows: a list that changes moves, adds
  and removes rows, and leaves the others as they are.

Reading more than needed only renders more; what a part reads is never missed. A method the
browser does not have (a `#[Server]` method's value) counts as reading everything.

When the browser makes elements itself (a `fusion:navigate` visit, an `@if` that turns true, new
rows of a list), `fusion.rendering` says how. With `'clone'` (the default), each static part of a
view, markup without data, is made once and copied after that; only the parts with data are
created. With `'create'`, every element and attribute is created in turn. The page is the same
either way. The first page load is the server's HTML, taken over as it is, in both.

## Directives

| Attribute | |
| --- | --- |
| `fusion:click="save"`, `fusion:submit.prevent="add"`, `fusion:keydown.enter="search"`, ... | Call a method (or `$set('prop', value)`, `$toggle('prop')`, `$refresh`, `$reload('prop')` and `$loadMore('prop')` in a page, `$dispatch('event')`, `$visit(url)` to go to a page as `fusion:navigate` does, `$post(url, data)` / `$put` / `$patch` / `$delete` to a controller's route). Modifiers: `prevent` `stop` `self` `once` `window` `document` `outside` key names, `debounce.300ms`, `throttle.1s` |
| `fusion:model="email"` | Two-way binding (`.blur`, `.debounce.300ms`, `.number`, `.trim`, `.boolean`); a dotted path binds a key: `fusion:model="form.email"` |
| `fusion:loading` | Shown while a `#[Server]` call runs (`.remove`, `.class="..."`, `.attr="disabled"`, with `fusion:target="save"`) |
| `fusion:key` | Keeps an element's identity in lists |
| `fusion:navigate` | A link to another page, rendered in the browser (see Pages). `.keypress` starts the visit as the mouse button goes down rather than on the click (touch and keyboard keep the click); `.preserve-scroll` keeps the scroll position |
| `fusion:current="active"` | A class while the link points at the current page |
| `fusion:ignore` | Leave this element as it is on re-render |
| `fusion:confirm="Delete it?"` | Ask (the browser's dialog) before the element's handlers run; `.prompt="Type DELETE to confirm\|DELETE"` asks for the word after the last `\|` |
| `fusion:poll.30s="refresh"` | Call a method (browser or `#[Server]`) every interval, 2.5s by default; a bare `fusion:poll` refreshes. It waits while the tab is hidden (`.keep-alive`: it does not), `.visible` polls only while the element is on screen, and it stops when the element leaves the page |
| `fusion:init="load"` | Call a method once, when the element appears |
| `fusion:visible="loadMore"` | Call a method (browser or `#[Server]`) when the element comes into view: `.once` the first time only, `.200px` that far before. While it stays in view, it is called again after each call that changed something, so a "load more" at the end of a list loads until the list fills the screen; wrap it in `@if ($hasMore)` to stop |
| `fusion:ref="name"` | The element as `$refs.name` in `$this->js()` |
| `fusion:transition` | Animate the element as it enters and leaves (see Transitions) |

## Transitions

`fusion:transition` animates an element as it enters (an `@if` that becomes true, a new row of a `@foreach`) and as it leaves. A leaving element stays in the page until its animation ends.

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
| `.out-in`, `.in-out` | Vue's modes, for an element replacing another (`@if` / `@else`, on both): the old one leaves before the new one enters, or after it |
| `.move` | On the rows of a `@foreach`: rows that stay slide to their new places when the list changes (Vue's `<TransitionGroup>`) |

With a name, the classes are Vue's, and the CSS decides what moves and for how long:

```blade
<p fusion:transition="fade">...</p>
```

```css
.fade-enter-active, .fade-leave-active { transition: opacity .3s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
```

Rows of a list animate on their own; a removed row leaves where it was. With `.move`, the rows around it slide into its place as it fades, and a reordered list slides into its new order; with a name, the `name-move` class gives the CSS (`.fade-move { transition: transform .3s; }`).

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

Nothing animates on the server's HTML, in a hidden tab, or for a visitor who asks for reduced motion.

## Browser APIs

`Fusion::browser()` reaches the browser's APIs from a component's browser methods and its event handlers. Calls are checked against the classes in `src/Browser` when the bundle is built, and compiled to the browser's own calls; the bundle includes only the APIs the application uses. On the server, `Fusion::browser()` throws.

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
| `clipboard()` | `write($text, $then)`, `read($then)`: https pages and localhost |
| `storage()`, `session()` | localStorage and sessionStorage: `get($key, $default)`, `set($key, $value)`, `has($key)`, `forget($key)`. Values are stored as JSON |
| `canvas($ref)` | The 2D context of `<canvas fusion:ref="...">`, chained with the context's own names (`fillStyle`, `fillRect`, `arc`, `fillText`, ...). The calls run once the component has rendered. `size($width, $height)` sets the size in CSS pixels, scaled for the screen's density |
| `socket($url)` | A WebSocket, one per URL and component: `onMessage($then)`, `onOpen($then)`, `onClose($then)`, `send($data)`, `close()`. JSON messages arrive as arrays. It reconnects after a drop and closes when the component leaves the page |

Callbacks are closures. A property they change renders as any other change does.

## Islands

A component placed in a Blade view that is not a component's own (a page, a layout) is an island: the rest of the page stays plain HTML. By default an island starts with the page. `fusion:hydrate` starts it later, and `fusion:static` never:

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
| `fusion:hydrate.interaction` | On the first click, focus, key or input in it. The click is given again once the island is live, so its handler runs; what was typed reaches `fusion:model` |
| `fusion:hydrate.media="(query)"` | When the media query matches |
| `fusion:static` | Never: the server's HTML, without state for the browser |

Until it starts, an island is the server's HTML; what is typed in it meanwhile is kept for it. Content between its tags (its slots) is the Blade view's: its HTML goes with the island's state, so the island renders it again in the browser. The components inside it start with it, and `@fusionScripts` does not load their chunks with the page: with `#[LazyLoad]`, a component's code is fetched when its island starts. Inside a component's view, children start with their parent, so `fusion:hydrate` there is a compile error.

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

### Pages that are only a view

A controller's page needs no class at all, as an Inertia page is a controller and a template: the
view declares what it receives, the controller loads it and takes the page's actions.

```php
class NotesController extends Controller
{
    public function index()
    {
        return Fusion::render('notes.index', [          // fusion/pages/notes/index.blade.php
            'notes' => fn () => Note::latest()->get()->toArray(),
            'count' => fn () => Note::count(),
        ]);
    }

    public function store(Request $request)
    {
        Note::create($request->validate(['title' => 'required|min:3']));
        Fusion::flash('toast', 'Added.');

        return back();
    }

    public function destroy(Note $note)
    {
        $note->delete();

        return back();
    }
}
```

```blade
@page(
    title: 'Notes',
    props: ['notes' => [], 'count' => 0],        {{-- from the controller: read-only here --}}
    state: ['form' => ['title' => ''], 'open' => false],   {{-- the page's own --}}
    remember: ['form'],                          {{-- state kept in the history entry --}}
    url: [],                                     {{-- state kept in the query string --}}
)
<div>
    <form fusion:submit.prevent="$post(route('notes.store'), $form, ['reset' => ['form']])">
        <input fusion:model="form.title">
        @error('title') <p>{{ $message }}</p> @enderror
    </form>

    @foreach ($notes as $note)
        <li>{{ $note['title'] }} <button fusion:click="$delete(route('notes.destroy', $note['id']))">x</button></li>
    @endforeach
</div>
```

The view is compiled for the browser as any page's is: it renders there on `fusion:navigate`,
updates fine-grained, and its state changes (`$set`, `$toggle`, `fusion:model`) run there. Its
actions are requests to the controller's routes:

- `$post(url, data, options)`, `$put`, `$patch`, `$delete(url, options)` send `data` as JSON with
  the CSRF token. Laravel's validation errors (a 422) become `$errors`; nothing else changes.
- On success the controller answers `back()` (a `$put`, `$patch` or `$delete` redirect is made a
  303, as Inertia's middleware does), and the page takes its props from it as a partial reload
  does, merge props merged: all of them, or `['only' => [...]]`. Its state stays, except what
  `['reset' => [...]]` puts back to its default (the form, once sent); flash data comes with it.
- A redirect to another page is a `fusion:navigate` visit there.

Fusion makes the page's class from `@page` (in the compiled directory, made again when the view
changes): props are `#[Locked]`, state plain properties, `remember` and `url` `#[Remember]` and
`#[Url]`, `title` `#[Title]`. Defaults are written out (strings, numbers, arrays, `true`, `false`,
`null`). A view page is a view under the pages' view path (`fusion.pages.views`) starting with
`@page`; a page class of the same name takes its place.

A view page is for the common page: lists, filters, forms, modals. When one needs browser
methods, `#[Computed]`, `updated` hooks, `mounted()` or uploads, it grows a page class:

```
php artisan fusion:page-class notes.index
```

That writes `App\Fusion\Pages\Notes\Index` with the view's props and state as typed properties
(and their `#[Locked]`, `#[Remember]`, `#[Url]`, `#[Title]`), and takes `@page` out of the view.
The controller does not change: it renders the page by the same name and takes its actions; the
class holds what runs in the browser.

### Deferred properties

A page can show first and load slow data right after, as Inertia's deferred props. In `mount()`:

```php
public ?array $stats = null;

public function mount(): void
{
    $this->defer('stats', fn () => Order::stats());
}
```

From a controller, the prop names a public property of the page:

```php
return Fusion::render(Overview::class, [
    'orders' => $orders,
    'stats' => Fusion::defer(fn () => Order::stats()),
]);
```

The page is rendered without them, then asks its own URL for them (a partial reload, below): the
route runs again and the closures are called. In the view, `@deferred` shows its content once the
properties are loaded, and `@placeholder` meanwhile. Without names, it waits for all of them.

```blade
@deferred('stats')
    <x-stats :stats="$stats" />
@placeholder
    <p>Loading…</p>
@enddeferred
```

The page can be used while they load; what changes meanwhile is kept. Only pages defer properties.

### Partial reloads

A page can reload some of its properties from its route or controller, as Inertia's partial
reloads: `$this->reload('orders')` in a browser method or after a `#[Server]` call, or
`$reload('orders')` in a handler.

```php
#[Server]
public function add(string $item): void
{
    Order::create(['item' => $item]);
    $this->reload('orders');
}
```

The page asks its URL again (`X-Fusion-Partial`, `X-Fusion-Only: orders`) and takes the named
properties from the answer; the others keep their values in the browser. Without names, every
property takes the value the route gives it, including what was typed. The answer signs the named
properties on their own; the next `#[Server]` call sends them with the snapshot, so reloaded
`#[Locked]` properties reach the server as the server gave them.

A closure prop of `Fusion::render()` is called only when it is needed: on a partial reload, only
the props it names (for pages without `mount()`, whose props set their properties).

```php
return Fusion::render(Orders::class, [
    'orders' => fn () => Order::latest()->get()->toArray(),
    'total' => fn () => Order::sum('amount'),
]);
```

### Merge props

A prop can be merged into the page's value on a partial reload instead of replacing it, as
Inertia's merge props. A first render or a visit sets it as usual.

```php
return Fusion::render(Feed::class, [
    'posts' => Fusion::scroll(fn () => Post::latest()->paginate(20)),
    'tags' => Fusion::merge(fn () => Tag::popular()->get()->toArray())->matchOn('id'),
    'filters' => Fusion::deepMerge(fn () => $this->filters()),
]);
```

- `Fusion::merge()` appends the new items (`->prepend()` puts them first; `->append('data')`
  merges the list at a key). `->matchOn('id')`: an item with the same id replaces the one there.
- `Fusion::deepMerge()` merges arrays key by key, all the way down; lists are appended.
- `Fusion::scroll($paginator)` holds `['data' => items, 'scroll' => [...]]`: the page name and the
  pages around it. `$loadMore('posts')` asks for the next page and appends it, nothing once
  there is none:

```blade
@foreach ($posts['data'] as $post) ... @endforeach

@if ($posts['scroll']['nextPage'] !== null)
    <div fusion:visible="$loadMore('posts')">Loading…</div>
@endif
```

When rows can be added while the list is open, page offsets shift and a page can repeat the last
row of the one before: `Fusion::scroll(...)->matchOn('id')` keeps each row once.

The signed partial reload carries the merge rule: the next `#[Server]` call merges it into the
server's state the same way, so a `#[Locked]` list keeps what was loaded.

A routed page (`Route::fusion()`) does the same in its `mount()`, the property named first:

```php
public array $posts = [];
public array $tags = [];
public array $filters = [];
public int $total = 0;

public function mount(): void
{
    $this->scroll('posts', fn () => Post::latest()->paginate(20));
    $this->merge('tags', fn () => Tag::popular()->get()->toArray())->matchOn('id');
    $this->deepMerge('filters', fn () => $this->defaultFilters());

    if ($this->wants('total')) {
        $this->total = Order::sum('amount');
    }
}
```

A partial reload runs `mount()` again: each closure runs only when the reload wants its property,
and `$this->wants('total')` skips other work the reload does not need (a first visit wants
everything).

### Shared data

`Fusion::share()` gives every component and page the same data, as Inertia's shared props:

```php
// in a middleware, so it is set on every request
Fusion::share('user', fn () => $request->user()?->only('id', 'name'));
Fusion::share('locale', app()->getLocale());
```

```blade
<span>{{ $this->shared('user.name', 'Guest') }}</span>
```

`$this->shared()` reads it with dot notation and a default, in views and in browser methods. The
data is sent with the page, with each `fusion:navigate` visit and with each `#[Server]` call's
answer; what reads it renders again when it changes. Closures are called when the data is read.
Everything shared is sent to the browser: share nothing secret. Set it per request (in a
middleware): under Octane, Fusion is created for each request.

Data that rarely changes can be sent once, as Inertia's once props:

```php
Fusion::shareOnce('countries', fn () => Country::pluck('name', 'code'));
Fusion::share('permissions', Fusion::once(fn () => $user->permissions())->until(3600));
```

The browser keeps it across visits and `#[Server]` calls and says it has it (`X-Fusion-Once`):
the server neither calls the closure for the browser nor sends it again, until it expires
(`->until()`: seconds, an interval or a date) or is sent `->fresh()`. On the server,
`$this->shared('countries')` still calls the closure. Once data is shared at the top level (a key
without dots).

### Flash data

`Fusion::flash()` sends data with the next response only, as Inertia's flash data: a toast after
a save.

```php
Fusion::flash('toast', 'Invoice saved');

return redirect()->route('invoices.index');
```

```blade
@if ($this->flashed('toast'))
    <div class="toast">{{ $this->flashed('toast') }}</div>
@endif
```

It goes with the next page, visit or `#[Server]` answer: this request's, or, after a redirect
(a controller's, or `$this->redirect()` in a `#[Server]` method), the page it goes to. Then it is
gone. It belongs to the page it came with: a visit to another page replaces it, and back does not
bring it again. The browser emits `fusion:flash` with it (`event.detail.flash`). It needs the
session (the `web` middleware).

The first visit is rendered by the server. After that, a `fusion:navigate` link asks the same URL
for the page's data (`X-Fusion-Navigate`): Laravel routes it as usual (middleware, bindings,
redirects), the page runs `mount()` and answers with its signed state and title, and the browser
renders the compiled component in place of the current page. The layout, and the components in
it, stay.

Any other page of the application (a Blade view, a view a controller returns, a page in another
layout) is fetched as HTML and put in place of the current document, as with `wire:navigate`:
still no reload. New stylesheets load before it shows, its new scripts run (one an earlier page
loaded is not loaded again), and its components and islands start as on a page load. A link from
such a page to a Fusion page works the same way. An error, a download, or a page built with
another version of Fusion (after a deploy) loads normally.

A visit scrolls to the top, or to the URL's `#fragment`; `fusion:navigate.preserve-scroll` keeps
the current position. Back and forward return to where each page was left.

Each visit sends the bundle's version (`X-Fusion-Version`). When the application has been
deployed with a new build since the page was loaded, the server answers `409` with
`X-Fusion-Location`, and the browser loads that URL in full, with the new assets.

While a visit loads, a progress bar (nitro-progress) runs at the top of the window: it shows at
once, creeps forward while the server answers, and fills and fades as the new page appears.
`php artisan vendor:publish --tag=nprogress-config` gives `config/nprogress.php`: `show`,
`color`, `height`, `position`, the glow and spinner and their sizes, `delay` (ms before it
appears), the motion (`minimum`, `maximum`, `trickle`, `trickle_speed`, `speed`, `easing`,
`fade_speed`), and `server_calls` to show it during `#[Server]` calls too. The markup is
NProgress's (`#nprogress`, `.bar`), so styles written for it still apply.
The runtime also emits `fusion:request` and `fusion:response` around each `#[Server]` call.

### Testing pages

`assertFusion()` reads the page from a response, whether rendered in full or answered to a
`fusion:navigate` visit, and asserts on its name and state (its public properties, as the browser
receives them). Keys use dot notation.

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

`deferred()` asserts the page leaves the properties for later; `notDeferred()`, that it does not.

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
