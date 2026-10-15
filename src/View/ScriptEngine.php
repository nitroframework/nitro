<?php

namespace Nitro\View;

use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentMeta;
use Nitro\Component;
use Nitro\Support\Naming;
use Nitro\Support\Protocol;
use Nitro\View\ServerRendering\NullRenderer;
use Nitro\View\ServerRendering\ServerRenderer;
use Nitro\View\ServerRendering\ViewState;

/**
 * Views written for a JavaScript framework: the app's Vite builds them, and the browser mounts
 * them with the engine Nitro.engine() registered. The server renders the element they mount
 * in, marked with the component's state like any other; with server rendering on, the view's
 * HTML is in it too, and the browser hydrates it.
 *
 * Each nitro.views entry names its adapter: Nitro's (View\Adapters) know
 * their framework's files and where a view keeps its <php> block. An entry without one, for a
 * framework of the app's own, is a ScriptEngine as it is.
 */
class ScriptEngine implements ViewEngine
{
    /** @var array<string, list<string>> each namespace's paths, by its name */
    private array $namespaces = [];

    /**
     * @param  list<string>  $paths  where its views are, in the order looked in (nitro.views)
     * @param  list<string>  $extensions  their file extensions, in the order looked for
     * @param  ServerRenderer  $server  renders a view for the first HTML (nitro.ssr), or leaves it to the browser
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

    /**
     * Where its views are: the app's, or a namespace's ("issues::list" is list in those).
     *
     * @return list<string>
     */
    public function paths(?string $namespace = null): array
    {
        return $namespace === null ? $this->paths : $this->namespaces[$namespace] ?? [];
    }

    /**
     * The namespaces that have views for it (nitro.namespaces.*.views).
     *
     * @return list<string>
     */
    public function namespaces(): array
    {
        return array_keys($this->namespaces);
    }

    /**
     * A namespace's views for it, from nitro.namespaces.
     *
     * @internal
     *
     * @param  list<string>  $paths
     */
    public function addNamespace(string $namespace, array $paths): void
    {
        $this->namespaces[$namespace] = $paths;
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return $this->extensions;
    }

    /** The view's file: in the first of its paths that has it (a namespace's, for "issues::list"). */
    public function file(ComponentMeta $meta): string
    {
        return $this->find($meta)[0];
    }

    /** The view by its path under its views ("auth/login", "issues::list"): what the browser and the render service find. */
    public function resolved(ComponentMeta $meta): string
    {
        return $this->find($meta)[1];
    }

    /**
     * A view's file and path. Its name's slashes and dots are folders, as a Blade view's dots
     * are: "auth.login" and "auth/login" are auth/login.vue. The name as written is looked for
     * first, dots kept in the file's name (chart.client.jsx); when both are there, the build says
     * so rather than choose.
     *
     * @return array{0: string, 1: string} the file, and the view's path
     */
    private function find(ComponentMeta $meta): array
    {
        [$namespace, $view] = Naming::split($meta->view);

        if ($namespace !== null && ! isset($this->namespaces[$namespace])) {
            throw new CompileException("View [{$meta->view}] of {$meta->class} not found: [{$namespace}] has no {$this->name} views (nitro.namespaces.{$namespace}.views.{$this->name}).", $meta->file);
        }

        $tried = [];
        $found = [];

        foreach (array_unique([str_replace('\\', '/', $view), str_replace(['.', '\\'], '/', $view)]) as $path) {
            foreach ($this->paths($namespace) as $root) {
                $base = rtrim($root, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);

                foreach ($this->extensions as $extension) {
                    if (is_file($tried[] = $base.'.'.$extension)) {
                        $found[$path] = $base.'.'.$extension;

                        continue 3;
                    }
                }
            }
        }

        if (count($found) > 1) {
            throw new CompileException("View [{$meta->view}] of {$meta->class} is two files: ".implode(' and ', $found).'. Rename one, or name the view by its path with slashes.', $meta->file);
        }

        if ($found === []) {
            throw new CompileException("View [{$meta->view}] of {$meta->class} not found: no ".implode(' or ', $tried).'.', $meta->file);
        }

        return [reset($found), Naming::within($namespace, (string) key($found))];
    }

    public function compiledByNitro(): bool
    {
        return false;
    }

    /** None for a framework of the app's own: its engine gives its views what it chooses. Nitro's adapters' are SharedCore's. */
    public function reservedNames(): array
    {
        return [];
    }

    /** None: Nitro's build has nothing of the view (the app's Vite builds it again when it changes). */
    public function files(ComponentMeta $meta): array
    {
        return [];
    }

    /**
     * The element the view mounts in. With server rendering, the view's HTML is inside it and
     * the element says so (nitro:rendered): the browser hydrates it. Without, or when the view
     * cannot be rendered here, it is empty and the browser renders the view into it.
     */
    public function render(Component $component, ComponentMeta $meta): string
    {
        $html = $this->server->render(ViewState::of($component, $meta, $this->resolved($meta)));

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
