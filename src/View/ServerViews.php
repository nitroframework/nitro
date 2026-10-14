<?php

namespace Nitro\View;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Nitro\Compiler\ComponentCompiler;
use Nitro\Compiler\ComponentMeta;
use Nitro\Compiler\Template\ComponentViews;
use Nitro\Compiler\Template\ServerView;
use Nitro\Compiler\Template\TemplateCompiler;

/**
 * Components' Blade views as the server renders them (Compiler\Template\ServerView): compiled
 * from the view as the browser's are, with the component's own names, and cached as a PHP file
 * for each component and version of the sources (the bundle's: a component's class, its views,
 * Nitro's compiler). A file is written once and only read after: a change to the sources is a
 * new version, and writing it drops the component's file of the version before.
 *
 * @internal
 */
final class ServerViews
{
    /**
     * @param  Closure(): string  $version  the sources' version (Build\Bundle::version())
     * @param  Closure(string): ComponentMeta  $meta  a store's metadata by class (the stores a component uses)
     * @param  list<string>  $attributes  the application's own nitro: attributes (nitro.attributes)
     */
    public function __construct(
        private readonly Container $app,
        private readonly Filesystem $files,
        private readonly BladeCompiler $blade,
        private readonly string $directory,
        private readonly Closure $version,
        private readonly Closure $meta,
        private readonly array $attributes,
    ) {
    }

    /**
     * The component's view as ServerView writes it: compiled now when its version has no file.
     *
     * @return array{tree: array<string, mixed>, expressions: Closure, calls: array<string, array{tree: array<string, mixed>, expressions: Closure}>}
     */
    public function of(ComponentMeta $meta, string $viewFile): array
    {
        $path = $this->path($meta);

        if (! is_file($path)) {
            $this->write($meta, $path, $this->compile($meta, $viewFile));
        }

        return require $path;
    }

    public function path(ComponentMeta $meta): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$this->name($meta).'-'.($this->version)().'.php';
    }

    private function name(ComponentMeta $meta): string
    {
        return substr(hash('xxh128', $meta->class), 0, 16);
    }

    private function compile(ComponentMeta $meta, string $viewFile): string
    {
        $compiler = new ComponentCompiler(TemplateCompiler::bladeDirectives($this->blade), meta: $this->meta, attributes: $this->attributes);

        /** The Blade components' views it calls, compiled for it alone (the template compiler asks the container for them). */
        $outer = $this->app->make(ComponentViews::class);
        $this->app->instance(ComponentViews::class, $views = new ComponentViews);

        try {
            $view = $compiler->view($meta, $this->files->get($viewFile), $viewFile);
        } finally {
            $this->app->instance(ComponentViews::class, $outer);
        }

        return "<?php\n\n/* ".$meta->class.' · '.str_replace('*/', '* /', $viewFile)." */\n\nreturn ".ServerView::source($view, $views).";\n";
    }

    /** Written whole (another request may read it meanwhile); the component's files of other versions go. */
    private function write(ComponentMeta $meta, string $path, string $code): void
    {
        $this->files->ensureDirectoryExists($this->directory);

        foreach ($this->files->glob($this->directory.DIRECTORY_SEPARATOR.$this->name($meta).'-*.php') as $stale) {
            @unlink($stale);
        }

        $this->files->replace($path, $code);
    }
}
