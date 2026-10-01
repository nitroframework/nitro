<?php

namespace Nitro\Fusion\Build;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Factory as ViewFactory;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\ComponentCompiler;
use Nitro\Fusion\Compiler\Functions;
use Nitro\Fusion\Compiler\Template\BladeInliner;
use Nitro\Fusion\Compiler\Template\ComponentTags;
use Nitro\Fusion\Fusion;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The browser's copy of the application's components: app.js with every component compiled, and
 * a chunk for each #[LazyLoad] group (the browser loads it when a page first needs it, as Viewi
 * loads its lazy groups), versioned together by a hash of what they are compiled from.
 *
 * app.js tells the runtime which components are in which chunk, and which Route::fusion() pages
 * render them, so a fusion:navigate visit loads a page's chunk while it fetches the page's data.
 *
 * Two ways to have them, as with Vite: `fusion:build` writes the production bundle, minified with
 * the application's esbuild (templates as JSON the browser parses); without a build, the bundle
 * is compiled on first use (and again whenever a component changes): built as fusion:build does
 * with fusion.minify_on_change, else (or when esbuild cannot run) readable. APP_DEBUG plays no
 * part in either. Each version's files replace the last version's: they do not accumulate.
 */
final class Bundle
{
    private ?string $version = null;

    /** @var array<string, string>|null the components in chunks (name => group): lazy() */
    private ?array $lazy = null;

    /** @var array{routes: list<string>, lang: list<string>, config: list<string>, functions: list<string>, chunks: array<string, string>} what the last compile's helpers named */
    private array $lastUses = ['routes' => [], 'lang' => [], 'config' => [], 'functions' => [], 'chunks' => []];

    /**
     * @param  array{node?: string, root?: string}  $esbuild  the node binary, and the directory
     *                                                        esbuild is installed in
     */
    public function __construct(
        private readonly Fusion $fusion,
        private readonly ViewFactory $views,
        private readonly BladeCompiler $blade,
        private readonly Filesystem $files,
        private readonly string $directory,
        private readonly array $esbuild = [],
        private readonly ?Router $router = null,
        private readonly bool $minifyOnChange = false,
    ) {
    }

    public function version(): string
    {
        return $this->version ??= substr(hash('sha256', implode("\n", array_map(
            fn (string $file) => $file.':'.@filemtime($file),
            $this->sources()
        )).$this->fusion->runtimeVersion().json_encode($this->routes())), 0, 16);
    }

    /**
     * What the browser asks for (app.js?v=...): the version, and ".min" for the build, so a browser
     * that cached the readable bundle does not keep it once there is a build.
     */
    public function tag(): string
    {
        return $this->built() ? $this->version().'.min' : $this->version();
    }

    /** The bundle for the components as they are: the build, or compiled now (readable) if there is none. */
    public function path(): string
    {
        return $this->ensure()[''];
    }

    /** A #[LazyLoad] group's chunk, as path() gives the bundle; null for a group there is not. */
    public function chunkPath(string $group): ?string
    {
        if (! in_array($group, $this->groups(), true)) {
            return null;
        }

        return $this->ensure()[$group] ?? null;
    }

    /** fusion:build: the production bundle and its chunks, minified with esbuild. */
    public function build(): string
    {
        $this->version = null;
        $files = [];

        foreach ($this->compileAll(true) as $group => $code) {
            $files[$this->pathFor($this->version(), $group, true)] = $this->minify($code);
        }
        $files[$this->usesPath()] = $this->usesJson();

        $this->write($files);

        return $this->pathFor($this->version(), '', true);
    }

    public function clear(): void
    {
        $this->files->deleteDirectory($this->directory, preserve: true);
    }

    /** The main bundle, readable. */
    public function compile(): string
    {
        return $this->compileAll()[''];
    }

    /** @return list<string> the #[LazyLoad] groups */
    public function groups(): array
    {
        return array_values(array_unique($this->lazy()));
    }

    /**
     * Every file: '' the main bundle, then each group's chunk.
     *
     * @return array<string, string>
     */
    public function compileAll(bool $build = false): array
    {
        $registry = $this->fusion->registry();
        $compiler = new ComponentCompiler(
            $this->isBladeDirective(...),
            parseTemplates: $build,
            componentName: fn (string $class) => array_search(ltrim($class, '\\'), $registry->all(), true) ?: null,
        );
        $main = [];
        $chunks = [];
        $lazy = $this->lazy();

        foreach (array_keys($this->fusion->registry()->all()) as $name) {
            $meta = $this->fusion->meta($name);
            $viewFile = $this->views->getFinder()->find($meta->view);
            $definition = $compiler->compile($meta, $this->files->get($viewFile), $viewFile);

            if (isset($lazy[$name])) {
                $chunks[$lazy[$name]][] = $definition;
            } else {
                $main[] = $definition;
            }
        }

        if ($lazy !== []) {
            $main[] = '// #[LazyLoad]: component => chunk, and the pages that render them'."\n"
                .'Fusion.lazy('.json_encode((object) $lazy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).', '
                .json_encode($this->routes(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).');';
        }

        /** Viewi's: the PHP functions the components call (and those they use), in the main bundle. */
        if ($compiler->uses['functions'] !== []) {
            array_unshift($main, '// PHP functions (js/functions)'."\n".Functions::js(array_keys($compiler->uses['functions'])));
        }

        $files = ['' => $this->wrap($main)];

        foreach ($chunks as $group => $definitions) {
            $files[$group] = $this->wrap($definitions);
        }

        $this->lastUses = $this->checked($compiler->uses) + ['chunks' => $lazy];

        return $files;
    }

    /**
     * What the compiled code's Laravel helpers name (route names, translation keys, config keys):
     * @fusionScripts ships their values with the page.
     *
     * @return array{routes: list<string>, lang: list<string>, config: list<string>, functions: list<string>, chunks: array<string, string>} (chunks: component => the chunk it is in)
     */
    public function uses(): array
    {
        $this->ensure();

        return json_decode((string) $this->files->get($this->usesPath()), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The uses, sorted; config() only for the keys the application made public.
     *
     * @param  array{routes: array<string, true>, lang: array<string, true>, config: array<string, true>, functions: array<string, true>}  $uses
     * @return array{routes: list<string>, lang: list<string>, config: list<string>, functions: list<string>}
     */
    private function checked(array $uses): array
    {
        $public = (array) $this->fusion->config('public_config', []);

        foreach (array_keys($uses['config']) as $key) {
            if (! in_array($key, $public, true)) {
                throw new CompileException("config('{$key}') runs in the browser, which sees only public config: add '{$key}' to fusion.public_config (its value is sent to every visitor)");
            }
        }

        $sorted = [];

        foreach ($uses as $kind => $names) {
            $names = array_map('strval', array_keys($names));
            sort($names);
            $sorted[$kind] = $names;
        }

        return $sorted;
    }

    private function usesJson(): string
    {
        return json_encode($this->lastUses, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** app-<version>.uses.json: the uses of this version's bundle. */
    private function usesPath(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'app-'.$this->version().'.uses.json';
    }

    /** Whether Blade compiles @name (a built-in directive or one the application registered). */
    public function isBladeDirective(string $name): bool
    {
        return method_exists($this->blade, 'compile'.ucfirst($name)) || array_key_exists($name, $this->blade->getCustomDirectives());
    }

    /** @param  list<string>  $definitions */
    private function wrap(array $definitions): string
    {
        return "(function (Fusion, P, B) {\n\"use strict\";\n".implode("\n\n", $definitions)
            ."\n})(window.Fusion, window.Fusion.P, window.Fusion.B);\n";
    }

    /**
     * The components that are not in app.js, name => chunk: those with #[LazyLoad('group')], and
     * (fusion.split_pages) each Route::fusion() page in a chunk of its own, as Next.js and Nuxt
     * split by page. Components that are not pages (the children pages share) stay in app.js.
     *
     * @return array<string, string>
     */
    private function lazy(): array
    {
        if ($this->lazy === null) {
            $this->lazy = [];
            $pages = $this->fusion->config('split_pages', true) ? $this->pages() : [];

            foreach ($this->fusion->registry()->all() as $name => $class) {
                $group = $this->fusion->meta($name)->lazy;

                if (is_string($group)) {
                    $this->lazy[$name] = $group;
                } elseif ($group === null && isset($pages[ltrim($class, '\\')])) {
                    $this->lazy[$name] = $name;
                }
            }
        }

        return $this->lazy;
    }

    /** @return array<string, true> the component classes Route::fusion() pages render */
    private function pages(): array
    {
        $pages = [];

        foreach ($this->router?->getRoutes()->getRoutes() ?? [] as $route) {
            $controller = $route->getAction('controller');

            if (is_string($controller) && in_array('GET', $route->methods(), true)) {
                $pages[ltrim(explode('@', $controller)[0], '\\')] = true;
            }
        }

        return $pages;
    }

    /**
     * The Route::fusion() pages of #[LazyLoad] components, as [path pattern, group]: a visit to a
     * matching path loads the chunk while it fetches the page. A pattern that matches too much
     * only loads a chunk early; the page's own component name decides what it needs.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function routes(): array
    {
        $lazy = $this->lazy();

        if ($lazy === [] || $this->router === null) {
            return [];
        }

        $groups = [];

        foreach ($lazy as $name => $group) {
            $groups[ltrim((string) $this->fusion->registry()->class($name), '\\')] = $group;
        }

        $routes = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $controller = $route->getAction('controller');
            $class = is_string($controller) ? ltrim(explode('@', $controller)[0], '\\') : null;

            if ($class === null || ! isset($groups[$class]) || $route->getDomain() !== null || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $routes[] = [$this->pattern($route->uri()), $groups[$class]];
        }

        return $routes;
    }

    /** A route's path as a JavaScript regular expression: each {parameter} one path segment. */
    private function pattern(string $uri): string
    {
        $pattern = '';

        foreach (explode('/', trim($uri, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            $pattern .= match (true) {
                preg_match('/^\{\w+\?\}$/', $segment) === 1 => '(?:/[^/]+)?',
                preg_match('/^\{\w+\}$/', $segment) === 1 => '/[^/]+',
                default => '/'.preg_replace_callback('/\{\w+\??\}|[^{]+/', fn (array $m) => $m[0][0] === '{' ? '[^/]+' : preg_quote($m[0], '#'), $segment),
            };
        }

        return '^'.($pattern === '' ? '/' : $pattern).'/?$';
    }

    /**
     * The files for this version, written if they are not there yet: the build's, else built now
     * (minify_on_change), else readable ones compiled now. A build that fails (no node, no esbuild)
     * leaves the readable files, so it is tried once per version, not on every request.
     *
     * @return array<string, string> '' and each group => path
     */
    private function ensure(): array
    {
        $built = $this->built();

        if (! $built && $this->minifyOnChange && ! $this->files->exists($this->pathFor($this->version()))) {
            try {
                $this->build();
                $built = true;
            } catch (RuntimeException $e) {
                report($e);
            }
        }

        $paths = ['' => $this->pathFor($this->version(), '', $built)];

        foreach ($this->groups() as $group) {
            $paths[$group] = $this->pathFor($this->version(), $group, $built);
        }

        if (! $built && array_filter([...$paths, $this->usesPath()], fn (string $path) => ! $this->files->exists($path)) !== []) {
            $files = [];

            foreach ($this->compileAll() as $group => $code) {
                $files[$this->pathFor($this->version(), $group)] = $code;
            }
            $files[$this->usesPath()] = $this->usesJson();

            $this->write($files);
        }

        return $paths;
    }

    private function built(): bool
    {
        return $this->files->exists($this->pathFor($this->version(), '', true));
    }

    /** @return list<string> the files the bundle is compiled from, and the compiler that compiles them */
    private function sources(): array
    {
        $files = $this->files->allFiles(dirname(__DIR__).DIRECTORY_SEPARATOR.'Compiler');
        $files = array_map(fn ($file) => $file->getPathname(), $files);

        $inliner = new BladeInliner($this->views, new ComponentTags($this->blade));

        foreach ($this->fusion->registry()->all() as $name => $class) {
            for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
                $files = [...$files, ...$this->classFiles($reflection)];
            }

            $view = $this->views->getFinder()->find($this->fusion->meta($name)->view);
            /** And the views it includes or uses as <x-components>: they are inlined into it. */
            $files = [...$files, $view, ...$inliner->dependencies($view)];
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * A class's file and those of the traits it uses (their methods are compiled with it).
     *
     * @param  ReflectionClass<object>  $class
     * @return list<string>
     */
    private function classFiles(ReflectionClass $class): array
    {
        $files = $class->getFileName() ? [$class->getFileName()] : [];

        foreach ($class->getTraits() as $trait) {
            $files = [...$files, ...$this->classFiles($trait)];
        }

        return $files;
    }

    /**
     * Minify with the application's esbuild (resources/minify.cjs, run from esbuild.root so that
     * esbuild resolves from its node_modules), as `vite build` does.
     *
     * @throws RuntimeException when node or esbuild is missing, or esbuild rejects the code
     */
    public function minify(string $code): string
    {
        $root = $this->esbuild['root'] ?? getcwd();
        $process = new Process([$this->esbuild['node'] ?? 'node', dirname(__DIR__, 2).'/resources/minify.cjs'], $root, null, $code, 120);

        try {
            $process->run();
        } catch (Throwable $e) {
            throw new RuntimeException('fusion:build minifies with esbuild, run by Node: '.$e->getMessage(), 0, $e);
        }

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new RuntimeException('fusion:build could not minify the components with esbuild: '
                .($error !== '' ? $error : 'node exited with '.$process->getExitCode()).'.');
        }

        return $process->getOutput();
    }

    /**
     * app-<version>.js compiled on first use, app-<version>.min.js from fusion:build; a group's
     * chunk app-<version>.<group>.chunk.js (.chunk.min.js).
     */
    private function pathFor(string $version, string $group = '', bool $built = false): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'app-'.$version.($group === '' ? '' : '.'.$group.'.chunk').($built ? '.min' : '').'.js';
    }

    /**
     * Write a version's files, and remove the files of other versions (nothing asks for them any more).
     *
     * @param  array<string, string>  $files  path => contents
     */
    private function write(array $files): void
    {
        foreach ($files as $path => $contents) {
            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path.'.tmp', $contents);
            $this->files->move($path.'.tmp', $path);
        }

        $keep = array_map(fn (string $path) => realpath($path), array_keys($files));

        foreach ($this->files->glob($this->directory.DIRECTORY_SEPARATOR.'app-*') as $old) {
            if (! in_array(realpath($old), $keep, true)) {
                $this->files->delete($old);
            }
        }
    }
}
