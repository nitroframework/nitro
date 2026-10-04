# Fusion: architecture

## The idea

A component is written once (a PHP class and a Blade view) and runs in two places:

- **the server** renders it with Blade, as Laravel renders any view, and runs its `#[Server]`
  methods;
- **the browser** runs the same class and view compiled to JavaScript: its methods, its
  re-rendering, its event handlers.

Fusion does not replace anything Laravel does. Routing, rendering, validation, sessions and
authorization stay Laravel's (or Nitro's, which is Laravel with a compiled request path); Fusion
adds the compiler, the browser runtime and one endpoint.

## Layers

```
src/Compiler      PHP -> JavaScript
  Transpiler        methods, template expressions and handlers (nikic/php-parser AST -> JS):
                    the entry points and expr()/stmt() dispatch, each node sent to a part in Php/
  Php/              the transpiler's parts, each saying in its docblock whether it is general
                    PHP or Fusion:
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
    Parts/            each saying in its docblock whether it is HTML, Blade or Fusion:
      HtmlScanner       the markup as the browser's HTML parser reads it      (HTML)
      Tree              the open stack and the nodes it builds                (HTML)
      Directives        each @directive where it belongs                      (Blade)
      Blocks            loops, @switch, @break/@continue, @error, @teleport   (Blade)
      InlinedViews      @include and <x-component> (BladeInliner's markers)   (Blade)
      ExpressionTable   numbered expressions and what each reads              (Fusion)
      FusionAttributes  fusion:click, fusion:model, fusion:transition, ...    (Fusion)
      ChildComponents   <fusion:child />, <fusion:slot>                       (Fusion)
      StaticParts       clone mode: marks static parts for the browser to copy (Fusion)
      Syntax            what a view may contain: directives, HTML rules
      Context           the state the parts share while compiling one view
  ComponentMeta     what a class declares (properties, #[Server], #[On], mount, ...)
  ComponentCompiler class + view -> Fusion.define(...), compiling only what the browser reaches
  Globals           everything a component can use without declaring it, in one table
src/Rendering     the server side of a component
  Renderer          Blade render with $this bound, isolated loops, the root element marked
  Snapshot          the signed state sent to the browser and verified when it comes back
  Tags              <fusion:...> tags with content and their slots
  Portals           @portal and @teleport
  PageHead          the page's <head> (@fusionHead) and the layout's title
src/Http          the endpoint: UpdateController (#[Server] calls), PersistentMiddleware, assets
  PageResponses     a routed page's answer: HTML, a visit's data, the 409, partial reloads
  Scripts           @fusionScripts and the browser's globals
src/State         SharedData: shared, once and flash data
src/Blade         <fusion:name /> tags in any view (a Blade precompiler)
src/Build         app.js: every component compiled, versioned by its sources
Fusion            the application's entry point: config, registry, components and mounting them,
                  the signing keys, an obfuscated build's names; the parts above behind its methods
js/src/php.js     PHP semantics in JavaScript (values, operators, the functions)
js/src/blade.js   Blade's helpers ($loop, @class, @style, @csrf, ...)
js/src/runtime/   components in the page: state, render + morph, events, models, network,
                  navigation, loading states, $errors
```

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

The guarantee is tested, not assumed: `tests/Parity` runs ~2,000 function and operator cases, a
class of methods covering the language features, and every fixture view in many states both in
PHP and in Node, and requires the same results, the same state and the same DOM.

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

## Pages and navigation (client-side routing)

As in Viewi, the server renders the first page and the browser renders the pages after it. Routing
stays Laravel's: a `fusion:navigate` visit requests the page's own URL with `X-Fusion-Navigate`, so
the route's middleware, bindings and redirects all apply. `Fusion::page()` runs `mount()` and, for
such a visit, answers with JSON instead of HTML (`Vary: X-Fusion-Navigate` keeps caches apart):

- `snapshot`: the page's signed state, exactly as a server-rendered page carries it;
- `title`: the layout's `<title>`, rendered with an empty slot (the layout owns the format);
- `layout`: the layout view (and a hash of its data); the page element carries the same value in
  `fusion:page`, and a different layout is a full load;
- `children`: the signed state of the children with `mount()`, by id. Only the server may create
  those, with the props its own render passes (props from the browser would reach `mount()`
  unchecked), so when the view places one (`hasServerChildren()`: the view files, followed
  through children without `mount()`) the server renders the page once to collect them. Children
  without `mount()` are created by the browser.

The browser renders the page component in place of the current page (`Runtime::mountPage()`),
consuming the children's state as the render places them. Back and forward visit again. Anything
else (an error, a 401, HTML from a non-Fusion route) loads normally.

## Security of #[Server] calls

The browser holds `{"state": "<json>", "checksum": "<hmac>"}`: the HMAC (SHA-256, keyed from
`APP_KEY`) covers the exact bytes of the state, including the component's name, locked
properties, model references and the page it was rendered for. A call sends the envelope back
unchanged, plus the properties the browser changed and the method with its arguments.
`UpdateController` accepts it only if:

1. the envelope is one this application signed, unchanged (else 419);
2. the component exists;
3. the page's authorization middleware (`fusion.persistent_middleware`) still admits the visitor
   (re-run against the original route, with its bindings);
4. every changed property is public, not locked, and has a value of its declared type, without
   coercion;
5. the method is a `#[Server]` method, and each argument has its parameter's type (class-typed
   parameters come from the container, never from the browser).

Models are never trusted from the browser: the state carries their attributes for display, and the
server reads them again by class and key. The endpoint sits in the `web` group (session, cookies,
CSRF). Every rule above has a test, on Laravel and on Nitro (`tests/Feature/ServerCallTest`).

## Known limits

- JavaScript has one number type: an integral float of 1e14 or more (`1.0E+14` in PHP) prints as
  an integer in the browser.
- For the same reason a float's negative zero (`-0.0`, which PHP prints as `-0`) prints as `0`: in
  JavaScript `0 * -1` is `-0` too, where PHP's integers give `0`.
- And `===` (and `match`) cannot tell an int from an integral float: `2 === 2.0` is true in the
  browser, false in PHP.
- After `unset()` on a list, appending in the browser continues from its length, where PHP continues
  from the highest key ever used.
- Browser code cannot call date and time functions, create objects (`new`), make static calls or use
  enums as properties; such code belongs in a `#[Server]` method (the build says so).
- A child with `mount()` can only be created by the server (it can appear in the server's render,
  not be added later in the browser); children without `mount()` can be created anywhere.
- `$this->dispatch('event', id: 5)` delivers named parameters in order.
- Component views cannot use Blade components (`<x-...>`), `@include` or custom directives yet: they
  would render on the server only.
