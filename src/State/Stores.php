<?php

namespace Nitro\Fusion\State;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Nitro\Fusion\Build\BrowserNames;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Compiler\Components;
use Nitro\Fusion\Component;
use Nitro\Fusion\Lifecycle\Features;
use Nitro\Fusion\Support\ComponentHooks;
use Nitro\Fusion\Store;

/**
 * This request's stores: one of each, shared by the components that use it, made with its mount()
 * the first time one asks, or taken as the browser sent it (a #[Server] call); and their signed
 * states for the browser. A part of Fusion.
 */
final class Stores
{
    /** @var array<string, Store> by name */
    private array $stores = [];

    /** @var array<string, array<string, mixed>> the page each store the browser sent was made for (its signed memo) */
    private array $memos = [];

    public function __construct(
        private readonly Container $app,
        private readonly BrowserNames $names,
        private readonly Components $components,
        private readonly Features $features,
    ) {
    }

    /**
     * This request's store of a class: the same one for every component that uses it, made with
     * its mount() (on the server) the first time one asks.
     */
    public function store(string $class): Store
    {
        $meta = $this->components->meta($class);

        if (! $meta->store) {
            throw new InvalidArgumentException("{$meta->class} is not a store (a class extending ".Store::class.').');
        }

        if (! isset($this->stores[$meta->name])) {
            /** @var Store $store */
            $store = $this->app->make($meta->class);
            $store->fusion()->id = $meta->name;
            $store->fusion()->name = $meta->name;
            $this->stores[$meta->name] = $store;
            $this->features->boot($store);
            $this->features->mounting($store);

            if ($meta->hasMount) {
                $this->app->call([$store, ComponentHooks::MOUNT]);
            }
        }

        return $this->stores[$meta->name];
    }

    /**
     * A store as the browser sent it (a #[Server] call): the one this request's components use.
     * Signed again for the page it was made for ($memo), not for the endpoint.
     *
     * @param  array<string, mixed>  $memo
     */
    public function adopt(Store $store, array $memo = []): void
    {
        $this->stores[$store->fusion()->name] = $store;
        $this->memos[$store->fusion()->name] = $memo;
    }

    /** The component's store properties (public Cart $cart), given this request's stores. */
    public function give(Component $component, ComponentMeta $meta): void
    {
        foreach ($meta->stores as $property => $class) {
            $component->{$property} = $this->store($class);
        }
    }

    /**
     * The stores this request made or took from the browser, signed for it: the page sends them
     * with its components (the browser makes those it does not have yet).
     *
     * @param  array<string, mixed>  $pageMemo  the page the request renders (a store made here is signed for it)
     * @return array<string, array> browser name => signed envelope
     */
    public function snapshots(array $pageMemo): array
    {
        $snapshots = [];

        foreach ($this->stores as $name => $store) {
            $meta = $this->components->meta($name);
            $memo = $this->memos[$name] ?? $pageMemo;
            $this->features->dehydrate($store);
            $browserName = $this->names->component($name);
            $snapshots[$browserName] = app(Snapshots::class)->envelope(Snapshot::capture($store, $meta, $memo), $name);
        }

        return $snapshots;
    }
}
