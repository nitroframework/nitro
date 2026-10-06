<?php

namespace Nitro\Fusion\Rendering;

use Illuminate\View\Factory as ViewFactory;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Component;
use Nitro\Fusion\View\ViewEngines;

/**
 * Renders components on the server: their view by its engine (View\ViewEngines), the root
 * element marked with the id, name and signed state the browser takes over.
 */
final class Renderer
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

    public function __construct(private readonly ViewFactory $views, private readonly ViewEngines $engines)
    {
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
     * The component's HTML: its view, root element marked with id, name and signed state.
     *
     * @param  array<string, mixed>  $memo
     * @param  array<string, string>  $attributes  extra attributes for the root element
     */
    public function render(Component $component, ComponentMeta $meta, string $key, array $memo = [], array $attributes = []): string
    {
        if ($this->later === 0) {
            $this->rendered[$meta->name] = true;
        }

        $fusion = app(\Nitro\Fusion\Fusion::class);
        $name = $fusion->browserName($meta->name);
        $html = $fusion->obscure($this->view($component, $meta), [...$meta->browserMethods, ...$meta->serverMethods], array_keys($meta->properties));
        $fusion->features()->dehydrate($component);
        $envelope = Snapshot::capture($component, $meta, $memo)->toJson($key, $name, $fusion->names());

        if ($this->collected !== null && $this->stack !== [] && ($meta->hasMount || $meta->serverMethods !== [])) {
            $this->collected[$component->getId()] = json_decode($envelope, true);
        }

        return RootElement::mark($html, [
            'fusion:id' => $component->getId(),
            'fusion:name' => $name,
            'fusion:snapshot' => $envelope,
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
