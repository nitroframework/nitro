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
use Nitro\Fusion\Build\BrowserNames;
use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Build\ServerChildren;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Compiler\Components;
use Nitro\Fusion\Component;
use Nitro\Fusion\Lifecycle\Features;
use Nitro\Fusion\Support\ComponentHooks;
use Nitro\Fusion\Merge;
use Nitro\Fusion\Rendering\PageHead;
use Nitro\Fusion\Rendering\Portals;
use Nitro\Fusion\Rendering\ComponentRenderer;
use Nitro\Fusion\State\Snapshot;
use Nitro\Fusion\State\SharedData;
use Nitro\Fusion\State\Snapshots;
use Nitro\Fusion\State\Stores;
use Nitro\Fusion\Support\Config;
use Nitro\Fusion\Support\LayoutKey;
use Nitro\Fusion\Support\Protocol;
use Nitro\Fusion\Support\UrlValues;
use Nitro\Fusion\View\ViewEngines;
use Symfony\Component\HttpFoundation\Response;

/**
 * A routed page's answer (Route::fusion()): mount() with the route's parameters, then the page in
 * its layout as HTML, or for a fusion:navigate visit the data the browser renders it from (a 409
 * when the browser runs another build). Partial reloads, and the merge and deferred properties
 * mount() declares, are answered here too. A part of Fusion.
 */
final class PageResponses
{

    public function __construct(
        private readonly Config $config,
        private readonly Container $app,
        private readonly ComponentRenderer $renderer,
        private readonly PageHead $head,
        private readonly Portals $portals,
        private readonly SharedData $data,
        private readonly BrowserNames $names,
        private readonly Components $components,
        private readonly Features $features,
        private readonly ViewEngines $views,
        private readonly Stores $stores,
        private readonly ServerChildren $children,
    ) {
    }

    /**
     * A routed page (Route::fusion()): mount() with the route's parameters, then the component
     * inside its layout; or, for a fusion:navigate visit, only what the browser needs to render
     * the page itself (pageData()).
     */
    public function page(Component $component, array $parameters): Response
    {
        $meta = $this->components->meta($component::class);
        $component->fusion()->id = $this->pageId();
        $component->fusion()->name = $meta->name;
        $this->stores->give($component, $meta);
        $this->features->boot($component);
        $this->features->mounting($component);

        if ($meta->hasMount) {
            /** The route's parameters, already bound and resolved by the router, in mount()'s order. */
            [$component, ComponentHooks::MOUNT](...array_values($parameters));
        } else {
            $this->fillFromRoute($component, $meta, $parameters);
        }

        return $this->respond($component, $meta);
    }

    /**
     * A page without mount(): each route parameter into its public property of the same name, as
     * a client page (#[Navigate('client')]) takes them from the URL in the browser. A value that
     * is not of the property's type (/chapters/abc for an int) is no such page: 404.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function fillFromRoute(Component $component, ComponentMeta $meta, array $parameters): void
    {
        foreach ($parameters as $name => $value) {
            $property = $meta->property((string) $name);

            if ($property === null) {
                continue;
            }

            $types = array_values(array_diff($property->types, ['null']));

            if (($value === null && $property->nullable) || ! is_string($value) || count($types) !== 1 || ! in_array($types[0], ['int', 'float'], true)) {
                $component->{$name} = $value;

                continue;
            }

            [$fits, $typed] = UrlValues::read($types[0], $value);
            $component->{$name} = $fits ? $typed : abort(404);
        }
    }

    /**
     * A merge prop from a page's mount() ($this->merge(), $this->deepMerge(), $this->scroll()):
     * its value set when the request wants the property (the closure is not called otherwise),
     * and, on a partial reload, the Merge kept so its rule (->matchOn(), ->prepend(), ... called
     * after) goes with the value.
     */
    public function mergeProperty(Component $page, string $property, Merge $merge): Merge
    {
        $meta = $this->components->meta($page::class);

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
        $meta = $this->components->meta($page::class);

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

        if (! $request instanceof Request || ! $request->headers->has(Protocol::header('navigate')) || ! $request->headers->has(Protocol::header('partial'))) {
            return null;
        }

        $only = array_map('trim', explode(',', (string) $request->headers->get(Protocol::header('only'), '')));

        return array_values(array_filter($only, fn (string $name) => preg_match('/^\w+$/', $name) === 1));
    }

    /** Whether the request needs the page property: any request but a partial reload of others. */
    public function wants(string $property): bool
    {
        $only = $this->partial();
        $names = $this->names->obfuscator();

        return $only === null || $only === [] || in_array($property, $only, true)
            || ($names !== null && in_array($names->property($property), $only, true));
    }

    /**
     * The layout a page renders in, as the browser compares it (fusion:page): a markup-first
     * visit renders the page at once only into the same one.
     */
    public function pageLayout(ComponentMeta $meta): string
    {
        return LayoutKey::of($meta->layout->view ?? $this->config->layout(), $meta->layout->data ?? []);
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
        $names = $this->names->obfuscator();

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
            $this->partial() !== null => (string) $request->headers->get(Protocol::header('partial')),
            $request->headers->has(Protocol::header('navigate')) => (string) $request->headers->get(Protocol::header('id')),
            default => '',
        };

        return preg_match('/^f[a-z0-9]{10}$/', $id) === 1 ? $id : 'f'.Str::lower(Str::random(10));
    }

    /** The page's answer: HTML in its layout, or the page data for a fusion:navigate visit. */
    private function respond(Component $component, ComponentMeta $meta): Response
    {
        $head = $this->head->forPage($component, $meta);
        $layout = $meta->layout->view ?? $this->config->layout();
        $layoutData = [...($meta->layout->data ?? []), 'title' => $head['title']];

        /** The same URL answers with HTML or with page data: caches must keep them apart. */
        $request = $this->app->make('request');

        if ($request->headers->has(Protocol::header('navigate'))) {
            /** The browser runs another build's components (a deploy since it loaded): load the page in full. */
            $version = $request->headers->get(Protocol::header('version'));

            if ($version !== null && $version !== $this->app->make(Bundle::class)->tag()) {
                return (new HttpResponse('', 409, [Protocol::header('location') => $request->fullUrl()]))->setVary(Protocol::header('navigate'), false);
            }

            return $this->pageData($component, $meta, $layout, $layoutData)->setVary(Protocol::header('navigate'), false);
        }

        $html = $this->renderer->render($component, $meta, $this->renderer->pageMemo(), [Protocol::attribute('page') => LayoutKey::of($layout, $layoutData)]);

        $page = $this->app->make('view')->make($layout, [...$layoutData, 'slot' => new HtmlString($html)])->render();

        return response($this->portals->fill($page))->setVary(Protocol::header('navigate'), false);
    }

    /**
     * A page for the browser to render: its signed state, the title its layout gives it, and the
     * layout it belongs in (a different one is a full page load). The browser has the compiled
     * component; no HTML is rendered here, except to create the children that have mount(): those
     * can only be created on the server, with the props the server's render passes them.
     */
    private function pageData(Component $component, ComponentMeta $meta, string $layout, array $layoutData): JsonResponse
    {
        $memo = $this->renderer->pageMemo();
        $snapshots = $this->app->make(Snapshots::class);

        $children = $this->children->placedBy($meta)
            ? $this->renderer->collectChildren(fn () => $this->renderer->render($component, $meta, $memo))
            : [];

        /** In development, the page or a component it renders that does not compile: its error. */
        $this->app->make(Bundle::class)->assertCompiled([$meta->name, ...$this->renderer->rendered()]);

        $this->features->dehydrate($component);
        $snapshot = Snapshot::capture($component, $meta, $memo);
        $only = $this->partial();

        return new JsonResponse([
            'fusion' => 1,
            'snapshot' => $snapshots->envelope($snapshot, $meta->name),
            /** A partial reload of some properties: those alone, signed, for the browser to lay over its own state. */
            'partial' => $only === null || $only === [] ? null : $snapshots->envelope($snapshot->only($this->partialProperties($only, $meta), $component->fusion()->mergeRules()), $meta->name),
            'children' => (object) $children,
            'title' => $this->head->layoutTitle($layout, $layoutData),
            /** @fusionHead's tags but the title, when the layout has it (layoutTitle() just rendered it): the browser puts them in place of the last page's. */
            'head' => $this->head->printed() ? $this->head->tags() : null,
            'layout' => LayoutKey::of($layout, $layoutData),
            ...$this->data->forBrowser(),
        ] + (($stores = $this->stores->snapshots($this->renderer->pageMemo())) === [] ? [] : [
            /** The stores the page's components use: the browser makes those it does not have yet. */
            'stores' => (object) $stores,
        ]), 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

}
