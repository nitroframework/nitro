<?php

namespace Nitro\Fusion;

use Closure;
use Illuminate\Support\Facades\Validator;
use Nitro\Fusion\Rendering\Context;
use Nitro\Fusion\Support\PendingDispatch;
use ReflectionClass;
use ReflectionProperty;

/**
 * A Fusion component: a class and a Blade view.
 *
 *   class Counter extends Component
 *   {
 *       public int $count = 0;
 *
 *       public function increment(): void     // compiled to JavaScript, runs in the browser
 *       {
 *           $this->count++;
 *       }
 *
 *       #[Server]
 *       public function save(): void          // runs on the server, called from the browser
 *       {
 *           Counter::create(['count' => $this->count]);
 *       }
 *   }
 *
 * Public properties are the component's state: rendered by the server, sent to the browser,
 * sent back with every #[Server] call. mount() runs on the server only, when the component is
 * created (its parameters come from the route or the parent's tag).
 */
abstract class Component
{
    private ?Context $fusion = null;

    /**
     * Fusion's bookkeeping for this instance (id, name, errors, effects).
     *
     * @internal
     */
    final public function fusion(): Context
    {
        return $this->fusion ??= new Context;
    }

    final public function getId(): string
    {
        return $this->fusion()->id;
    }

    /**
     * $this->total for a #[Computed] total(): worked out once per render (a method run outside a
     * render, a #[Server] one, always gets it afresh).
     */
    final public function __get(string $name): mixed
    {
        if (! app(Fusion::class)->meta(static::class)->isComputed($name)) {
            trigger_error('Undefined property: '.static::class.'::$'.$name, E_USER_WARNING);

            return null;
        }

        $context = $this->fusion();

        if (! $context->rendering) {
            return $this->{$name}();
        }

        if (! array_key_exists($name, $context->computed)) {
            $context->computed[$name] = $this->{$name}();
        }

        return $context->computed[$name];
    }

    final public function __isset(string $name): bool
    {
        return app(Fusion::class)->meta(static::class)->isComputed($name) && $this->__get($name) !== null;
    }

    /**
     * Dispatch an event to the components listening for it (#[On('event')]) and the window; or,
     * as in Livewire, to one: ->to(Dashboard::class), ->self().
     */
    final protected function dispatch(string $event, mixed ...$params): PendingDispatch
    {
        $context = $this->fusion();
        $context->dispatches[] = ['event' => $event, 'params' => $params];

        return new PendingDispatch($context, array_key_last($context->dispatches));
    }

    /**
     * Run JavaScript in the browser once it has rendered the component's new state, as Livewire's
     * $this->js(): $fusion (the component: its properties and methods), $refs (the elements marked
     * fusion:ref="name") and $el (its root element) are in scope.
     *
     *   $this->js('$refs.search.focus()');
     *
     * The code is code: never build it from what a visitor typed.
     */
    final protected function js(string $expression): void
    {
        $this->fusion()->js[] = $expression;
    }

    /**
     * Send the browser to another URL (with fusion:navigate when $navigate is true).
     */
    final protected function redirect(string $url, bool $navigate = false): void
    {
        $this->fusion()->redirect = ['url' => $url, 'navigate' => $navigate];
    }

    /**
     * Set properties back to their declared defaults (all public properties when none are given).
     */
    final protected function reset(string ...$properties): void
    {
        $class = new ReflectionClass($this);

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (($properties === [] || in_array($property->getName(), $properties, true))
                && ! $property->isStatic() && $property->hasDefaultValue()) {
                $property->setValue($this, $property->getDefaultValue());
            }
        }
    }

    /**
     * Validate the public properties (rules keyed by property, dot notation for arrays); with no
     * rules, the #[Validate] rules of every property that has them. Failures reach the browser as
     * $errors, as they would on a form request.
     *
     * @return array<string, mixed> the validated data
     */
    final protected function validate(?array $rules = null, array $messages = [], array $attributes = []): array
    {
        /** No rules given: the #[Validate] rules of every property that has them. */
        if ($rules === null) {
            [$rules, $named] = app(Fusion::class)->meta(static::class)->rulesFor();
            $attributes += $named;
        }

        return Validator::make(State::of($this), $rules, $messages, $attributes)->validate();
    }

    /**
     * Data shared with every component (Fusion::share()), or one value of it in dot notation.
     * In the browser it reads the data the last page, visit or #[Server] call brought.
     */
    final public function shared(?string $key = null, mixed $default = null): mixed
    {
        return app(Fusion::class)->shared($key, $default);
    }

    /**
     * Fusion::flash()'s data for this response, or one value of it in dot notation. In the browser
     * it reads what the last page, visit or #[Server] call brought.
     */
    final public function flashed(?string $key = null, mixed $default = null): mixed
    {
        return app(Fusion::class)->flashed($key, $default);
    }

    /**
     * Load a page property after the page is shown: the browser asks for it right away, and
     * @deferred / @placeholder show something meanwhile. Called in a page's mount().
     *
     *   $this->defer('stats', fn () => Order::stats());
     */
    final protected function defer(string $property, Closure $value): void
    {
        app(Fusion::class)->deferProperty($this, $property, $value);
    }

    /**
     * A page property whose new items are added to the ones the page has on a partial reload,
     * instead of replacing them, as Inertia's merge props. A first render or a visit sets it as
     * usual. The closure runs only when the request wants the property. ->prepend(),
     * ->append('data'), ->matchOn('id').
     *
     *   $this->merge('tags', fn () => Tag::popular()->get()->toArray())->matchOn('id');
     */
    final protected function merge(string $property, mixed $value): Merge
    {
        return app(Fusion::class)->mergeProperty($this, $property, new Merge($value));
    }

    /**
     * As merge(), arrays merged key by key, all the way down (lists appended).
     *
     *   $this->deepMerge('filters', fn () => $this->defaultFilters());
     */
    final protected function deepMerge(string $property, mixed $value): Merge
    {
        return app(Fusion::class)->mergeProperty($this, $property, (new Merge($value))->deep());
    }

    /**
     * A paginator, as a property that loads more as it scrolls: the property holds its items,
     * $loadMore('posts') asks for the next page and appends them, and $this->hasMore('posts')
     * says whether there is one.
     *
     *   $this->scroll('posts', fn () => Post::latest()->paginate(20));
     */
    final protected function scroll(string $property, mixed $paginator): Merge
    {
        return app(Fusion::class)->mergeProperty($this, $property, (new Merge($paginator))->asScroll());
    }

    /**
     * Whether this request needs the page property: always, except on a partial reload of other
     * properties. In mount(), to skip work a partial reload does not need:
     *
     *   if ($this->wants('total')) { $this->total = Order::sum('amount'); }
     */
    final protected function wants(string $property): bool
    {
        return app(Fusion::class)->wants($property);
    }

    /**
     * The page's <head>: title, description, link-preview image, canonical URL, noindex. Set in
     * mount(); what is left unset comes from #[Title], #[Description] and fusion.head.
     *
     *   $this->head()->title("Invoice {$invoice->number}")->description('...');
     */
    final protected function head(): Head
    {
        return $this->fusion()->head ??= new Head;
    }

    /**
     * Whether a scroll prop ($this->scroll()) has a next page to load, in views and methods: a
     * view shows its "load more" row while $this->hasMore('posts') is true.
     */
    final public function hasMore(string $property): bool
    {
        return ($this->fusion()->scroll[$property]['nextPage'] ?? null) !== null;
    }

    /**
     * Reload page properties from the page's route, as Inertia's partial reloads:
     * the page asks its URL again and takes the named properties (all of them when none are
     * named) from the answer. In the browser, or after a #[Server] call.
     *
     *   $this->reload('orders');
     */
    final protected function reload(string ...$properties): void
    {
        $meta = app(Fusion::class)->meta(static::class);

        if (! $meta->page) {
            throw new \InvalidArgumentException("Only pages reload properties: [{$meta->name}] is a component.");
        }

        foreach ($properties as $property) {
            if ($meta->property($property) === null) {
                throw new \InvalidArgumentException("Fusion page [{$meta->name}] has no public property \${$property} to reload.");
            }
        }

        /** Reloading all of them ([]) takes in any reload of some. */
        $current = $this->fusion()->reload;
        $this->fusion()->reload = $properties === [] || $current === [] ? [] : array_values(array_unique([...($current ?? []), ...$properties]));
    }

    /**
     * A routed page (Route::fusion()) is dispatched as a controller: Laravel (and Nitro) resolve
     * mount()'s route parameters, bindings included, and call this.
     *
     * @internal
     */
    final public function callAction(string $method, array $parameters): mixed
    {
        return app(Fusion::class)->page($this, $parameters);
    }
}
