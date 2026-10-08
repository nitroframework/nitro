<?php

namespace Nitro\Fusion\View;

use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\ComponentSlot;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Factory as ViewFactory;
use LogicException;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Compiler\StateProperties;
use Nitro\Fusion\Compiler\Template\BladeInliner;
use Nitro\Fusion\Compiler\Template\ComponentTags;
use Nitro\Fusion\Component;
use Nitro\Fusion\Support\Protocol;
use Throwable;

/**
 * Blade views: rendered on the server by Blade itself, and compiled by Fusion for the browser.
 * The view sees the component's properties as variables and the component as $this, and its
 * loops start fresh, so $loop and child keys depend on the component's own view only.
 */
final class BladeEngine implements ViewEngine
{
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

    public function compiledByFusion(): bool
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
            throw new LogicException('Fusion renders views with Blade\'s compiler engine.');
        }

        $compiler = $engine->getCompiler();

        if ($compiler->isExpired($path)) {
            $compiler->compile($path);
        }

        $errors = new ViewErrorBag;
        $errors->put('default', $component->fusion()->errors);

        $slots = $component->fusion()->slots;
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

        try {
            return $this->evaluate($component, $compiler->getCompiledPath($path), $data);
        } finally {
            $this->restoreLoops($loops);
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
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $exception;
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
