<?php

namespace Nitro\Fusion\Rendering;

use Illuminate\Support\ViewErrorBag;
use Illuminate\View\ComponentSlot;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Factory as ViewFactory;
use LogicException;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Component;
use Nitro\Fusion\State;
use Throwable;

/**
 * Renders components on the server with Blade itself (Fusion only compiles the same view for the
 * browser). The view sees the component's properties as variables and the component as $this,
 * and its loops start fresh, so $loop and child keys depend on the component's own view only.
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

    public function __construct(private readonly ViewFactory $views)
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

    private function view(Component $component, ComponentMeta $meta): string
    {
        $path = $this->views->getFinder()->find($meta->view);
        $engine = $this->views->getEngineResolver()->resolve('blade');

        if (! $engine instanceof CompilerEngine) {
            throw new LogicException('Fusion renders views with Blade\'s compiler engine.');
        }

        $compiler = $engine->getCompiler();

        if ($compiler->isExpired($path)) {
            $compiler->compile($path);
        }

        $errors = new ViewErrorBag;
        $errors->put('default', $component->fusion()->errors);

        $slots = $component->fusion()->slots;

        $data = [
            ...$this->views->getShared(),
            /** What the parent's tag passed: $slot (the default slot), $slots['name']. */
            'slot' => new ComponentSlot($slots['default'] ?? ''),
            'slots' => new Slots($slots),
            ...State::of($component),
            /** The stores it uses ($cart): not its state, but its view reads them as it reads its properties. */
            ...array_map(fn (string $property) => $component->{$property}, array_combine(array_keys(State::stores($component::class)), array_keys(State::stores($component::class)))),
            'errors' => $errors,
            '__env' => $this->views,
        ];

        $this->stack[] = $component;
        $this->orders[] = $this->started++;
        $loops = $this->isolateLoops();
        $context = $component->fusion();
        $context->rendering = true;

        try {
            return $this->evaluate($component, $compiler->getCompiledPath($path), $data);
        } finally {
            $context->rendering = false;
            $context->computed = [];
            $this->restoreLoops($loops);
            array_pop($this->stack);
            array_pop($this->orders);
        }
    }

    /** Include the compiled view with $this bound to the component. */
    private function evaluate(Component $component, string $__path, array $__data): string
    {
        $level = ob_get_level();
        ob_start();

        /**
         * Counted as a render, as View::render() counts one: a Blade component in the view renders
         * its own view, and when that ends the factory flushes its state if nothing else is
         * rendering, the component tags still open around it included.
         */
        $this->views->incrementRender();

        try {
            (function () use ($__path, $__data) {
                extract($__data, EXTR_SKIP);

                include $__path;
            })->call($component);
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        } finally {
            $this->views->decrementRender();
        }

        /** A component is its root element: whitespace around it (the view file's last newline) is not part of it. */
        return trim((string) ob_get_clean());
    }

    /**
     * Empty the view factory's loop stack for the component's view (Blade keeps it in a
     * protected property, with no API to swap it) and return the outer view's.
     *
     * @return array<int, array<string, mixed>>
     */
    private function isolateLoops(): array
    {
        return (function (): array {
            /** @var ViewFactory $this */
            $stack = $this->loopsStack;
            $this->loopsStack = [];

            return $stack;
        })->call($this->views);
    }

    /** @param  array<int, array<string, mixed>>  $loops */
    private function restoreLoops(array $loops): void
    {
        (function () use ($loops): void {
            /** @var ViewFactory $this */
            $this->loopsStack = $loops;
        })->call($this->views);
    }
}
