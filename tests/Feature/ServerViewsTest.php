<?php

namespace Nitro\Tests\Feature;

use Nitro\Compiler\ComponentCompiler;
use Nitro\Compiler\Components;
use Nitro\Compiler\Globals;
use Nitro\Compiler\Template\ComponentViews;
use Nitro\Compiler\Template\TemplateCompiler;
use Nitro\Tests\Fixtures\Browser\Money;
use Nitro\Tests\Fixtures\Components\StressRow;
use Nitro\Tests\TestCase;
use Nitro\View\ServerViews;

/**
 * A component's Blade view as the server renders it (ServerViews, ServerView): the browser's tree
 * and the expressions' PHP twins, cached as a PHP file for each version of the sources.
 */
class ServerViewsTest extends TestCase
{
    public function test_a_view_is_the_browsers_tree_with_its_twins_and_the_component_views_it_calls(): void
    {
        $this->boot('laravel');
        $meta = app(Components::class)->meta(StressRow::class);
        $file = app('view')->getFinder()->find($meta->view);

        $view = app(ServerViews::class)->of($meta, $file);

        /** The tree the browser renders, compiled again here as the bundle compiles it (its own names). */
        $outer = app(ComponentViews::class);
        app()->instance(ComponentViews::class, $views = new ComponentViews);
        $browser = (new ComponentCompiler(TemplateCompiler::bladeDirectives(app('blade.compiler')), meta: fn (string $class) => app(Components::class)->meta($class), attributes: $this->attributes()))
            ->view($meta, (string) file_get_contents($file), $file);
        app()->instance(ComponentViews::class, $outer);

        $this->assertSame($browser->tree(), $view['tree']);
        $calls = array_values(array_unique($browser->calls()));
        sort($calls);
        $this->assertNotSame([], $calls);
        $this->assertSame($calls, array_keys($view['calls']), 'the <x-badge> views it calls, by id');

        foreach ($view['calls'] as $id => $called) {
            $this->assertSame($views->tree($id), $called['tree']);
        }

        /** The twins, bound to the component, with the view's data. */
        $expressions = $view['expressions']->call(new StressRow, ['order' => ['customer' => 'Ann & Bo'], 'selected' => true, 'focus' => '']);
        $this->assertCount(count($browser->expressions()), $expressions);

        $twins = $browser->phpExpressions();
        $money = array_key_first(array_filter($twins, fn ($twin) => $twin !== null && str_contains($twin['php'], 'Money::format')));
        $customer = array_key_first(array_filter($twins, fn ($twin) => $twin !== null && $twin['php'] === "e(\$order['customer'])"));

        $this->assertStringContainsString('\\'.Money::class.'::format', $twins[$money]['php'], '@use: the name written out');
        $this->assertSame('Ann &amp; Bo', $expressions[$customer](...array_fill(0, count($twins[$customer]['locals']), null)));
        $this->assertSame(e(Money::format(300)), $expressions[$money](...array_map(fn (string $local) => $local === 'line' ? ['price' => 100, 'qty' => 3] : null, $twins[$money]['locals'])));
    }

    /** @return list<string> the fixture app's own nitro: attributes */
    private function attributes(): array
    {
        return Globals::customAttributes((array) config('nitro.attributes'));
    }

    public function test_a_view_is_compiled_once_for_each_version_of_the_sources(): void
    {
        $this->boot('laravel');
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nitro-server-views-'.bin2hex(random_bytes(4));
        $version = 'one';
        $views = new ServerViews(app(), app('files'), app('blade.compiler'), $directory, function () use (&$version) {
            return $version;
        }, fn (string $class) => app(Components::class)->meta($class), $this->attributes());
        $meta = app(Components::class)->meta(StressRow::class);
        $file = app('view')->getFinder()->find($meta->view);

        $views->of($meta, $file);
        $first = $views->path($meta);
        file_put_contents($first, '<?php return ["tree" => "cached"];');

        $this->assertSame('cached', $views->of($meta, $file)['tree'], 'read, not compiled again');

        $version = 'two';
        $views->of($meta, $file);

        $this->assertFileDoesNotExist($first, 'the version before goes');
        $this->assertSame([basename($views->path($meta))], array_map('basename', glob($directory.'/*.php') ?: []));

        app('files')->deleteDirectory($directory);
    }
}
