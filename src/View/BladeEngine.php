<?php

namespace Nitro\View;

use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\ComponentSlot;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Factory as ViewFactory;
use LogicException;
use Nitro\Compiler\ComponentMeta;
use Nitro\Compiler\StateProperties;
use Nitro\Compiler\Template\BladeInliner;
use Nitro\Compiler\Template\ComponentTags;
use Nitro\Component;
use Nitro\Support\Protocol;
use Throwable;

/**
 * Blade views: rendered on the server by Blade itself, and compiled by Nitro for the browser.
 * The view sees the component's properties as variables and the component as $this, and its
 * loops start fresh, so $loop and child keys depend on the component's own view only.
 */
final class BladeEngine implements ViewEngine
{
    /** The view factory's properties Factory::flushState() resets. */
    public const RENDER_STATE = [
        'renderCount', 'renderedOnce',
        'sections', 'sectionStack',
        'pushes', 'prepends', 'pushStack',
        'componentStack', 'componentData', 'currentComponentData', 'slots', 'slotStack',
        'fragments', 'fragmentStack',
    ];

    private ?BladeInliner $inliner = null;

    public function __construct(private readonly ViewFactory $views, private readonly BladeCompiler $blade)
    {
    }

    public function name(): string
    {
        return Protocol::bladeEngine();
    }

    public function label(): string
    {
        return 'Blade';
    }

    public function file(ComponentMeta $meta): string
    {
        return $this->views->getFinder()->find($meta->view);
    }

    public function compiledByNitro(): bool
    {
        return true;
    }

    /** None: a Blade view's own ($set, $refresh, ...) are $-named, apart from the component's. */
    public function reservedNames(): array
    {
        return [];
    }

    /** The view, and those it includes or uses as <x-components>: they are inlined into it. */
    public function files(ComponentMeta $meta): array
    {
        $file = $this->file($meta);
        $this->inliner ??= new BladeInliner($this->views, new ComponentTags($this->blade));

        return [$file, ...$this->inliner->dependencies($file)];
    }

    public function render(Component $component, ComponentMeta $meta): string
    {
        $path = $this->file($meta);
        $engine = $this->views->getEngineResolver()->resolve('blade');

        if (! $engine instanceof CompilerEngine) {
            throw new LogicException('Nitro renders views with Blade\'s compiler engine.');
        }

        $compiler = $engine->getCompiler();

        if ($compiler->isExpired($path)) {
            $compiler->compile($path);
        }

        $errors = new ViewErrorBag;
        $errors->put('default', $component->nitro()->errors);

        $slots = $component->nitro()->slots;
        $stores = array_keys(StateProperties::stores($component::class));

        $data = [
            ...$this->views->getShared(),
            /** What the parent's tag passed: $slot (the default slot), $slots['name']. */
            'slot' => new ComponentSlot($slots['default'] ?? ''),
            'slots' => new Slots($slots),
            ...StateProperties::values($component),
            /** The stores it uses ($cart): not its state, but its view reads them as it reads its properties. */
            ...array_map(fn (string $property) => $component->{$property}, array_combine($stores, $stores)),
            'errors' => $errors,
            '__env' => $this->views,
        ];

        $loops = $this->isolateLoops();

        /**
         * The Blade components in the view read $errors from the shared data, as Laravel shares
         * the session's: here the component's, as its compiled view reads them in the browser.
         */
        $shared = $this->views->getShared();
        $this->views->share('errors', $errors);

        try {
            return $this->evaluate($component, $compiler->getCompiledPath($path), $data);
        } finally {
            $this->restoreLoops($loops);
            $this->restoreSharedErrors($shared);
        }
    }

    /** @param  array<string, mixed>  $shared  the shared data before the view: its $errors, or none */
    private function restoreSharedErrors(array $shared): void
    {
        if (array_key_exists('errors', $shared)) {
            $this->views->share('errors', $shared['errors']);

            return;
        }

        (function (): void {
            /** @var ViewFactory $this */
            unset($this->shared['errors']);
        })->call($this->views);
    }

    /** Include the compiled view with $this bound to the component. */
    private function evaluate(Component $component, string $__path, array $__data): string
    {
        $level = ob_get_level();
        ob_start();
        $state = $this->renderState();

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
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            /**
             * A Blade component in the view that failed made Laravel flush the factory
             * (View::render()): the views around this one go on with the state they had.
             */
            $this->restoreRenderState($state);

            throw $exception;
        }

        $this->views->decrementRender();

        /** A component is its root element: whitespace around it (the view file's last newline) is not part of it. */
        return trim((string) ob_get_clean());
    }

    /**
     * What View::render() flushes when a view fails (Factory::flushState()): the render count,
     * the @once marks, sections, stacks, the component tags open and fragments. Blade keeps them
     * in protected properties, with no API to swap them.
     *
     * @return array<string, mixed>
     */
    private function renderState(): array
    {
        return (function (): array {
            $state = [];

            foreach (BladeEngine::RENDER_STATE as $property) {
                if (property_exists($this, $property)) {
                    $state[$property] = $this->{$property};
                }
            }

            return $state;
        })->call($this->views);
    }

    /** @param  array<string, mixed>  $state */
    private function restoreRenderState(array $state): void
    {
        (function () use ($state): void {
            foreach ($state as $property => $value) {
                $this->{$property} = $value;
            }
        })->call($this->views);
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
