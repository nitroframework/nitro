# Fusion's architecture

How Fusion is put together, and where new code goes. Read this before writing a feature: a
feature is added through one of the seams below, in the layer its job belongs to. The
architecture guards (`tests/Architecture`) hold the code to it.

## The idea

A component is written once (a PHP class and a Blade view, or a Vue, React, Solid or Svelte one)
and runs in two places:

- **the server** renders it, as Laravel renders any view, and runs its `#[Server]` methods;
- **the browser** runs the same class and view compiled to JavaScript: its methods, its
  re-rendering, its event handlers. Only `#[Server]` methods reach the server, with the
  component's state signed by the server.

Fusion does not replace anything Laravel does. Routing, rendering, validation, sessions and
authorization stay Laravel's (or Nitro's, which is Laravel with a compiled request path); Fusion
adds the compiler, the browser runtime and one endpoint. Its four sides agree through one contract,
`js/protocol.json`:

| Side | Where | Does |
|---|---|---|
| Compiler | `src/Compiler` | reads a component class and its view; writes the JavaScript the browser runs |
| Server | the rest of `src/` | renders components to HTML, answers pages, visits and `#[Server]` calls, signs state |
| Runtime | `js/src` (built to `dist/fusion.js`) | makes the server's HTML live, renders, navigates, calls the server |
| Adapters | `js/adapters`, `src/View` | the JavaScript frameworks' views, in the browser and on the server |

## The server's layers

Each folder under `src/` is a layer. A layer uses the layers below it, never one above or beside
it (`DependencyTest`).

```
entry points   Http, Console, Blade, Testing, FusionServiceProvider
Rendering      a component or a page to HTML
State          signed state, shared and flash data, stores
Lifecycle      hooks, features, an instance's Context          } beside each other:
Build          the bundle, chunks, the browser's names         } neither uses the other
View           the view engines
Compiler       ComponentMeta, which properties are state, PHP and templates to JavaScript
Support        the vocabulary every part shares
```

| Folder | Holds |
|---|---|
| `Http` | controllers and middleware; `PageResponses` (a routed page's answer, visits, partial reloads, merge and deferred props) |
| `Console` | the artisan commands |
| `Blade` | the `<fusion:...>` precompiler |
| `Rendering` | `ComponentRenderer`, `Placement` (a component placed in a view), `Tags`, `Portals`, `PageHead` (`@fusionHead`), `Scripts` (`@fusionScripts`) |
| `State` | `Snapshot` / `Snapshots` (state sealed for the browser and opened again), `SharedData`, `Stores` |
| `Lifecycle` | `Features` (runs the hooks), `Context`, `Validation`, `AttributeHandlers` |
| `Build` | `Bundle` and its parts (`BundleCompiler`, `Chunks`, `CompiledFiles`, `StaticFiles`, `Sources`, `Minifier`, `PageRoutes`, `BrowserClassFiles`); `BrowserNames`, `ServerChildren` |
| `View` | `ViewEngine`, `BladeEngine`, `ScriptEngine`, the adapters' server side (`Adapters/`), `ColocatedViews` |
| `Compiler` | `Components` (`ComponentMeta` by name or class), `ComponentCompiler`, `Transpiler`, `Template/`, `Globals`, `Uses`, `Obfuscator` (its parts below) |
| `Support` | only what every part uses and none owns: `Protocol`, `Config`, `Signer`, `Wire`, `Types`, `Html`, `Duration`, `UrlValues`, `LayoutKey`, `Naming`, `Registry`, `ComponentHooks`, `PropertyHooks` (`PlacementTest`) |

**How parts reach each other.** `FusionServiceProvider` builds every part, once per request
(`scoped`), and each part takes what it needs in its constructor. Nothing goes through another
part to reach a third. Two kinds of code resolve their parts where they use them instead
(`app(X::class)`): the controllers, since a route keeps its controller between requests (Nitro
keeps the application in memory), and the public classes an app makes (`Component`, `Upload`,
`PendingDispatch`), which the container does not build. Where a lower layer needs work done by a
higher one, the provider hands it a callable (a client page's head reaches `Bundle` so), and the
lower layer never imports the higher.

**Request state lives in scoped parts.** Under Nitro the application stays in memory: a part that
holds anything about a request is registered `scoped`, never as a singleton.

### The compiler's parts

```
Transpiler        methods, template expressions and handlers (nikic/php-parser AST -> JS):
                  the entry points and expr()/stmt() dispatch, each node sent to a part in Php/
Php/              each part says in its docblock whether it is general PHP or Fusion:
  Statements        if, loops, switch, break/continue levels, unset            (PHP)
  Expressions       values, operators, isset/empty, match, constants          (PHP)
  Writes            assignments with PHP's array value semantics (P.setIn)    (PHP)
  Calls             PHP functions, by-ref functions, Laravel helpers, Str::   (PHP, Laravel)
  FunctionDefinitions  methods, closures, arrow functions, type coercion      (PHP)
  Catalog           what of PHP exists in the browser: functions, constants   (PHP)
  ComponentAccess   $this->prop, $this->method(), #[Server], dispatch,
                    Fusion::browser()                                         (Fusion)
  Handlers          event handlers, magic actions, template-expression rules  (Fusion)
  Context           the state the parts share while compiling one piece of code
Template/         the Blade subset -> a render function (Lexer, TemplateCompiler, ChildTag)
  TemplateCompiler  the front door: the token loop, each token sent to a part in Parts/
  BladeInliner      @include and anonymous <x-components>, inlined into the view
  ComponentParts    an <x-component>'s slots, @props, variables and attribute bag, as Blade makes them
  Parts/            each says in its docblock whether it is HTML, Blade or Fusion:
    HtmlScanner       the markup as the browser's HTML parser reads it      (HTML)
    Tree              the open stack and the nodes it builds                (HTML)
    Directives        each @directive where it belongs                      (Blade)
    Blocks            loops, @switch, @break/@continue, @error, @teleport   (Blade)
    InlinedViews      @include and <x-component> (BladeInliner's markers)   (Blade)
    ExpressionTable   numbered expressions and what each reads              (Fusion)
    FusionAttributes  fusion:click, fusion:model, fusion:transition, ...    (Fusion)
    ChildComponents   <fusion:child />, <fusion:slot>                       (Fusion)
    StaticParts       clone mode: marks static parts for the browser to copy (Fusion)
    Syntax            what a view may contain: directives, the template's rules
    Context           the state the parts share while compiling one view
ComponentReader   reads a class once, checking it as it reads (what cannot work fails the build)
ComponentMeta     what it found: properties, #[Server], #[On], mount, ... and questions about them
ComponentCompiler class + view -> Fusion.define(...), compiling only what the browser reaches
BrowserClassCompiler  the #[Browser] classes and enums the components reach, once for the bundle
Globals           everything a component can use without declaring it, in one table
```

## The public API

What an application's code names (`PublicApiTest`):

- the root namespace: `Component`, `Store`, `Upload`, `Once`, `Merge`, `Head`, `PendingDispatch`,
  `Feature`, `FeatureAttribute`, `NavigationMode`
- `Attributes`, `Browser`, `Concerns`, `Facades`, `Testing`
- the `Fusion` facade: `share`, `shared`, `once`, `shareOnce`, `flash`, `flashed`, `store`,
  `browser`; nothing else
- in the browser, `window.Fusion`: `define`, `store`, `lazy`, `markupPages` (for app.js),
  `directive`, `engine`, `use`, `visit`

A class an app receives or passes through these must be public. Every other folder is internal and
free to change. A method an app must not call is marked `@internal`.

## One home for each concept

A concept is written in one place; everything else reads it from there (`HomesTest`,
`LiteralsTest`). The main ones:

| Concept | Home |
|---|---|
| Names both sides use: attributes, headers, paths, signals, the compiled format | `js/protocol.json` (read by `Support/Protocol`, `runtime/protocol.js`) |
| Component and property hooks | `Support/ComponentHooks`, `Support/PropertyHooks`; run by `Lifecycle/Features`, in the browser by `runtime/component/hooks.js` |
| Typed values from a URL | `Support/UrlValues`, `runtime/url-values.js`, one rule set in the protocol |
| Scalar types | `Support/Types` |
| HTML's element rules | `Support/Html` |
| Durations (`300ms`, `1s`) | `Support/Duration`, `duration()` in `runtime/dom/events.js` |
| Signing | `Support/Signer` (purposes, key rotation) |
| Fusion's config | `Support/Config` |
| What compiled code uses | `Compiler/Uses` |
| Template vocabulary (attributes, modifiers, triggers) | `Compiler/Globals` |
| Fusion's Blade directives (`@teleport`, `@deferred`, ...) | `Compiler/Globals::DIRECTIVES`: each registered for the server and compiled for the browser (`CompiledFormatTest`) |
| What `@fusionScripts` gives the browser (`B.globals`) | `js/protocol.json`'s `globals` (`CompiledFormatTest` holds `Scripts` and the runtime to it) |
| The name of Fusion's own view engine | `js/protocol.json`'s `view.bladeEngine` (`Protocol::bladeEngine()`); a definition Fusion compiled names no engine |
| `<fusion:dynamic-component>` | `Compiler/Template/ChildTag::DYNAMIC` |
| Change signals (`$errors`, `$deferred`, ...) | `js/protocol.json`'s `signals` (`SIGNALS` in `runtime/protocol.js`) |
| A page's data in its HTML: the globals, and one block with each component's signed state by id (as Next.js and Nuxt carry theirs) | `Rendering/PageData` writes it (filled once the page is rendered, `Http/FinishPage`), `runtime/page-data.js` reads it |
| Starting an instance (a component, a page, a store): id and name, `boot`, its inputs, the features' `mounting`, `mount()` | `Lifecycle/Features::start()` |
| State the browser sent: opened, whole, known, still admitted by the page's middleware | `Http/IncomingState` |
| A state by its own names (an obfuscated build's aliases read) | `State/Snapshots::ownNames()` |
| The request being answered, and its session | `Support/CurrentRequest` |
| Whether the application is in development (`APP_DEBUG`) | `Support/Config::debug()` |
| The dev overlay: on or off, its file and script | `Rendering/Devtools`; `Http/ServerTiming` times each answer for it |
| What `@fusionScripts` tells the runtime (endpoint, build, chunks, CSRF token, progress bar) | `Rendering/Scripts` writes it, `runtime/page-data.js` reads it; the CSRF token is `session.token` (`laravel/blade.js`) |
| The browser's names in an obfuscated build, both ways | `Build/BrowserNames` |
| A method's source for the compiler | `Compiler/MethodSource` |
| A component's and a store's state in the browser: defaults, what changed, the `$this` properties | `runtime/component/snapshot.js`, `runtime/component/context.js` |
| Every request the runtime sends (visits, reloads, calls, uploads) | `runtime/server/request.js` |
| Cached results (`#[Cached]`): their keys, the tags' versions, `Fusion::expire()` | `State/MethodCache`; `mount()` and `#[Server]` calls ask it where they run |
| What a component that failed shows (its `fallback()`, `fusion.fallbacks.view`, Fusion's message) | `Rendering/Fallbacks` (placed on the server, a failed call's answer, the browser's default); `fail()` in `runtime/component/component.js` shows it |

## The seams: where a new feature goes

A new feature should be new code in one of these, not edits across the codebase.

| A feature that... | Goes in | Example |
|---|---|---|
| runs around every component on the server | a `Feature`, listed in `fusion.features` | logging every call |
| an attribute switches on, for a class, property or method | a `FeatureAttribute` in `Attributes/` | `#[Url]` |
| the compiler must know about (a property's kind, a class's option) | an attribute in `Attributes/`, read by `ComponentMeta` / `PropertyMeta`; a field in the protocol's `compiled.prop` when the browser needs it | `#[Locked]`, `#[Remember]` |
| is an application's own `fusion:` attribute | `fusion.attributes` and `Fusion.directive()` | a tooltip |
| is a new `fusion:` attribute of Fusion's own | `Compiler/Globals` (what the compiler accepts) and a module under `runtime/dom` (what runs it) | `fusion:dirty` |
| listens to the runtime (calls, renders, stores) | a plugin, `Fusion.use()` (`js/src/plugins`) | `#[Persist]`, `#[Sync]` |
| supports another JavaScript framework's views | a `ViewEngine` (`ScriptEngine`), an adapter in `js/adapters`, `fusion.views` | Svelte |
| is something both sides must name | `js/protocol.json` first, then each side reads it | a new header |
| holds per-request work on the server | a class in its layer, registered `scoped` in `FusionServiceProvider` | `BrowserNames` |
| is something apps call | a method on the `Fusion` facade or `Component` that hands the work to its part | `Fusion::shareOnce()` |

**A mechanism has a home, not only its names.** How something crosses from the server to the
browser (a page's data, a call's answer, an upload) is written in one place on each side, with
everyone else asking it. Names in one place are not enough: when moving the page's state into one
block meant changing seven files that each wrote or read it, the names were all in the protocol
and the mechanism was in none.

**Before writing a feature:** name its seam and list the files it will change. If it fits no seam,
or touches more than a handful of files, stop and discuss it: a seam is missing, and adding that
seam is the real work. The feature itself should then be small.

## The browser

`window.Fusion` is the `Runtime` (`js/src/runtime/runtime.js`): the public API above, the
components on the page, and the services they share. Each job is its own module:

| Folder | Holds |
|---|---|
| `runtime/component` | `Component`; its `$this` for compiled code (`context.js`); the state the server signed, read and taken in (`snapshot.js`); hooks, errors, merge props, shared data, stores, placement (children and components made in the browser), `#[Remember]` |
| `runtime/view` | rendering a compiled template, hydrating the server's HTML, blocks, transitions, view engines (`Fusion.engine()`) |
| `runtime/dom` | events, `fusion:model`, loading, dirty, poll / init / visible, islands |
| `runtime/navigation` | `fusion:navigate` visits (in View Transitions when on), prefetching (`prefetch.js`: on hover, in view), the page cache, swapping and mounting pages, the progress bar |
| `runtime/server` | `#[Server]` calls and reloads, uploads, a call's effects, broadcasts |
| `php.js`, `php/` | PHP semantics in JavaScript: values, operators, the functions |
| `laravel/` | Blade's helpers (`$loop`, `@class`, `@csrf`, ...) and Laravel's (`route()`, `__()`, `config()`) |
| `plugins/` | Fusion's own plugins: `#[Persist]`, `#[Sync]` |
| `devtools/` | the dev overlay (`dist/fusion-devtools.js`, development only): a plugin on the public runtime, in a shadow root |
| `js/adapters` | the frameworks' views given the component (`useFusion()`), imported by the app's Vite |
| `js/vite.js` | Fusion's Vite plugin |
| `js/functions` | PHP functions a bundle includes when its components call them |

`chunks.js` (lazy chunks, markup-first pages), `directives.js`, `plugins.js` and `protocol.js`
sit beside the folders. The runtime has no direction guard yet; its modules reach the runtime
through the instance they are given.

## Faithful to PHP

Compiled code calls `js/src/php.js` for every operator and function, so PHP's rules hold:
loose comparison, arithmetic on numeric strings, truthiness, float printing (`precision=14`),
`round()` as PHP 8.4+ rounds, byte-based string functions, and PHP's ordered arrays.

- **Arrays** are values in PHP. In the browser a list is an `Array`, any other array a `Map`
  (keeping key order and int/string keys), and no write mutates an array: `$this->form['email'] = $v`
  compiles to `$this.form = P.setIn($this.form, ['email'], $v)`. A copy (`$a = $this->items`) can
  never change the original, and every change reaches the top-level property.
- **Names** keep their `$` (`$total`), and PHP functions are `P.fns.name`, so a variable can never
  shadow a function or a JavaScript keyword.
- **The wire format** between PHP and JS is JSON with non-list arrays as `{"$m": [[key, value], ...]}`
  (`Support\Wire`, `php.js encode/decode`), so keys and their order survive.

The guarantee is tested, not assumed: `tests/Parity` runs function and operator cases, a class of
methods covering the language features, values across the wire, and every fixture view in many
states, both in PHP and in Node, and requires the same results, the same state and the same DOM.

## Rendering

The browser does not track dependencies: any write to a property schedules one render (a
microtask), which runs the compiled render function and morphs the result into the page. Child
components are islands (their element is kept; a child without `mount()` receives new props), and
`fusion:ignore` opts an element out. So the output is always what the view says for the current
state; there is no subscription to get wrong.

Children are keyed by their position in the view (`c2`) plus the indexes of the loops around them
(`c2.0.3`), or by `:key`. The Blade precompiler and the template compiler number children with the
same class (`ChildTag`), and the renderer isolates Blade's loop stack per component, so a child has
the same key on both sides.

Whitespace follows Blade exactly (PHP drops a newline after a directive's `?>`), so the server's
HTML and the browser's render are the same, and taking over a server-rendered component changes
nothing but the handler markers.

## Pages and navigation

The server renders the first page and the browser renders the pages after it. Routing stays
Laravel's: a `fusion:navigate` visit requests the page's own URL with `X-Fusion-Navigate`, so the
route's middleware, bindings and redirects all apply. `PageResponses::page()` runs `mount()` and,
for such a visit, answers with JSON instead of HTML (`Vary: X-Fusion-Navigate` keeps caches apart):

- `snapshot`: the page's signed state, exactly as a server-rendered page carries it;
- `title`: the layout's `<title>`, rendered with an empty slot (the layout owns the format);
- `layout`: the layout key (`Support\LayoutKey`); the page element carries the same value in
  `fusion:page`, and a different layout is a full load;
- `children`: the signed state of the children with `mount()`, by id. Only the server may create
  those, with the props its own render passes (props from the browser would reach `mount()`
  unchecked), so when the view places one (`Build\ServerChildren`: the view files, followed
  through children without `mount()`) the server renders the page once to collect them. Children
  without `mount()` are created by the browser.

The browser mounts the page component in place of the current page (`navigation/page-mount.js`),
consuming the children's state as the render places them (`component/placement.js`). A
markup-first or client page (`#[Navigate]`) renders at once, before its data. Back and forward
visit again. Anything else (an error, a 401, HTML from a non-Fusion route) loads normally.

## Security of #[Server] calls

The browser holds `{"state": "<json>", "checksum": "<hmac>"}`: the HMAC (SHA-256, keyed from
`APP_KEY`, the session's secret and the logged-in user's id: `Support\Signer`) covers the exact
bytes of the state, including the component's name, locked properties, model references and the
page it was rendered for. In a session only that session's key is accepted: state signed with the
application key alone (made without a session) is good nowhere else. A call sends the envelope
back unchanged, plus the properties the browser changed and the method with its arguments.
`UpdateController` accepts it only if:

1. the envelope is one this application signed, unchanged (else 419);
2. the component exists;
3. the page's authorization middleware (`fusion.persistent_middleware`, and middleware extending
   it) still admits the visitor (re-run against the original route, with its bindings; a route
   that is gone, matched by a fallback only, is refused), and every partial reload laid over the
   snapshot was rendered for that same page (method and path);
4. every changed property is public, not locked, and has a value of its declared type, without
   coercion; uploads only in an `Upload` property or an `#[Uploads]` array, nowhere else;
5. the method is a `#[Server]` method, and each argument has its parameter's type (class-typed
   parameters come from the container, never from the browser; no argument holds an upload).

Models are never trusted from the browser: the state carries their attributes for display, and the
server reads them again by class and key. The endpoint sits in the `web` group (session, cookies,
CSRF). A redirect goes to http(s) URLs and paths only (`redirect()` on the server, and the runtime
whatever it is sent), so a URL from a visitor cannot run script. Every rule above has a test, on
Laravel and on Nitro (`tests/Feature/ServerCallTest`, `SecurityTest`, `UploadTest`).

## Known limits

- JavaScript has one number type: an integral float of 1e14 or more (`1.0E+14` in PHP) prints as
  an integer in the browser, and a whole float sent to the browser comes back an int.
- For the same reason a float's negative zero (`-0.0`, which PHP prints as `-0`) prints as `0`.
- And `===` (and `match`) cannot tell an int from an integral float: `2 === 2.0` is true in the
  browser, false in PHP.
- After `unset()` on a list, appending in the browser continues from its length, where PHP continues
  from the highest key ever used.
- Browser code cannot call date and time functions or create objects (`new`); the classes and
  enums it uses are marked `#[Browser]` (backed enums for properties). The build says when code
  belongs in a `#[Server]` method instead.
- A child with `mount()` can only be created by the server (it can appear in the server's render,
  not be added later in the browser); children without `mount()` can be created anywhere.
- A view can use `@include` and anonymous Blade components (`<x-...>`), inlined into it; a class
  component runs on the server only, so it cannot render in the browser.
- `$this->dispatch('event', id: 5)` delivers named parameters in order.

## The guards

`tests/Architecture` (local, as all the tests are):

| Guard | Holds |
|---|---|
| `HomesTest` | a concept is written only in its home |
| `LiteralsTest` | a name spelled in 3+ files across 2+ parts is allowed with a reason, or is a known break |
| `DependencyTest` | the layers' direction; Fusion's parts never go through the `Fusion` class |
| `PublicApiTest` | what apps reach is public |
| `PlacementTest` | what `Http`, `Support` and `Rendering` may hold |
| `SizeTest` | over 500 lines or 30 public methods is one job too many |
| `CompiledFormatTest` | the compiler writes only what the protocol lists, and the runtime handles all of it |

`known-breaks.php` lists what still breaks a rule, each with the task that fixes it. It only
shrinks: a new break fails, and a fixed break still listed fails too.
