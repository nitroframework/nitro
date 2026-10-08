<?php

namespace Nitro\Fusion\View;

use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Component;
use Nitro\Fusion\Support\Protocol;
use Nitro\Fusion\View\ServerRendering\NullRenderer;
use Nitro\Fusion\View\ServerRendering\ServerRenderer;
use Nitro\Fusion\View\ServerRendering\ViewState;

/**
 * Views written for a JavaScript framework: the app's Vite builds them, and the browser mounts
 * them with the engine Fusion.engine() registered. The server renders the element they mount
 * in, marked with the component's state like any other; with server rendering on, the view's
 * HTML is in it too, and the browser hydrates it.
 *
 * Each fusion.views entry names its adapter: Fusion's (View\Adapters) know
 * their framework's files and where a view keeps its <php> block. An entry without one, for a
 * framework of the app's own, is a ScriptEngine as it is.
 */
class ScriptEngine implements ViewEngine
{
    /**
     * @param  list<string>  $paths  where its views are, in the order looked in (fusion.views)
     * @param  list<string>  $extensions  their file extensions, in the order looked for
     * @param  ServerRenderer  $server  renders a view for the first HTML (fusion.ssr), or leaves it to the browser
     */
    public function __construct(
        protected readonly string $name,
        protected readonly array $paths,
        protected readonly array $extensions,
        protected readonly ServerRenderer $server = new NullRenderer,
    ) {
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

    /** None for a framework of the app's own: its engine gives its views what it chooses. Fusion's adapters' are SharedCore's. */
    public function reservedNames(): array
    {
        return [];
    }

    /** None: Fusion's build has nothing of the view (the app's Vite builds it again when it changes). */
    public function files(ComponentMeta $meta): array
    {
        return [];
    }

    /**
     * The element the view mounts in. With server rendering, the view's HTML is inside it and
     * the element says so (fusion:rendered): the browser hydrates it. Without, or when the view
     * cannot be rendered here, it is empty and the browser renders the view into it.
     */
    public function render(Component $component, ComponentMeta $meta): string
    {
        $this->file($meta);
        $html = $this->server->render(ViewState::of($component, $meta));

        $wrapper = Protocol::get('view')['wrapper'];

        return $html === null ? "<{$wrapper}></{$wrapper}>" : "<{$wrapper} ".Protocol::attribute('serverHtml').">{$html}</{$wrapper}>";
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
