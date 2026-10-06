<?php

namespace Nitro\Fusion\Http;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Component;
use Nitro\Fusion\Fusion;
use Nitro\Fusion\Merge;
use Nitro\Fusion\Rendering\PageHead;
use Nitro\Fusion\Rendering\Portals;
use Nitro\Fusion\Rendering\Renderer;
use Nitro\Fusion\Rendering\Snapshot;
use Nitro\Fusion\State\SharedData;
use Symfony\Component\HttpFoundation\Response;

/**
 * A routed page's answer (Route::fusion()): mount() with the route's parameters, then the page in
 * its layout as HTML, or for a fusion:navigate visit the data the browser renders it from (a 409
 * when the browser runs another build). Partial reloads, and the merge and deferred properties
 * mount() declares, are answered here too. A part of Fusion.
 */
final class PageResponses
{
    /** @var array<class-string, bool> whether a component's view places a child only the server can create */
    private array $serverChildren = [];

    public function __construct(
        private readonly Fusion $fusion,
        private readonly Container $app,
        private readonly Renderer $renderer,
        private readonly PageHead $head,
        private readonly Portals $portals,
        private readonly SharedData $data,
    ) {
    }

    /**
     * A routed page (Route::fusion()): mount() with the route's parameters, then the component
     * inside its layout; or, for a fusion:navigate visit, only what the browser needs to render
     * the page itself (pageData()).
     */
    public function page(Component $component, array $parameters): Response
    {
        $meta = $this->fusion->meta($component::class);
        $component->fusion()->id = $this->pageId();
        $component->fusion()->name = $meta->name;
        $this->fusion->giveStores($component, $meta);
        $this->fusion->features()->boot($component);
        $this->fusion->features()->mounting($component);

        if ($meta->hasMount) {
            /** The route's parameters, already bound and resolved by the router, in mount()'s order. */
            [$component, 'mount'](...array_values($parameters));
        }

        return $this->respond($component, $meta);
    }

    /**
     * A merge prop from a page's mount() ($this->merge(), $this->deepMerge(), $this->scroll()):
     * its value set when the request wants the property (the closure is not called otherwise),
     * and, on a partial reload, the Merge kept so its rule (->matchOn(), ->prepend(), ... called
     * after) goes with the value.
     */
    public function mergeProperty(Component $page, string $property, Merge $merge): Merge
    {
        $meta = $this->fusion->meta($page::class);

        if (! $meta->page) {
            throw new InvalidArgumentException("Only pages merge properties: [{$meta->name}] is a component.");
        }

        if ($meta->property($property) === null) {
            throw new InvalidArgumentException("Fusion page [{$meta->name}] has no public property \${$property} to merge into.");
        }

        if ($this->wants($property)) {
            $page->{$property} = $merge->resolve($this->app->call(...));

            if ($merge->position() !== null) {
                $page->fusion()->scroll[$property] = $merge->position();
            }
        }

        if ($this->partial() !== null) {
            $page->fusion()->mergeProps[$property] = $merge;
        }

        return $merge;
    }

    /**
     * A page property loaded after the page is shown ($this->defer()): set when
     * a partial reload wants it; left as it is otherwise, and named in the snapshot for the
     * browser to ask for (a partial reload that does not want it leaves it alone).
     */
    public function deferProperty(Component $page, string $property, Closure $value): void
    {
        $meta = $this->fusion->meta($page::class);

        if (! $meta->page) {
            throw new InvalidArgumentException("Only pages defer properties: [{$meta->name}] is a component.");
        }

        if ($meta->property($property) === null) {
            throw new InvalidArgumentException("Fusion page [{$meta->name}] has no public property \${$property} to defer.");
        }

        if ($this->partial() === null) {
            $page->fusion()->deferred[] = $property;
        } elseif ($this->wants($property)) {
            $page->{$property} = $this->app->call($value);
        }
    }

    /**
     * A page reloading its properties in the browser (X-Fusion-Partial): the ones it wants, an
     * empty list for all of them. Null for any other request.
     *
     * @return list<string>|null
     */
    public function partial(): ?array
    {
        $request = $this->app->bound('request') ? $this->app->make('request') : null;

        if (! $request instanceof Request || ! $request->headers->has(Fusion::NAVIGATE_HEADER) || ! $request->headers->has(Fusion::PARTIAL_HEADER)) {
            return null;
        }

        $only = array_map('trim', explode(',', (string) $request->headers->get(Fusion::ONLY_HEADER, '')));

        return array_values(array_filter($only, fn (string $name) => preg_match('/^\w+$/', $name) === 1));
    }

    /** Whether the request needs the page property: any request but a partial reload of others. */
    public function wants(string $property): bool
    {
        $only = $this->partial();
        $names = $this->fusion->names();

        return $only === null || $only === [] || in_array($property, $only, true)
            || ($names !== null && in_array($names->property($property), $only, true));
    }

    /**
     * The layout a page renders in, as the browser compares it (fusion:page): a markup-first
     * visit renders the page at once only into the same one.
     */
    public function pageLayout(ComponentMeta $meta): string
    {
        return $this->layoutKey($meta->layout->view ?? (string) $this->fusion->config('layout', 'components.layouts.app'), $meta->layout->data ?? []);
    }

    /**
     * A partial reload's properties by the component's own names (the browser names them by
     * an obfuscated build's aliases).
     *
     * @param  list<string>  $only
     * @return list<string>
     */
    private function partialProperties(array $only, ComponentMeta $meta): array
    {
        $names = $this->fusion->names();

        return $names === null ? $only : array_map(fn (string $name) => $names->propertyName($name, array_keys($meta->properties)) ?? $name, $only);
    }

    /**
     * A new page's id; on a partial reload, the one it has in the browser; on a markup-first
     * visit, the one the browser gave the page it rendered early (its children's ids follow it).
     */
    private function pageId(): string
    {
        $request = $this->app->make('request');
        $id = match (true) {
            $this->partial() !== null => (string) $request->headers->get(Fusion::PARTIAL_HEADER),
            $request->headers->has(Fusion::NAVIGATE_HEADER) => (string) $request->headers->get(Fusion::ID_HEADER),
            default => '',
        };

        return preg_match('/^f[a-z0-9]{10}$/', $id) === 1 ? $id : 'f'.Str::lower(Str::random(10));
    }

    /** The page's answer: HTML in its layout, or the page data for a fusion:navigate visit. */
    private function respond(Component $component, ComponentMeta $meta): Response
    {
        $head = $this->head->forPage($component, $meta);
        $layout = $meta->layout->view ?? (string) $this->fusion->config('layout', 'components.layouts.app');
        $layoutData = [...($meta->layout->data ?? []), 'title' => $head['title']];

        /** The same URL answers with HTML or with page data: caches must keep them apart. */
        $request = $this->app->make('request');

        if ($request->headers->has(Fusion::NAVIGATE_HEADER)) {
            /** The browser runs another build's components (a deploy since it loaded): load the page in full. */
            $version = $request->headers->get(Fusion::VERSION_HEADER);

            if ($version !== null && $version !== $this->app->make(Bundle::class)->tag()) {
                return (new HttpResponse('', 409, ['X-Fusion-Location' => $request->fullUrl()]))->setVary(Fusion::NAVIGATE_HEADER, false);
            }

            return $this->pageData($component, $meta, $layout, $layoutData)->setVary(Fusion::NAVIGATE_HEADER, false);
        }

        $html = $this->renderer->render($component, $meta, $this->fusion->stateKey(), $this->fusion->pageMemo(), ['fusion:page' => $this->layoutKey($layout, $layoutData)]);

        $page = $this->app->make('view')->make($layout, [...$layoutData, 'slot' => new HtmlString($html)])->render();

        return response($this->portals->fill($page))->setVary(Fusion::NAVIGATE_HEADER, false);
    }

    /**
     * A page for the browser to render: its signed state, the title its layout gives it, and the
     * layout it belongs in (a different one is a full page load). The browser has the compiled
     * component; no HTML is rendered here, except to create the children that have mount(): those
     * can only be created on the server, with the props the server's render passes them.
     */
    private function pageData(Component $component, ComponentMeta $meta, string $layout, array $layoutData): JsonResponse
    {
        $key = $this->fusion->stateKey();
        $memo = $this->fusion->pageMemo();

        $children = $this->hasServerChildren($meta)
            ? $this->renderer->collectChildren(fn () => $this->renderer->render($component, $meta, $key, $memo))
            : [];

        /** In development, the page or a component it renders that does not compile: its error. */
        $this->app->make(Bundle::class)->assertCompiled([$meta->name, ...$this->renderer->rendered()]);

        $this->fusion->features()->dehydrate($component);
        $snapshot = Snapshot::capture($component, $meta, $memo);
        $only = $this->partial();

        return new JsonResponse([
            'fusion' => 1,
            'snapshot' => json_decode($snapshot->toJson($key, $this->fusion->browserName($meta->name), $this->fusion->names()), true),
            /** A partial reload of some properties: those alone, signed, for the browser to lay over its own state. */
            'partial' => $only === null || $only === [] ? null : json_decode($snapshot->only($this->partialProperties($only, $meta), $component->fusion()->mergeRules())->toJson($key, $this->fusion->browserName($meta->name), $this->fusion->names()), true),
            'children' => (object) $children,
            'title' => $this->head->layoutTitle($layout, $layoutData),
            /** @fusionHead's tags but the title, when the layout has it (layoutTitle() just rendered it): the browser puts them in place of the last page's. */
            'head' => $this->head->printed() ? $this->head->tags() : null,
            'layout' => $this->layoutKey($layout, $layoutData),
            'shared' => $this->data->sharedForBrowser(),
            'once' => (object) $this->data->onceForBrowser(),
            'flash' => $this->data->flashForBrowser(),
        ] + (($stores = $this->fusion->storeSnapshots()) === [] ? [] : [
            /** The stores the page's components use: the browser makes those it does not have yet. */
            'stores' => (object) $stores,
        ]), 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Pages share the page around them when they have the same layout, with the same data. */
    private function layoutKey(string $layout, array $layoutData): string
    {
        unset($layoutData['title']);

        return $layoutData === [] ? $layout : $layout.'#'.substr(md5(serialize($layoutData)), 0, 12);
    }

    /**
     * Whether the component's view places (itself or through the children it places) a child that
     * has mount() or #[Server] methods: only the server can create the first, or sign the state of
     * the second. $mountOnly: a child with mount() only (a #[Server] call answers with those: the
     * browser takes the state of the others when they first call the server).
     *
     * @param  array<string, true>  $seen  classes already looked at (views can place each other)
     */
    public function hasServerChildren(ComponentMeta $meta, bool $mountOnly = false, array &$seen = []): bool
    {
        /** A store has no view, and a view the app's build compiles places no Fusion children. */
        if ($meta->store || ! $this->fusion->viewEngine($meta)->compiledByFusion()) {
            return false;
        }

        $key = $meta->class.($mountOnly ? '#mount' : '');

        if (isset($this->serverChildren[$key])) {
            return $this->serverChildren[$key];
        }

        $seen[$meta->class] = true;
        $source = (string) file_get_contents($this->fusion->viewEngine($meta)->file($meta));
        $found = false;

        preg_match_all('/<fusion:([\w.\-]+)/', $source, $tags);

        foreach (array_unique($tags[1]) as $name) {
            /** It may name any component, one with mount() or #[Server] methods too. */
            if ($name === 'dynamic-component') {
                $found = true;

                break;
            }

            if (! $this->fusion->has($name)) {
                continue;
            }

            $child = $this->fusion->meta($name);

            /** A child that uses a store: rendered here, so the store is made here (its mount()) and sent with the page. */
            if ($child->hasMount || (! $mountOnly && ($child->serverMethods !== [] || $child->stores !== [])) || (! isset($seen[$child->class]) && $this->hasServerChildren($child, $mountOnly, $seen))) {
                $found = true;

                break;
            }
        }

        return $this->serverChildren[$key] = $found;
    }
}
