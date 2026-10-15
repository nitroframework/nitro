<?php

namespace Nitro\Build;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentCompiler;
use Nitro\Compiler\ComponentMeta;
use Nitro\Compiler\Components;
use Nitro\Compiler\Functions;
use Nitro\Compiler\Globals;
use Nitro\Compiler\Obfuscator;
use Nitro\Compiler\Template\ComponentViews;
use Nitro\Compiler\Template\TemplateCompiler;
use Nitro\Compiler\Uses;
use Nitro\NavigationMode;
use Nitro\Support\Config;
use Nitro\Support\LayoutKey;
use Nitro\Support\Registry;
use Nitro\View\ViewEngines;

/**
 * Every component compiled into the browser's files: the main bundle ('') and a chunk for each
 * group (Chunks), with what tells the runtime where things are: which chunk a component is in and
 * which pages render it, and the markup-first pages by path. Bundle decides when, and where they go.
 */
final class BundleCompiler
{
    public function __construct(
        private readonly Config $config,
        /** @var Closure(ComponentMeta): array{title: ?string, head: ?string} a client page's title and head tags, rendered at build time */
        private readonly Closure $clientHead,
        private readonly Registry $registry,
        private readonly Components $components,
        private readonly ViewEngines $views,
        private readonly BladeCompiler $blade,
        private readonly Filesystem $files,
        private readonly Chunks $chunks,
        private readonly PageRoutes $pageRoutes,
        /** nitro.rendering: 'clone' (views' static parts copied in the browser) or 'create' */
        private readonly string $rendering,
        /** nitro.navigation: how a visit shows a page without #[Navigate] */
        private readonly NavigationMode $navigation,
    ) {
    }

    /**
     * Every file: '' the main bundle, then each group's chunk; and what the code uses, the
     * #[Browser] classes it reaches, and the components that did not compile. $compiling hears
     * each component as it is compiled (nitro:build -v lists them).
     *
     * A build ($build) fails on the first component that does not compile. In development, such a
     * component is left out, with its error (uses' broken): the pages that use it fail with that
     * error, the others work.
     *
     * @return array{files: array<string, string>, uses: array<string, mixed>, classes: list<string>}
     */
    public function compile(bool $build, ?Obfuscator $names, ?Closure $compiling = null): array
    {
        $registry = $this->registry;
        $names?->assertDistinct(array_keys($registry->all()), 'component');
        $compiler = new ComponentCompiler(
            $this->isBladeDirective(...),
            parseTemplates: $build,
            componentName: fn (string $class) => array_search(ltrim($class, '\\'), $registry->all(), true) ?: null,
            names: $names,
            cloneStatic: $this->rendering === 'clone',
            atOnce: $this->rendersAtOnce(...),
            meta: fn (string $class) => $this->components->meta($class),
            attributes: $this->customAttributes(),
        );
        $main = [];
        $chunks = [];
        $broken = [];
        $map = $this->chunks->map();

        /** The Blade components' views the components call, compiled once for this bundle. */
        app()->instance(ComponentViews::class, $views = new ComponentViews);

        foreach (array_keys($this->registry->all()) as $name) {
            if ($compiling !== null) {
                $compiling($name);
            }

            try {
                $meta = $this->components->meta($name);

                /** A store has no view, and is in the main bundle: any page's components may use it. */
                if ($meta->store) {
                    $main[] = $compiler->compileStore($meta);

                    continue;
                }

                /** A view Nitro compiles is read here; one the app's build compiles is only named. */
                $engine = $this->views->of($meta);
                $meta->assertNamesFree($engine->reservedNames(), $engine->label());
                $viewFile = $engine->file($meta);
                $definition = $compiler->compile($meta, $engine->compiledByNitro() ? $this->files->get($viewFile) : null, $viewFile, $engine->resolved($meta));
            } catch (CompileException $exception) {
                if ($build) {
                    throw $exception;
                }

                $broken[$name] = ['message' => $exception->reason, 'file' => $exception->sourceFile, 'line' => $exception->sourceLine];

                continue;
            }

            if (isset($map[$name])) {
                $chunks[$map[$name]][] = $definition;
            } else {
                $main[] = $definition;
            }
        }

        if ($map !== []) {
            /** The chunks by the names the browser asks for them: hashed in a build (Chunks::id()). */
            $ids = [];

            foreach ($map as $name => $group) {
                $ids[$names?->component($name) ?? $name] = $this->chunks->id($group, $build);
            }
            $routes = array_map(fn (array $route) => [$route[0], $this->chunks->id($route[1], $build)], $this->chunks->routes());

            $main[] = '// #[LazyLoad]: component => chunk, and the pages that render them'."\n"
                .'Nitro.lazy('.json_encode((object) $ids, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).', '
                .json_encode($routes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).');';
        }

        /** Markup-first pages by path: a visit renders the page at once, before its data. */
        $markup = [];

        foreach ($this->pageRoutes->all() as [$pattern, $name, $route]) {
            $meta = $this->components->meta($name);

            if ($this->markupFirst($meta)) {
                /** A client page takes its route's parameters from the URL: which property each fills, and its type. */
                $parameters = $meta->navigate === NavigationMode::Client ? $this->pageRoutes->clientParameters($meta, $route, $names) : [];
                $markup[] = $parameters === [] ? [$pattern, $names?->component($name) ?? $name] : [$pattern, $names?->component($name) ?? $name, $parameters];
            }
        }

        if ($markup !== []) {
            $main[] = '// #[Navigate(\'markup\')] and #[Navigate(\'client\')]: path => page, rendered at once on a visit'."\n"
                .'Nitro.markupPages('.json_encode($markup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).');';
        }

        /** The Blade components' views, in the main bundle: any chunk's components may call them. */
        array_unshift($main, ...$views->definitions());

        /** The #[Browser] classes' methods the components reach, in the main bundle: any chunk may call them. */
        $classes = $compiler->compileClasses();

        if ($classes !== '') {
            array_unshift($main, $classes);
        }

        /** Viewi's: the PHP functions the components call (and those they use), in the main bundle. */
        if ($compiler->uses['functions'] !== []) {
            array_unshift($main, '// PHP functions (js/functions)'."\n".Functions::js(array_keys($compiler->uses['functions'])));
        }

        $files = ['' => $this->wrap($main)];

        foreach ($chunks as $group => $definitions) {
            $files[$group] = $this->wrap($definitions);
        }

        /** The #[Browser] classes: what was compiled for them (methods, and enums by "Enum::"). */
        $uses = Uses::checked($compiler->uses, (array) $this->config->get('public_config'));
        $uses['classes'] = $compiler->compiledClasses();

        return ['files' => $files, 'uses' => $uses + ['chunks' => $map, 'broken' => (object) $broken], 'classes' => $compiler->compiledClasses()];
    }

    /** Whether a visit to the page renders it at once (markup first, or client): its #[Navigate], or nitro.navigation. */
    public function markupFirst(ComponentMeta $meta): bool
    {
        return $meta->page && ($meta->navigate ?? $this->navigation)->rendersAtOnce();
    }

    /**
     * The application's own nitro: attributes (nitro.attributes), checked: a name that is not
     * one, or is Nitro's own, fails every compile with the reason.
     *
     * @return list<string>
     */
    public function customAttributes(): array
    {
        return Globals::customAttributes((array) $this->config->get('attributes'));
    }

    /** Whether Blade compiles @name (a built-in directive or one the application registered). */
    public function isBladeDirective(string $name): bool
    {
        return TemplateCompiler::bladeDirectives($this->blade)($name);
    }

    /**
     * What a page rendered at once carries in its definition: the layout it renders into, and for a
     * client page (#[Navigate('client')]) the title and head tags the server would give it, worked out
     * now, since a visit to it does not ask the server. Null for any other page.
     *
     * @return array<string, mixed>|null
     */
    private function rendersAtOnce(ComponentMeta $meta): ?array
    {
        if (! $this->markupFirst($meta)) {
            return null;
        }

        $fields = ['markup' => LayoutKey::of($meta->layout->view ?? $this->config->layout(), $meta->layout->data ?? [])];

        if ($meta->navigate === NavigationMode::Client) {
            $this->pageRoutes->assertClient($meta);
            $fields['client'] = ($this->clientHead)($meta);
        }

        return $fields;
    }

    /** @param  list<string>  $definitions */
    private function wrap(array $definitions): string
    {
        return "(function (Nitro, P, B) {\n\"use strict\";\n".implode("\n\n", $definitions)
            ."\n})(window.Nitro, window.Nitro.P, window.Nitro.B);\n";
    }
}
