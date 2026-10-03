<?php

namespace Nitro\Fusion\Build;

use Closure;
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
 *
 * fusion:build also publishes the build as static files (fusion.static, public/fusion), as
 * `vite build` writes public/build: the web server (or a CDN) serves them without PHP, and
 * @fusionScripts points at them. Their names carry the version, and a chunk is named by a hash
 * of its group, as Vite names its chunks. Without them (a change since the build, or none), the
 * Fusion routes serve the files.
 */
final class Bundle
{
    private ?string $version = null;

    private ?Obfuscator $obfuscator = null;

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
        /** fusion:build's static files: the directory (public/fusion), and its path under public/ for asset(); null: none */
        private readonly ?string $staticDirectory = null,
        private readonly string $staticPath = 'fusion',
        /** fusion.build.obfuscate: a build names methods and components by aliases (Obfuscator) */
        private readonly bool $obfuscate = false,
        /** fusion.rendering: 'clone' (the default: views' static parts copied in the browser) or 'create' */
        private readonly string $rendering = 'clone',
    ) {
        if (! in_array($rendering, ['create', 'clone'], true)) {
            throw new \InvalidArgumentException("fusion.rendering is 'create' or 'clone', not '{$rendering}'");
        }
    }

    /**
     * The names the browser has for methods and components, when the bundle it is served is an
     * obfuscated build; null when it has their own names.
     */
    public function obfuscator(?bool $built = null): ?Obfuscator
    {
        if (! $this->obfuscate || ! ($built ?? $this->built())) {
            return null;
        }

        return $this->obfuscator ??= new Obfuscator(
            $this->fusion->key(),
            array_keys($this->fusion->registry()->all()),
            aliasComponents: ! $this->usesDynamicComponents(),
        );
    }

    /** Whether a view has <fusion:dynamic-component>: it names components as it runs, so they keep their names. */
    private function usesDynamicComponents(): bool
    {
        foreach (array_keys($this->fusion->registry()->all()) as $name) {
            $view = $this->views->getFinder()->find($this->fusion->meta($name)->view);

            if (str_contains((string) $this->files->get($view), '<fusion:dynamic-component')) {
                return true;
            }
        }

        return false;
    }

    public function version(): string
    {
        return $this->version ??= substr(hash('sha256', implode("\n", array_map(
            fn (string $file) => $file.':'.@filemtime($file),
            $this->sources()
        )).$this->fusion->runtimeVersion().json_encode($this->routes())
            /** Obfuscated names come from the key: either changing makes another build. */
            .($this->obfuscate ? 'obfuscate:'.hash('sha256', $this->fusion->key()) : '')
            /** Clone mode marks the templates' static parts: another build than create mode's. */
            .($this->rendering === 'clone' ? 'rendering:clone' : '')), 0, 16);
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

    /** A chunk, by the name the browser asks for it (chunkId()), as path() gives the bundle; null for none. */
    public function chunkPath(string $id): ?string
    {
        $group = $this->groupFor($id);

        return $group === null ? null : ($this->ensure()[$group] ?? null);
    }

    /**
     * A chunk's name in the browser: in a build, a short hash of its group (as Vite names chunks);
     * otherwise the group itself, readable while developing.
     */
    public function chunkId(string $group, ?bool $built = null): string
    {
        return ($built ?? $this->built()) ? substr(hash('sha256', 'fusion-chunk:'.$group), 0, 8) : $group;
    }

    /** The group a chunk name (chunkId()) stands for: by its hash, or by its group's own name. */
    public function groupFor(string $id): ?string
    {
        foreach ($this->groups() as $group) {
            if ($id === $group || $id === $this->chunkId($group, true)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Where the build's static copy of the bundle ('') or a group's chunk is, under public/
     * (fusion/app-<version>.js, fusion/<chunk id>-<version>.js); null when there is none for the
     * components as they are (no build, a change since, fusion.static off).
     */
    public function staticFile(string $group = ''): ?string
    {
        if ($this->staticDirectory === null || ! $this->built()) {
            return null;
        }

        $name = $this->staticName($group);

        return $this->files->exists($this->staticDirectory.DIRECTORY_SEPARATOR.$name) ? $this->staticPath.'/'.$name : null;
    }

    /** The chunks' static files as the runtime asks for them: {id} stands for the chunk's name; null when there are none. */
    public function staticChunkPattern(): ?string
    {
        return $this->staticFile() === null ? null : $this->staticPath.'/{id}-'.$this->version().'.js';
    }

    /** The runtime's static copy under public/ (fusion/fusion-<version>.js), null when there is none. */
    public function staticRuntime(): ?string
    {
        if ($this->staticDirectory === null) {
            return null;
        }

        $name = 'fusion-'.$this->fusion->runtimeVersion().'.js';

        return $this->files->exists($this->staticDirectory.DIRECTORY_SEPARATOR.$name) ? $this->staticPath.'/'.$name : null;
    }

    private function staticName(string $group): string
    {
        return ($group === '' ? 'app' : $this->chunkId($group, true)).'-'.$this->version().'.js';
    }

    /**
     * The production bundle and its chunks, minified with esbuild; with $static (fusion:build),
     * published to fusion.static as well, with the runtime.
     *
     * $report hears how it goes (fusion:build prints it):
     *   ('compiling', count), ('component', name) for each, ('compiled', seconds),
     *   ('minifying', chunks), ('minified', seconds, how many were minified rather than cached),
     *   ('publishing', directory, files), ('published', seconds),
     *   ('built', summary): what was built (see summary())
     *
     * @param  (Closure(string, mixed...): void)|null  $report
     */
    public function build(bool $static = false, ?Closure $report = null): string
    {
        $report ??= function (): void {};
        $this->version = null;
        $files = [];
        $published = [];
        $publishing = $static && $this->staticDirectory !== null;

        $report('compiling', count($this->fusion->registry()->all()));
        $started = microtime(true);
        $chunks = $this->compileAll(true, fn (string $name) => $report('component', $name));
        $report('compiled', microtime(true) - $started);

        $report('minifying', count($chunks));
        $started = microtime(true);
        $minifiedNow = 0;
        $minified = $this->minifyAll($chunks, $minifiedNow, prune: true);
        $report('minified', microtime(true) - $started, $minifiedNow);

        foreach ($minified as $group => $code) {
            $files[$this->pathFor($this->version(), $group, true)] = $code;
            $published[$this->staticName($group)] = $code;
        }
        $files[$this->usesPath()] = $this->usesJson();

        $this->write($files);

        $runtime = (string) file_get_contents($this->fusion->runtimePath());

        if ($publishing) {
            $published['fusion-'.$this->fusion->runtimeVersion().'.js'] = $runtime;
            $report('publishing', $this->staticDirectory, count($published));
            $started = microtime(true);
            $this->publish($published);
            $report('published', microtime(true) - $started);
        }

        $report('built', $this->summary($minified, $runtime, $publishing));

        return $this->pathFor($this->version(), '', true);
    }

    /**
     * What a build made, for fusion:build to show: the version, whether it is obfuscated, where it
     * was published (null: not published), the runtime, and each file of the bundle with its size
     * and the components and pages in it ('' the main bundle, then the files loaded on demand).
     *
     * @param  array<string, string>  $minified  group => code
     * @return array{version: string, obfuscated: bool, directory: ?string, runtime: array{name: string, bytes: int}, files: list<array{group: string, name: string, bytes: int, members: list<string>}>}
     */
    private function summary(array $minified, string $runtime, bool $published): array
    {
        $lazy = $this->lazy();
        $files = [];

        foreach ($minified as $group => $code) {
            $files[] = [
                'group' => $group,
                'name' => $published ? $this->staticName($group) : basename($this->pathFor($this->version(), $group, true)),
                'bytes' => strlen($code),
                'members' => array_values(array_filter(
                    array_keys($this->fusion->registry()->all()),
                    fn (string $name) => ($lazy[$name] ?? '') === $group,
                )),
            ];
        }

        return [
            'version' => $this->version(),
            'obfuscated' => $this->obfuscate,
            'directory' => $published ? $this->staticDirectory : null,
            'runtime' => ['name' => 'fusion-'.$this->fusion->runtimeVersion().'.js', 'bytes' => strlen($runtime)],
            'files' => $files,
        ];
    }

    /** Remove the compiled files, and the published ones. */
    public function clear(): void
    {
        $this->files->deleteDirectory($this->directory, preserve: true);
        $this->publish([]);
    }

    /**
     * Write the static files (name => contents) to fusion.static, and remove the ones of other
     * builds: only files named as Fusion names them, so the directory may hold others.
     *
     * @param  array<string, string>  $files
     */
    private function publish(array $files): void
    {
        if ($this->staticDirectory === null) {
            return;
        }

        foreach ($files as $name => $contents) {
            $path = $this->staticDirectory.DIRECTORY_SEPARATOR.$name;
            $this->files->ensureDirectoryExists($this->staticDirectory);
            $this->files->put($path.'.tmp', $contents);
            $this->files->move($path.'.tmp', $path);
        }

        foreach ($this->files->glob($this->staticDirectory.DIRECTORY_SEPARATOR.'*.js') as $old) {
            $name = basename($old);

            if (! isset($files[$name]) && preg_match('/^(app|fusion|[0-9a-f]{8})-[0-9a-f]{12,16}\.js$/', $name) === 1) {
                $this->files->delete($old);
            }
        }
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
     * Every file: '' the main bundle, then each group's chunk. $compiling hears each component
     * as it is compiled (fusion:build -v lists them).
     *
     * @return array<string, string>
     */
    public function compileAll(bool $build = false, ?Closure $compiling = null): array
    {
        $registry = $this->fusion->registry();
        $names = $this->obfuscator($build);
        $names?->assertDistinct(array_keys($registry->all()), 'component');
        $compiler = new ComponentCompiler(
            $this->isBladeDirective(...),
            parseTemplates: $build,
            componentName: fn (string $class) => array_search(ltrim($class, '\\'), $registry->all(), true) ?: null,
            names: $names,
            cloneStatic: $this->rendering === 'clone',
        );
        $main = [];
        $chunks = [];
        $lazy = $this->lazy();

        foreach (array_keys($this->fusion->registry()->all()) as $name) {
            if ($compiling !== null) {
                $compiling($name);
            }

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
            /** The chunks by the names the browser asks for them: hashed in a build (chunkId()). */
            $ids = [];

            foreach ($lazy as $name => $group) {
                $ids[$names?->component($name) ?? $name] = $this->chunkId($group, $build);
            }
            $routes = array_map(fn (array $route) => [$route[0], $this->chunkId($route[1], $build)], $this->routes());

            $main[] = '// #[LazyLoad]: component => chunk, and the pages that render them'."\n"
                .'Fusion.lazy('.json_encode((object) $ids, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).', '
                .json_encode($routes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).');';
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
     * What is not in app.js, name => chunk: every page, in a chunk of its own (or its #[LazyLoad]
     * group), as Next.js and Nuxt split by page; and components with #[LazyLoad]. The components
     * pages share stay in app.js.
     *
     * @return array<string, string>
     */
    private function lazy(): array
    {
        if ($this->lazy === null) {
            $this->lazy = [];

            foreach (array_keys($this->fusion->registry()->all()) as $name) {
                $group = $this->fusion->meta($name)->lazy;

                if ($group !== null) {
                    $this->lazy[$name] = $group;
                }
            }
        }

        return $this->lazy;
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
        return $this->minifyAll(['' => $code])[''];
    }

    /**
     * Minify every chunk (name => code) in one Node process (starting Node, ~0.2s, is most of the
     * cost of minifying one chunk), esbuild minifying them in parallel. A chunk minified before
     * (the same code, minifier and esbuild) comes from the cache in the compiled directory, so a
     * build where one page changed minifies that page only. $minified tells how many were.
     *
     * @param  array<string, string>  $chunks
     * @return array<string, string>
     *
     * @throws RuntimeException when node or esbuild is missing, or esbuild rejects the code
     */
    public function minifyAll(array $chunks, ?int &$minified = null, bool $prune = false): array
    {
        $root = $this->esbuild['root'] ?? getcwd();
        $script = dirname(__DIR__, 2).'/resources/minify.cjs';
        $cache = $this->directory.DIRECTORY_SEPARATOR.'minified';
        $tool = hash('sha256', (string) @file_get_contents($script).(string) @file_get_contents($root.'/node_modules/esbuild/package.json'));

        $out = [];
        $keys = [];
        $missing = [];

        foreach ($chunks as $name => $code) {
            $keys[$name] = $cache.DIRECTORY_SEPARATOR.hash('sha256', $tool."\0".$code).'.js';

            if ($this->files->exists($keys[$name])) {
                $out[$name] = (string) $this->files->get($keys[$name]);
            } else {
                $missing[$name] = $code;
            }
        }

        $minified = count($missing);

        if ($missing !== []) {
            $process = new Process([$this->esbuild['node'] ?? 'node', $script, '--batch'], $root, null, json_encode((object) $missing, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 300);

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

            $this->files->ensureDirectoryExists($cache);

            foreach (json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR) as $name => $code) {
                $out[$name] = $code;
                $this->files->put($keys[$name], $code);
            }
        }

        /** A build's cache keeps what it used: a chunk that changed leaves its old minified code behind. */
        if ($prune) {
            $used = array_map(fn (string $path) => basename($path), $keys);

            foreach ($this->files->glob($cache.DIRECTORY_SEPARATOR.'*.js') as $old) {
                if (! in_array(basename($old), $used, true)) {
                    $this->files->delete($old);
                }
            }
        }

        return array_replace($chunks, $out);
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
