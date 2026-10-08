<?php

namespace Nitro\Fusion\Rendering;

use Illuminate\Contracts\Container\Container;
use Illuminate\View\Factory as ViewFactory;
use Nitro\Fusion\Build\BrowserNames;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Component;
use Nitro\Fusion\Lifecycle\Features;
use Nitro\Fusion\State\Snapshot;
use Nitro\Fusion\State\Snapshots;
use Nitro\Fusion\Support\CurrentRequest;
use Nitro\Fusion\Support\Protocol;
use Nitro\Fusion\View\ViewEngines;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Renders one component on the server: a child in another's view, an island in a Blade view,
 * or a page (Http\PageResponses then puts a page in its layout). Its view is rendered by its
 * engine (View\ViewEngines); its root element is marked with the id, name and signed state the
 * browser takes over. It knows which component is rendering, for the children and teleported
 * content inside it.
 */
final class ComponentRenderer
{
    /** @var list<Component> components being rendered, innermost last */
    private array $stack = [];

    /** @var list<int> when each of them began rendering (the browser hydrates components in that order) */
    private array $orders = [];

    private int $started = 0;

    /** @var array<string, array>|null the children with mount() rendered while collecting, by id */
    private ?array $collected = null;

    /** @var array<string, true> the names of the components rendered (@fusionScripts loads their chunks) */
    private array $rendered = [];

    /** Islands being rendered that start later (or never): their components' chunks wait for them. */
    private int $later = 0;

    /** The page the components being rendered belong to, when it is not this request's (childrenOf()). */
    private ?array $renderingFor = null;

    public function __construct(
        private readonly ViewFactory $views,
        private readonly ViewEngines $engines,
        private readonly BrowserNames $names,
        private readonly Features $features,
        private readonly Container $app,
        private readonly PageData $pageData,
    ) {
    }

    /** The page the components were rendered for: their #[Server] calls re-check its middleware. */
    public function pageMemo(): array
    {
        if ($this->renderingFor !== null) {
            return $this->renderingFor;
        }

        $request = CurrentRequest::of($this->app);

        if ($request === null || $request->route() === null) {
            return [];
        }

        return ['route' => ['method' => $request->getMethod(), 'uri' => $request->getRequestUri()]];
    }

    /**
     * The signed state of the children the component's view places that need one (mount() or
     * #[Server] methods), by id: its view rendered on the server, for the page of $memo (the
     * component's own, from its snapshot), so the children's calls re-check that page's middleware.
     * The browser takes them for children it made itself, which a #[Server] call needs.
     *
     * @param  array<string, mixed>  $memo
     * @return array<string, array>
     */
    public function childrenOf(Component $component, ComponentMeta $meta, array $memo): array
    {
        $previous = $this->renderingFor;
        $this->renderingFor = $memo;

        try {
            return $this->collectChildren(fn () => $this->render($component, $meta, $memo));
        } catch (\Throwable $exception) {
            /**
             * The state may hold what the browser changed: props from it that do not fit a child
             * (its mount() parameters, its typed properties), or a dynamic component's name that
             * names none, are the request's fault, not the server's. Anything else is a real error.
             */
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof UnplaceableComponent || ($cause instanceof \TypeError && preg_match('/::mount\(\): Argument #\d+|^Cannot assign .+ to property .+ of type /', $cause->getMessage()) === 1)) {
                    throw new HttpException(403, 'A child\'s props, from the state the browser sent, do not fit it.', $exception);
                }
            }

            throw $exception;
        } finally {
            $this->renderingFor = $previous;
        }
    }

    public function current(): ?Component
    {
        return $this->stack === [] ? null : $this->stack[count($this->stack) - 1];
    }

    /** Where teleported content rendered now goes in its portal: in the order the browser hydrates it. */
    public function order(): int
    {
        return $this->orders === [] ? $this->started : $this->orders[count($this->orders) - 1];
    }

    /** @return list<string> the components rendered so far, by name (the bundle knows their chunks) */
    public function rendered(): array
    {
        return array_map('strval', array_keys($this->rendered));
    }

    /**
     * The component's HTML: its view, root element marked with id and name; its signed state goes to the page's block (PageData).
     *
     * @param  array<string, mixed>  $memo
     * @param  array<string, string>  $attributes  extra attributes for the root element
     */
    public function render(Component $component, ComponentMeta $meta, array $memo = [], array $attributes = []): string
    {
        if ($this->later === 0) {
            $this->rendered[$meta->name] = true;
        }

        $name = $this->names->component($meta->name);
        $html = $this->names->obscure($this->view($component, $meta), [...$meta->browserMethods, ...$meta->serverMethods], array_keys($meta->properties));
        $this->features->dehydrate($component);
        $envelope = app(Snapshots::class)->json(Snapshot::capture($component, $meta, $memo), $meta->name);

        if ($this->collected !== null && $this->stack !== [] && ($meta->hasMount || $meta->serverMethods !== [])) {
            $this->collected[$component->getId()] = json_decode($envelope, true);
        }

        $this->pageData->keep($component->getId(), $envelope);

        return RootElement::mark($html, [
            Protocol::attribute('id') => $component->getId(),
            Protocol::attribute('name') => $name,
            ...$attributes,
        ], $meta->name);
    }

    /**
     * Render an island that starts later (fusion:hydrate) or never (fusion:static): its
     * components are not among those @fusionScripts loads the chunks of.
     *
     * @param  callable(): string  $render
     */
    public function later(callable $render): string
    {
        $this->later++;

        try {
            return $render();
        } finally {
            $this->later--;
        }
    }

    /**
     * Run a render and return the signed state of the children with mount() or #[Server] methods
     * it created, by id: the browser renders the page itself, but only the server can create a child
     * with mount(), and only the server can sign the state a #[Server] call sends back.
     *
     * @return array<string, array>
     */
    public function collectChildren(callable $render): array
    {
        $outer = $this->collected;
        $this->collected = [];

        try {
            $render();

            return $this->collected;
        } finally {
            $this->collected = $outer;
        }
    }

    /**
     * Indexes of the loops the current component's view is inside (for child keys).
     *
     * @return list<int>
     */
    public function loopIndexes(): array
    {
        return array_values(array_map(fn (array $loop) => $loop['index'], $this->views->getLoopStack()));
    }

    /** The view's HTML, by its engine, with the component as the one rendering (children and teleports ask). */
    private function view(Component $component, ComponentMeta $meta): string
    {
        $this->stack[] = $component;
        $this->orders[] = $this->started++;
        $context = $component->fusion();
        $context->rendering = true;

        try {
            return $this->engines->of($meta)->render($component, $meta);
        } finally {
            $context->rendering = false;
            $context->computed = [];
            array_pop($this->stack);
            array_pop($this->orders);
        }
    }
}
