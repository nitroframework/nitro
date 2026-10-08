<?php

namespace Nitro\Fusion\Rendering;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Compiler\Components;
use Nitro\Fusion\Compiler\PropertyMeta;
use Nitro\Fusion\Compiler\Template\ChildTag;
use Nitro\Fusion\Component;
use Nitro\Fusion\Lifecycle\Features;
use Nitro\Fusion\State\Stores;
use Nitro\Fusion\Support\ComponentHooks;
use Nitro\Fusion\Support\Protocol;
use Nitro\Fusion\Support\Registry;

/**
 * A component placed in a view and rendered on the server: <fusion:name /> in a page, a layout or
 * another component's view (a child), @fusion, <fusion:dynamic-component>. Made with its mount()
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
    ) {
    }

    /**
     * A component placed in a view: <fusion:name /> in a page or layout, or a child in another
     * component's view (keyed as the browser keys it: ChildTag::key()). $hydrate: an island's start
     * (fusion:hydrate, fusion:static), for a component that is not a child.
     *
     * @param  array<string, string>  $slots  name => its HTML
     */
    public function tag(string $name, array $props, mixed $key, int $number, array $slots = [], ?string $hydrate = null): HtmlString
    {
        $parent = $this->renderer->current();

        /** <fusion:dynamic-component :is="$name">: the component :is names (none for ""); its key has the name. */
        $dynamic = $name === ChildTag::DYNAMIC;

        if ($dynamic) {
            if (! array_key_exists('is', $props)) {
                throw new InvalidArgumentException('<fusion:dynamic-component> needs :is, the name of the component to render');
            }

            /** null names none, as "" does (the browser reads it as a string). */
            $is = $props['is'] ?? '';
            unset($props['is']);

            if (! is_string($is)) {
                throw new UnplaceableComponent('<fusion:dynamic-component> :is names a component: a string, not '.get_debug_type($is).'.');
            }

            if ($is === '') {
                return new HtmlString('');
            }

            $name = $is;
            $class = class_exists($name) ? ltrim($name, '\\') : $this->registry->class($name);

            if ($class === null || $this->registry->isPageClass($class)) {
                throw new UnplaceableComponent($class === null
                    ? "<fusion:dynamic-component> :is names [{$name}], which is not a Fusion component."
                    : "[{$name}] is a page: pages are routed (Route::fusion()), not placed in views. Place a component instead.");
            }
        }

        if ($parent === null) {
            return new HtmlString($this->mount($name, $props, $slots, $hydrate));
        }

        if ($hydrate !== null) {
            throw new InvalidArgumentException("fusion:hydrate and fusion:static are for islands, components placed in Blade views: <fusion:{$name}> is a child of [{$parent->fusion()->name}], and starts with it.");
        }

        $key = ($key !== null ? (string) $key : ChildTag::key($number, $this->renderer->loopIndexes())).($dynamic ? ':'.$name : '');

        return new HtmlString($this->mountChild($name, $props, $parent, $key, $slots));
    }

    /**
     * Render a component that is not inside another (in a page, a layout, @fusion).
     *
     * An island that starts later ($hydrate: fusion:hydrate) is marked for the browser, and the
     * chunks of its components are not loaded with the page; a static one (fusion:static) is its
     * HTML only, without state for the browser.
     *
     * @param  array<string, string>  $slots  name => its HTML
     */
    public function mount(string $name, array $props = [], array $slots = [], ?string $hydrate = null): string
    {
        $render = function () use ($name, $props, $slots, $hydrate): string {
            $meta = $this->placeable($name);
            $component = $this->create($meta, 'f'.Str::lower(Str::random(10)), $props);
            $component->fusion()->slots = $slots;
            $attributes = $hydrate === null || $hydrate === 'static' ? [] : [Protocol::attribute('hydrate') => $hydrate];
            /** No parent renders its slots in the browser: their HTML goes with its state. */
            $memo = $this->renderer->pageMemo() + (array_filter($slots, fn (string $html) => trim($html) !== '') === [] ? [] : ['slots' => $slots]);

            return $this->renderer->render($component, $meta, $memo, $attributes);
        };

        if ($hydrate === null) {
            return $render();
        }

        $html = $this->renderer->later($render);

        return $hydrate === 'static' ? (string) preg_replace('/\sfusion:(?:snapshot|id|name)="[^"]*"/', '', $html) : $html;
    }

    /** @param  array<string, string>  $slots */
    private function mountChild(string $name, array $props, Component $parent, string $key, array $slots = []): string
    {
        $meta = $this->placeable($name);
        $component = $this->create($meta, $parent->getId().'.'.$key, $props);
        $component->fusion()->slots = $slots;

        return $this->renderer->render($component, $meta, $this->renderer->pageMemo());
    }

    /** A component placed in a view (<fusion:...>, @fusion, a dynamic component): not a page. */
    private function placeable(string $name): ComponentMeta
    {
        $meta = $this->components->meta($name);

        if ($meta->page) {
            throw new InvalidArgumentException("[{$meta->name}] is a page: pages are routed (Route::fusion()), not placed in views. Place a component instead.");
        }

        if ($meta->store) {
            throw new InvalidArgumentException("[{$meta->name}] is a store: components use it through a property of its type (public Cart \$cart), it is not placed in views.");
        }

        return $meta;
    }

    /**
     * A new instance: mount() receives the props as named arguments when the component has one;
     * otherwise the props set its public properties (as the browser does for such components).
     *
     * @param  array<string, mixed>  $props
     */
    private function create(ComponentMeta $meta, string $id, array $props): Component
    {
        /** @var Component $component */
        $component = $this->app->make($meta->class);
        $component->fusion()->id = $id;
        $component->fusion()->name = $meta->name;
        $this->stores->give($component, $meta);
        $this->features->boot($component);

        /** fusion:model on its tag: the parent's value, for its #[Modelable] property (once mounted). */
        $model = array_key_exists(Protocol::modelProp(), $props) ? [$props[Protocol::modelProp()]] : null;
        unset($props[Protocol::modelProp()]);

        if ($model !== null && $meta->modelable === null) {
            throw new InvalidArgumentException("fusion:model on <fusion:{$meta->name}>: the component has no #[Modelable] property.");
        }

        if ($meta->hasMount) {
            $this->features->mounting($component);
            $this->app->call([$component, ComponentHooks::MOUNT], $props);
        } else {
            foreach ($props as $prop => $value) {
                if (($property = $meta->property($prop)) === null) {
                    throw new InvalidArgumentException("Fusion component [{$meta->name}] has no public property \${$prop}.");
                }

                /** An enum property given its value (a child the browser placed): the case it names. */
                if ($property->kind === PropertyMeta::ENUM && ! $property->accepts($value)) {
                    throw new InvalidArgumentException("Fusion component [{$meta->name}]: \${$prop} takes a case of {$property->types[0]}.");
                }

                $component->{$prop} = $property->kind === PropertyMeta::ENUM ? $property->cast($value) : $value;
            }

            $this->features->mounting($component);
        }

        if ($model !== null) {
            $component->{$meta->modelable} = $model[0];
        }

        return $component;
    }
}
