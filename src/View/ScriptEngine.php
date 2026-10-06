<?php

namespace Nitro\Fusion\View;

use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Component;

/**
 * Views written for a JavaScript framework (Vue, React, Solid, or the app's own engine): the
 * app's Vite builds them, and the browser mounts them with the engine Fusion.engine() registered.
 * The server renders the element they mount in; the component's root, marked with its state
 * like any other.
 */
final class ScriptEngine implements ViewEngine
{
    /**
     * @param  list<string>  $paths  where its views are, in the order looked in (fusion.views)
     * @param  list<string>  $extensions  their file extensions, in the order looked for
     */
    public function __construct(private readonly string $name, private readonly array $paths, private readonly array $extensions)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    /** The view's file: in the first of its paths that has it. */
    public function file(ComponentMeta $meta): string
    {
        $tried = [];

        foreach ($this->paths as $path) {
            $base = rtrim($path, '/\\').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $meta->view);

            foreach ($this->extensions as $extension) {
                if (is_file($tried[] = $base.'.'.$extension)) {
                    return $base.'.'.$extension;
                }
            }
        }

        throw new CompileException("View [{$meta->view}] of {$meta->class} not found: no ".implode(' or ', $tried).'.', $meta->file);
    }

    public function compiledByFusion(): bool
    {
        return false;
    }

    /** None: Fusion's build has nothing of the view (the app's Vite builds it again when it changes). */
    public function files(ComponentMeta $meta): array
    {
        return [];
    }

    /** The element the view mounts in: the browser renders it, from the state on it. */
    public function render(Component $component, ComponentMeta $meta): string
    {
        $this->file($meta);

        return '<div></div>';
    }
}
