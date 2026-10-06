<?php

namespace Nitro\Fusion\View;

use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Component;

/**
 * Views written for a JavaScript framework: the app's Vite builds them, and the browser mounts
 * them with the engine Fusion.engine() registered. The server renders the element they mount
 * in, marked with the component's state like any other.
 *
 * Each fusion.views entry names its adapter: Fusion's (View\Adapters: Vue, React, Solid) know
 * their framework's files and where a view keeps its <php> block. An entry without one, for a
 * framework of the app's own, is a ScriptEngine as it is.
 */
class ScriptEngine implements ViewEngine
{
    /**
     * @param  list<string>  $paths  where its views are, in the order looked in (fusion.views)
     * @param  list<string>  $extensions  their file extensions, in the order looked for
     */
    public function __construct(protected readonly string $name, protected readonly array $paths, protected readonly array $extensions)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return ucfirst($this->name);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return $this->extensions;
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

    /**
     * The element the view mounts in: the browser renders it, from the state on it.
     *
     * Planned: server rendering, where this asks a Node service for the view's HTML with the
     * component's state, and the browser's engine hydrates it instead of mounting.
     */
    public function render(Component $component, ComponentMeta $meta): string
    {
        $this->file($meta);

        return '<div></div>';
    }

    /**
     * The component's PHP, when the view holds it (View\ColocatedViews): its code and the line it
     * starts on; null when the view has none. As the file's type writes it: a script's in a
     * leading comment, any other's in a <php> element.
     *
     * @return array{0: string, 1: int}|null
     */
    public function phpBlock(string $source, string $file): ?array
    {
        return preg_match('/\.(jsx|tsx|js|ts)$/', $file) === 1 ? self::commentBlock($source) : self::elementBlock($source);
    }

    /**
     * <php> ... </php>
     *
     * @return array{0: string, 1: int}|null
     */
    protected static function elementBlock(string $source): ?array
    {
        return self::block('/<php>(.*?)<\/php>/s', $source);
    }

    /**
     * / * <php> ... </php> * /: a comment, so the file stays JavaScript.
     *
     * @return array{0: string, 1: int}|null
     */
    protected static function commentBlock(string $source): ?array
    {
        return self::block('/\/\*\s*<php>(.*?)<\/php>\s*\*\//s', $source);
    }

    /** @return array{0: string, 1: int}|null */
    private static function block(string $pattern, string $source): ?array
    {
        if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return [$match[1][0], substr_count($source, "\n", 0, $match[1][1]) + 1];
    }
}
