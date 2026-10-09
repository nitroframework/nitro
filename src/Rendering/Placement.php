<?php

namespace Nitro\Rendering;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nitro\Compiler\ComponentMeta;
use Nitro\Compiler\Components;
use Nitro\Compiler\PropertyMeta;
use Nitro\Compiler\Template\ChildTag;
use Nitro\Component;
use Nitro\Lifecycle\Features;
use Nitro\State\MethodCache;
use Nitro\State\Stores;
use Nitro\Support\ComponentHooks;
use Nitro\Support\Protocol;
use Nitro\Support\Registry;
use Throwable;

/**
 * A component placed in a view and rendered on the server: <nitro:name /> in a page, a layout or
 * another component's view (a child), @nitro, <nitro:dynamic-component>. Made with its mount()
 * or its props, then rendered.
 */
final class Placement
{
    public function __construct(
        private readonly Container $app,
        private readonly ComponentRenderer $renderer,
        private readonly Components $components,
        private readonly Registry $registry,
        private readonly Features $features,
        private readonly Stores $stores,
        private readonly PageData $pageData,
        private readonly MethodCache $cache,
        private readonly Fallbacks $fallbacks,
    ) {
    }

    /**
     * A component placed in a view: <nitro:name /> in a page or layout, or a child in another
     * component's view (keyed as the browser keys it: ChildTag::key()). $hydrate: an island's start
     * (nitro:hydrate, nitro:static), for a component that is not a child.
     *
     * @param  array<string, string>  $slots  name => its HTML
     */
    public function tag(string $name, array $props, mixed $key, int $number, array $slots = [], ?string $hydrate = null): HtmlString
    {
        $parent = $this->renderer->current();

        /** <nitro:dynamic-component :is="$name">: the component :is names (none for ""); its key has the name. */
        $dynamic = $name === ChildTag::DYNAMIC;

        if ($dynamic) {
            if (! array_key_exists('is', $props)) {
                throw new InvalidArgumentException('<nitro:dynamic-component> needs :is, the name of the component to render');
            }

            /** null names none, as "" does (the browser reads it as a string). */
            $is = $props['is'] ?? '';
            unset($props['is']);

            if (! is_string($is)) {
                throw new UnplaceableComponent('<nitro:dynamic-component> :is names a component: a string, not '.get_debug_type($is).'.');
            }

            if ($is === '') {
                return new HtmlString('');
            }

            $name = $is;
            $class = class_exists($name) ? ltrim($name, '\\') : $this->registry->class($name);

            if ($class === null || $this->registry->isPageClass($class)) {
                throw new UnplaceableComponent($class === null
                    ? "<nitro:dynamic-component> :is names [{$name}], which is not a Nitro component."
                    : "[{$name}] is a page: pages are routed (Route::nitro()), not placed in views. Place a component instead.");
            }
        }

        if ($parent === null) {
            return new HtmlString($this->mount($name, $props, $slots, $hydrate));
        }

        if ($hydrate !== null) {
            throw new InvalidArgumentException("nitro:hydrate and nitro:static are for islands, components placed in Blade views: <nitro:{$name}> is a child of [{$parent->nitro()->name}], and starts with it.");
        }

        $key = ($key !== null ? (string) $key : ChildTag::key($number, $this->renderer->loopIndexes())).($dynamic ? ':'.$name : '');

        return new HtmlString($this->mountChild($name, $props, $parent, $key, $slots));
    }

    /**
     * Render a component that is not inside another (in a page, a layout, @nitro).
     *
     * An island that starts later ($hydrate: nitro:hydrate) is marked for the browser, and the
     * chunks of its components are not loaded with the page; a static one (nitro:static) is its
     * HTML only, without state for the browser.
     *
     * @param  array<string, string>  $slots  name => its HTML
     */
    public function mount(string $name, array $props = [], array $slots = [], ?string $hydrate = null): string
    {
        $id = 'f'.Str::lower(Str::random(10));
        $render = fn (): string => $this->guarded($id, function (?Component &$component) use ($name, $id, $props, $slots, $hydrate): string {
            $meta = $this->placeable($name);
            $this->create($meta, $id, $props, $component);
            $component->nitro()->slots = $slots;
            $attributes = $hydrate === null || $hydrate === 'static' ? [] : [Protocol::attribute('hydrate') => $hydrate];
            /** No parent renders its slots in the browser: their HTML goes with its state. */
            $memo = $this->renderer->pageMemo() + (array_filter($slots, fn (string $html) => trim($html) !== '') === [] ? [] : ['slots' => $slots]);

            return $this->renderer->render($component, $meta, $memo, $attributes);
        });

        if ($hydrate === null) {
            return $render();
        }

        $html = $this->renderer->later($render);

        return $hydrate === 'static' ? $this->pageData->withoutState($html) : $html;
    }

    /** @param  array<string, string>  $slots */
    private function mountChild(string $name, array $props, Component $parent, string $key, array $slots = []): string
    {
        $id = $parent->getId().'.'.$key;

        return $this->guarded($id, function (?Component &$component) use ($name, $id, $props, $slots): string {
            $meta = $this->placeable($name);
            $this->create($meta, $id, $props, $component);
            $component->nitro()->slots = $slots;

            return $this->renderer->render($component, $meta, $this->renderer->pageMemo());
        });
    }

    /**
     * A component started and rendered by $place, or its fallback when that fails (Fallbacks). While
     * a visit collects children's states, the fallback goes with them, by the child's id (the browser
     * puts it in the child's place); while a call does, the failure is the call's (the browser shows
     * the component's fallback).
     *
     * @param  Closure(?Component &): string  $place  sets the component once it is made
     */
    private function guarded(string $id, Closure $place): string
    {
        $component = null;

        try {
            return $place($component);
        } catch (Throwable $exception) {
            if ($this->renderer->collecting() && ! $this->renderer->collectsFallbacks()) {
                throw $exception;
            }

            $html = $this->fallbacks->placed($id, $component, $exception);

            if ($this->renderer->collecting()) {
                $this->renderer->collectFallback($id, $html);
            }

            return $html;
        }
    }

    /** A component placed in a view (<nitro:...>, @nitro, a dynamic component): not a page. */
    private function placeable(string $name): ComponentMeta
    {
        $meta = $this->components->meta($name);

        if ($meta->page) {
            throw new InvalidArgumentException("[{$meta->name}] is a page: pages are routed (Route::nitro()), not placed in views. Place a component instead.");
        }

        if ($meta->store) {
            throw new InvalidArgumentException("[{$meta->name}] is a store: components use it through a property of its type (public Cart \$cart), it is not placed in views.");
        }

        return $meta;
    }

    /**
     * A new instance: mount() receives the props as named arguments when the component has one;
     * otherwise the props set its public properties (as the browser does for such components).
     * $component is the instance from the moment it is made: when starting it fails, its fallback()
     * is asked (guarded()).
     *
     * @param  array<string, mixed>  $props
     * @param-out  Component  $component
     */
    private function create(ComponentMeta $meta, string $id, array $props, ?Component &$component): Component
    {
        /** @var Component $component */
        $component = $this->app->make($meta->class);

        /** nitro:model on its tag: the parent's value, for its #[Modelable] property (once mounted). */
        $model = array_key_exists(Protocol::modelProp(), $props) ? [$props[Protocol::modelProp()]] : null;
        unset($props[Protocol::modelProp()]);

        if ($model !== null && $meta->modelable === null) {
            throw new InvalidArgumentException("nitro:model on <nitro:{$meta->name}>: the component has no #[Modelable] property.");
        }

        $this->stores->give($component, $meta);
        $this->features->start(
            $component,
            $id,
            $meta->name,
            $meta->hasMount ? null : fn () => $this->fill($component, $meta, $props),
            $meta->hasMount ? $this->cache->mount($component, $meta, $props, fn () => $this->app->call([$component, ComponentHooks::MOUNT], $props)) : null,
        );

        if ($model !== null) {
            $component->{$meta->modelable} = $model[0];
        }

        return $component;
    }

    /**
     * Props into the public properties of the same name, for a component without mount().
     *
     * @param  array<string, mixed>  $props
     */
    private function fill(Component $component, ComponentMeta $meta, array $props): void
    {
        foreach ($props as $prop => $value) {
            if (($property = $meta->property($prop)) === null) {
                throw new InvalidArgumentException("Nitro component [{$meta->name}] has no public property \${$prop}.");
            }

            /** An enum property given its value (a child the browser placed): the case it names. */
            if ($property->kind === PropertyMeta::ENUM && ! $property->accepts($value)) {
                throw new InvalidArgumentException("Nitro component [{$meta->name}]: \${$prop} takes a case of {$property->types[0]}.");
            }

            $component->{$prop} = $property->kind === PropertyMeta::ENUM ? $property->cast($value) : $value;
        }
    }
}
