<?php

namespace Nitro\View;

use Closure;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\ComponentSlot;
use Illuminate\View\Factory as ViewFactory;
use Nitro\Compiler\ComponentMeta;
use Nitro\Compiler\StateProperties;
use Nitro\Compiler\Template\BladeInliner;
use Nitro\Compiler\Template\ComponentTags;
use Nitro\Component;
use Nitro\Support\Protocol;

/**
 * Blade views, compiled by Nitro: to the tree the browser renders, and on the server the same tree
 * rendered with its expressions' PHP twins (ServerViews, TreeRenderer), so both render one view
 * by the same rules. The view sees the component's properties as variables and the component as
 * $this; its loops start fresh, so $loop and child keys depend on the component's own view only.
 */
final class BladeEngine implements ViewEngine
{
    private ?BladeInliner $inliner = null;

    /**
     * @param  Closure(): ServerViews  $serverViews  the views as the server renders them
     * @param  Closure(): TreeHost  $host  where their children and @teleport content go
     */
    public function __construct(
        private readonly ViewFactory $views,
        private readonly BladeCompiler $blade,
        private readonly Closure $serverViews,
        private readonly Closure $host,
    ) {
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

        /** What a Blade component's view in it is given: the shared data, and the component's $errors (as its compiled view reads them in the browser). */
        $shared = [...$this->views->getShared(), 'errors' => $errors, '__env' => $this->views];
        $view = ($this->serverViews)()->of($meta, $path);

        /** A component is its root element: whitespace around it (the view file's last newline) is not part of it. */
        return trim((new TreeRenderer(($this->host)(), $view['calls'], $shared))->render($view, $component, $data, $slots));
    }
}
