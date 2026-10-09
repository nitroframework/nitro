<?php

namespace Nitro\Build;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Illuminate\View\Compilers\BladeCompiler;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentMeta;
use Nitro\Compiler\Components;
use Nitro\Compiler\Obfuscator;
use Nitro\Compiler\Template\ChildTag;
use Nitro\Compiler\Uses;
use Nitro\NavigationMode;
use Nitro\Support\Config;
use Nitro\Support\Protocol;
use Nitro\Support\Registry;
use Nitro\Support\Signer;
use Nitro\View\ViewEngines;
use RuntimeException;

/**
 * The browser's copy of the application's components: app.js with every component compiled, and
 * a chunk for each group (Chunks: the browser loads it when a page first needs it), versioned
 * together by a hash of what they are compiled from.
 *
 * Two ways to have them, as with Vite: `nitro:build` writes the production bundle, minified with
 * the application's esbuild (templates as JSON the browser parses); without a build, the bundle
 * is compiled on first use (and again whenever a component changes): built as nitro:build does
 * with nitro.minify_on_change, else (or when esbuild cannot run) readable. APP_DEBUG plays no
 * part in either. Each version's files replace the last version's: they do not accumulate.
 *
 * nitro:build also publishes the build as static files (StaticFiles); without them, the Nitro
 * routes serve the files. Its other parts: BundleCompiler, Chunks, CompiledFiles, Sources,
 * Minifier, PageRoutes, BrowserClassFiles (each says what it does).
 */
final class Bundle
{
    private ?string $version = null;

    private ?Obfuscator $obfuscator = null;

    private readonly Minifier $minifier;

    private readonly PageRoutes $pageRoutes;

    private readonly BrowserClassFiles $browserClasses;

    private readonly Chunks $chunks;

    private readonly StaticFiles $static;

    private readonly BundleCompiler $compiler;

    private readonly CompiledFiles $compiled;

    private readonly Sources $sources;

    /** @var array<string, mixed> what the last compile's code uses (Uses::KINDS), its chunks, the components that did not compile */
    private array $lastUses;

    /**
     * @param  array{node?: string, root?: string}  $esbuild  the node binary, and the directory
     *                                                        esbuild is installed in
     * @param  Closure(ComponentMeta): array{title: ?string, head: ?string}  $clientHead  a client page's title and head tags, rendered at build time
     */
    public function __construct(
        Config $config,
        Closure $clientHead,
        private readonly Registry $registry,
        private readonly Components $components,
        private readonly ViewEngines $views,
        private readonly Signer $signer,
        BladeCompiler $blade,
        private readonly Filesystem $files,
        private readonly string $directory,
        array $esbuild = [],
        ?Router $router = null,
        private readonly bool $minifyOnChange = false,
        /** nitro:build's static files: the directory (public/nitro), and its path under public/ for asset(); null: none */
        ?string $staticDirectory = null,
        string $staticPath = 'nitro',
        /** nitro.build.obfuscate: a build names methods and components by aliases (Obfuscator) */
        private readonly bool $obfuscate = false,
        /** nitro.rendering: 'clone' (the default: views' static parts copied in the browser) or 'create' */
        private readonly string $rendering = 'clone',
        /** nitro.navigation: how a visit shows a page without #[Navigate] */
        private readonly NavigationMode $navigation = NavigationMode::Data,
    ) {
        if (! in_array($rendering, ['create', 'clone'], true)) {
            throw new \InvalidArgumentException("nitro.rendering is 'create' or 'clone', not '{$rendering}'");
        }

        $this->minifier = new Minifier($files, $directory.DIRECTORY_SEPARATOR.'minified', $esbuild);
        $this->pageRoutes = new PageRoutes($registry, $router);
        $this->browserClasses = new BrowserClassFiles($files, $directory);
        $this->chunks = new Chunks($registry, $components, $this->pageRoutes);
        $this->static = new StaticFiles($files, $staticDirectory, $staticPath);
        $this->compiler = new BundleCompiler($config, $clientHead, $registry, $components, $views, $blade, $files, $this->chunks, $this->pageRoutes, $rendering, $navigation);
        $this->compiled = new CompiledFiles($files, $directory);
        $this->sources = new Sources($files, $registry, $components, $views, $this->browserClasses);
        $this->lastUses = Uses::none() + ['chunks' => []];
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
            $this->signer->key(),
            array_keys($this->registry->all()),
            aliasComponents: ! $this->usesDynamicComponents(),
        );
    }

    /** Whether a view has <nitro:dynamic-component>: it names components as it runs, so they keep their names. */
    private function usesDynamicComponents(): bool
    {
        foreach (array_keys($this->registry->all()) as $name) {
            $meta = $this->components->meta($name);

            if ($meta->store) {
                continue;
            }

            $engine = $this->views->of($meta);

            if ($engine->compiledByNitro() && str_contains((string) $this->files->get($engine->file($meta)), '<'.Protocol::namespace().ChildTag::DYNAMIC)) {
                return true;
            }
        }

        return false;
    }

    public function version(): string
    {
        return $this->version ??= substr(hash('sha256', implode("\n", array_map(
            fn (string $file) => $file.':'.@filemtime($file),
            $this->sources->all()
        )).$this->runtimeVersion().json_encode($this->chunks->routes())
            /** Obfuscated names come from the key: either changing makes another build. */
            .($this->obfuscate ? 'obfuscate:'.hash('sha256', $this->signer->key()) : '')
            /** Clone mode marks the templates' static parts: another build than create mode's. */
            .($this->rendering === 'clone' ? 'rendering:clone' : '')
            /** Markup-first pages carry their layout and are listed by path: another build. */
            .'navigation:'.$this->navigation->value
            /** The application's own nitro: attributes: a view using one compiles only once it is listed. */
            .'attributes:'.implode(',', $this->compiler->customAttributes())), 0, 16);
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
        $group = $this->chunks->groupFor($id);

        return $group === null ? null : ($this->ensure()[$group] ?? null);
    }

    /** A chunk's name in the browser (Chunks::id()), for the bundle the browser is served. */
    public function chunkId(string $group, ?bool $built = null): string
    {
        return $this->chunks->id($group, $built ?? $this->built());
    }

    /** The group a chunk name (chunkId()) stands for. */
    public function groupFor(string $id): ?string
    {
        return $this->chunks->groupFor($id);
    }

    /** @return list<string> the groups, a chunk each */
    public function groups(): array
    {
        return $this->chunks->groups();
    }

    /**
     * Where the build's static copy of the bundle ('') or a group's chunk is, under public/
     * (nitro/app-<version>.js, nitro/<chunk id>-<version>.js); null when there is none for the
     * components as they are (no build, a change since, nitro.static off).
     */
    public function staticFile(string $group = ''): ?string
    {
        return $this->built() ? $this->static->url($this->staticName($group)) : null;
    }

    /** The chunks' static files as the runtime asks for them: {id} stands for the chunk's name; null when there are none. */
    public function staticChunkPattern(): ?string
    {
        return $this->staticFile() === null ? null : $this->static->pattern('{id}-'.$this->version().'.js');
    }

    /** The runtime's static copy under public/ (nitro/nitro-<version>.js), null when there is none. */
    public function staticRuntime(): ?string
    {
        return $this->static->url($this->runtimeName());
    }

    private function staticName(string $group): string
    {
        return ($group === '' ? 'app' : $this->chunks->id($group, true)).'-'.$this->version().'.js';
    }

    private function runtimeName(): string
    {
        return 'nitro-'.$this->runtimeVersion().'.js';
    }

    /**
     * The production bundle and its chunks, minified with esbuild; with $static (nitro:build),
     * published to nitro.static as well, with the runtime.
     *
     * $report hears how it goes (nitro:build prints it):
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
        $publishing = $static && $this->static->directory() !== null;

        $report('compiling', count($this->registry->all()));
        $started = microtime(true);
        $chunks = $this->compileAll(true, fn (string $name) => $report('component', $name));
        $report('compiled', microtime(true) - $started);

        $report('minifying', count($chunks));
        $started = microtime(true);
        $minifiedNow = 0;
        $minified = $this->minifyAll($chunks, $minifiedNow, prune: true);
        $report('minified', microtime(true) - $started, $minifiedNow);

        foreach ($minified as $group => $code) {
            $files[$this->compiled->path($this->version(), $group, true)] = $code;
            $published[$this->staticName($group)] = $code;
        }
        $files[$this->compiled->usesPath($this->version())] = $this->usesJson();

        $this->compiled->write($files);

        $runtime = (string) file_get_contents($this->runtimePath());

        if ($publishing) {
            $published[$this->runtimeName()] = $runtime;
            $report('publishing', $this->static->directory(), count($published));
            $started = microtime(true);
            $this->static->publish($published);
            $report('published', microtime(true) - $started);
        }

        $report('built', $this->summary($minified, $runtime, $publishing));

        return $this->compiled->path($this->version(), '', true);
    }

    /**
     * What a build made, for nitro:build to show: the version, whether it is obfuscated, where it
     * was published (null: not published), the runtime, and each file of the bundle with its size
     * and the components and pages in it ('' the main bundle, then the files loaded on demand).
     *
     * @param  array<string, string>  $minified  group => code
     * @return array{version: string, obfuscated: bool, directory: ?string, runtime: array{name: string, bytes: int}, files: list<array{group: string, name: string, bytes: int, members: list<string>}>}
     */
    private function summary(array $minified, string $runtime, bool $published): array
    {
        $map = $this->chunks->map();
        $files = [];

        foreach ($minified as $group => $code) {
            $files[] = [
                'group' => $group,
                'name' => $published ? $this->staticName($group) : basename($this->compiled->path($this->version(), $group, true)),
                'bytes' => strlen($code),
                'members' => array_values(array_filter(
                    array_keys($this->registry->all()),
                    fn (string $name) => ($map[$name] ?? '') === $group,
                )),
            ];
        }

        return [
            'version' => $this->version(),
            'obfuscated' => $this->obfuscate,
            'directory' => $published ? $this->static->directory() : null,
            'runtime' => ['name' => $this->runtimeName(), 'bytes' => strlen($runtime)],
            'files' => $files,
        ];
    }

    /** Remove the compiled files, and the published ones. */
    public function clear(): void
    {
        /** Nitro's files only (app-*, classes.json, the minified cache): the directory's own, such as a .gitignore, stay. */
        $this->compiled->clear();
        $this->browserClasses->clear();

        $this->files->deleteDirectory($this->directory.DIRECTORY_SEPARATOR.'minified');
        $this->static->publish([]);
    }

    /** The main bundle, readable. */
    public function compile(): string
    {
        return $this->compileAll()[''];
    }

    /**
     * Every file: '' the main bundle, then each group's chunk (BundleCompiler). $compiling hears
     * each component as it is compiled.
     *
     * @return array<string, string>
     */
    public function compileAll(bool $build = false, ?Closure $compiling = null): array
    {
        $compiled = $this->compiler->compile($build, $this->obfuscator($build), $compiling);

        /** Which #[Browser] classes the components reach: when the recorded ones change, so does the version. */
        if ($this->browserClasses->record($compiled['classes'])) {
            $this->version = null;
        }

        $this->lastUses = $compiled['uses'];

        return $compiled['files'];
    }

    /**
     * What the compiled code uses (Compiler\Uses): @nitroScripts ships their values with the page;
     * and its chunks (component => the chunk it is in), and the components that did not compile.
     *
     * @return array<string, mixed>
     */
    public function uses(): array
    {
        $this->ensure();

        return $this->compiled->uses($this->version()) ?? [];
    }

    /** @return array<string, string> each component in a chunk => its group, for the bundle served */
    public function inChunks(): array
    {
        return $this->uses()['chunks'] ?? [];
    }

    /**
     * In development: fail with the compile error of the first of these components that does not
     * compile (a page that uses one cannot work in the browser). Pages without one are not held up.
     *
     * @param  list<string>  $names
     *
     * @throws CompileException
     */
    public function assertCompiled(array $names): void
    {
        $broken = $this->uses()['broken'] ?? [];

        foreach ($names as $name) {
            if (isset($broken[$name])) {
                throw new CompileException($broken[$name]['message'], $broken[$name]['file'], $broken[$name]['line']);
            }
        }
    }

    private function usesJson(): string
    {
        return json_encode($this->lastUses, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Whether Blade compiles @name (a built-in directive or one the application registered). */
    public function isBladeDirective(string $name): bool
    {
        return $this->compiler->isBladeDirective($name);
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

        /** While a component does not compile, the build would fail on every request: the readable bundle serves meanwhile. */
        if (! $built && $this->minifyOnChange && ! $this->compiled->exists($this->compiled->path($this->version())) && (($this->compiled->uses($this->version()) ?? [])['broken'] ?? []) === []) {
            try {
                $this->build();
                $built = true;
            } catch (RuntimeException $exception) {
                report($exception);
            }
        }

        $paths = $this->paths($built);

        if (! $built && array_filter([...$paths, $this->compiled->usesPath($this->version())], fn (string $path) => ! $this->compiled->exists($path)) !== []) {
            $files = [];

            /** Compiling can change the version (the #[Browser] classes it reaches): the files go under the new one. */
            foreach ($this->compileAll() as $group => $code) {
                $files[$this->compiled->path($this->version(), $group)] = $code;
            }
            $files[$this->compiled->usesPath($this->version())] = $this->usesJson();

            $this->compiled->write($files);
            $paths = $this->paths($built);
        }

        return $paths;
    }

    /** @return array<string, string> group => its file, '' the main bundle */
    private function paths(bool $built): array
    {
        $paths = ['' => $this->compiled->path($this->version(), '', $built)];

        foreach ($this->chunks->groups() as $group) {
            $paths[$group] = $this->compiled->path($this->version(), $group, $built);
        }

        return $paths;
    }

    private function built(): bool
    {
        return $this->compiled->exists($this->compiled->path($this->version(), '', true));
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
     * Minify every chunk (name => code) in one Node process, esbuild minifying them in parallel;
     * a chunk minified before comes from the cache (Minifier). $minified tells how many were.
     *
     * @param  array<string, string>  $chunks
     * @return array<string, string>
     *
     * @throws RuntimeException when node or esbuild is missing, or esbuild rejects the code
     */
    public function minifyAll(array $chunks, int &$minified = 0, bool $prune = false): array
    {
        return $this->minifier->minify($chunks, $minified, $prune);
    }

    /** The browser runtime, as this version of Nitro ships it. */
    public function runtimePath(): string
    {
        return dirname(__DIR__, 2).'/dist/nitro.js';
    }

    public function runtimeVersion(): string
    {
        return substr((string) @md5_file($this->runtimePath()), 0, 12);
    }
}
