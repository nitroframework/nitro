<?php

namespace Nitro\Blade;

use Illuminate\View\Compilers\BladeCompiler;

/**
 * Nitro in Blade: the precompilers (NitroTags) and Nitro's directives, and views compiled by
 * another version of Nitro dropped. The browser's side of the directives is the template
 * compiler's (Compiler/Template/Parts/Directives; their names, Globals::DIRECTIVES).
 */
final class Directives
{
    public static function register(BladeCompiler $blade, string $compiled): void
    {
        self::forgetStaleViews($compiled);

        /** @nitroScript blocks are the browser's (Globals::SCRIPT): out before the tags are read. */
        $blade->precompiler(NitroTags::withoutScripts(...));
        $blade->precompiler(NitroTags::compile(...));

        $blade->directive('nitroScripts', fn () => '<?php echo app(\Nitro\Rendering\Scripts::class)->html(); ?>');

        /** @nitroHead in the layout's <head>: the page's <title> and its tags for search engines and link previews. */
        $blade->directive('nitroHead', fn () => '<?php echo app(\Nitro\Rendering\PageHead::class)->html(); ?>');
        $blade->directive('nitro', fn (string $expression) => "<?php echo app(\\Nitro\\Rendering\\Placement::class)->mount({$expression}); ?>");

        /** Viewi's portals, as Livewire's @teleport: @portal('name') is where content goes, @teleport('name') what goes there. */
        $blade->directive('portal', fn (string $expression) => "<?php echo app(\\Nitro\\Rendering\\Portals::class)->portal({$expression}); ?>");
        $blade->directive('teleport', fn (string $expression) => "<?php echo app(\\Nitro\\Rendering\\Portals::class)->startTeleport({$expression}); ?>");
        $blade->directive('endteleport', fn () => '<?php echo app(\Nitro\Rendering\Portals::class)->endTeleport(); ?>');

        /** @deferred('stats') ... @placeholder ... @enddeferred: what a page shows once its deferred properties are loaded, and meanwhile. */
        $blade->directive('deferred', fn (string $expression) => "<?php if (! \$this->nitro()->deferring([{$expression}])): ?>");
        $blade->directive('placeholder', fn () => '<?php else: ?>');
        $blade->directive('enddeferred', fn () => '<?php endif; ?>');
    }

    /**
     * Compiled Blade views hold what Nitro's directives and <nitro:> tags compile to: the parts
     * they call by name. Laravel compiles a view again only when its own file changes, so views
     * compiled by another version of Nitro (an upgrade) are dropped once, to be compiled again.
     * The version is that of the two files that write them: this one and NitroTags.
     */
    private static function forgetStaleViews(string $compiled): void
    {
        if ($compiled === '' || ! is_dir($compiled)) {
            return;
        }

        $stamp = $compiled.DIRECTORY_SEPARATOR.'nitro.version';
        $version = md5((string) md5_file(__FILE__).md5_file(__DIR__.'/NitroTags.php'));

        if (@file_get_contents($stamp) === $version) {
            return;
        }

        foreach (glob($compiled.DIRECTORY_SEPARATOR.'*.php') ?: [] as $view) {
            @unlink($view);
        }

        @file_put_contents($stamp, $version);
    }
}
