<?php

namespace Nitro\Fusion\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\View\AnonymousComponent;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\Scope;
use Nitro\Fusion\Compiler\Template\TemplateCompiler;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @include and Blade components compiled for the browser (BladeInliner): what cannot be inlined is
 * a CompileException, reported in the view and at the line it is written at. What renders is
 * tests/Parity (the showcase fixture) and the browser tests (/showcase).
 */
class InlinedViewsTest extends TestCase
{
    private const VIEWS = self::APP.'/resources/views/';

    private function compile(string $template): void
    {
        $compiler = new TemplateCompiler(fn (string $name) => Blade::getCustomDirectives()[$name] ?? in_array($name, ['include', 'includeIf', 'includeWhen', 'includeUnless', 'props'], true));
        $compiler->compile($template, new Scope(properties: ['count', 'view', 'tone'], clientMethods: ['click'], file: 'Component.php'), 'view.blade.php');
    }

    public static function errors(): array
    {
        return [
            'a dynamic view name' => ["<div>\n@include(\$view)</div>", "@include names its view with a string", 'view.blade.php', 2],
            'a view that is not there' => ["<div>\n\n@include('nope.nothing')</div>", '@include: there is no view [nope.nothing]', 'view.blade.php', 3],
            'data that is not a literal array' => ["<div>@include('partials.heading', \$count)</div>", "@include's data must be a literal array", 'view.blade.php', 1],
            'an unknown component' => ["<div>\n<x-nope-at-all /></div>", 'Unable to locate a class or view for component [nope-at-all]', 'view.blade.php', 2],
            'a class component' => ['<div><x-boxed /></div>', 'is the class component', 'view.blade.php', 1],
            'x-dynamic-component' => ['<div><x-dynamic-component component="badge" /></div>', '<x-dynamic-component> names its component at run time', 'view.blade.php', 1],
            'a component reading the Fusion component\'s properties' => ['<div><x-uses-count /></div>', '<x-uses-count> has no $count: a Blade component sees its @props, its attributes and its slots', self::VIEWS.'components/uses-count.blade.php', 2],
            'an $attributes method the browser has not' => ['<div><x-filtered /></div>', '$attributes->filter() is not supported in the browser (merge, class, only, except, get, has are)', self::VIEWS.'components/filtered.blade.php', 1],
            'a Fusion child in an included view' => ["<div>\n@include('inline-errors.with-child')</div>", '<fusion:...> inside inline-errors.with-child', self::VIEWS.'inline-errors/with-child.blade.php', 2],
            'an error in an included view, at its own line' => ["<div>\n\n\n@include('inline-errors.bad-line', ['cake' => \$count])</div>", 'Only methods of the component', self::VIEWS.'inline-errors/bad-line.blade.php', 3],
            'an unclosed component tag' => ["<div>\n<x-badge>open</div>", '<x-badge> is not closed', 'view.blade.php', 2],
        ];
    }

    #[DataProvider('errors')]
    public function test_what_cannot_be_inlined_is_reported_where_it_is_written(string $template, string $message, string $file, int $line): void
    {
        $this->boot('laravel');
        Blade::component('boxed', AnonymousComponent::class);

        try {
            $this->compile($template);
            $this->fail("Expected a CompileException for: {$template}");
        } catch (CompileException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertSame(str_replace('\\', '/', $file), str_replace('\\', '/', (string) $e->sourceFile), $e->getMessage());
            $this->assertSame($line, $e->sourceLine, $e->getMessage());
        }
    }

    public function test_lines_after_an_inlined_view_keep_their_numbers(): void
    {
        $this->boot('laravel');

        try {
            $this->compile("<div>\n@include('partials.heading', ['title' => 'x', 'level' => 1])\n<x-badge>a</x-badge>\n\n{{ \$nothing->here() }}</div>");
            $this->fail('Expected a CompileException');
        } catch (CompileException $e) {
            $this->assertSame('view.blade.php', $e->sourceFile);
            $this->assertSame(5, $e->sourceLine, $e->getMessage());
        }
    }

    public function test_a_changed_partial_or_component_changes_the_bundle(): void
    {
        $this->boot('laravel');
        $bundle = $this->app->make(\Nitro\Fusion\Build\Bundle::class);
        $before = $bundle->version();

        $partial = self::VIEWS.'partials/heading.blade.php';
        $time = filemtime($partial);
        touch($partial, $time + 10);
        clearstatcache();

        try {
            $this->app->forgetScopedInstances(); /* the next request's bundle */
            $this->assertNotSame($before, $this->app->make(\Nitro\Fusion\Build\Bundle::class)->version());
        } finally {
            touch($partial, $time);
        }
    }
}
