<?php

namespace Nitro\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\View\AnonymousComponent;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\Scope;
use Nitro\Compiler\Template\ComponentViews;
use Nitro\Compiler\Template\TemplateCompiler;
use Nitro\Tests\TestCase;
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

        /** Then what the component's code reaches, as compiling the component goes on to (a method called on a value: an enum case's). */
        $components = new \Nitro\Compiler\ComponentCompiler(fn () => false);
        \Closure::bind(fn (array $keys) => $this->classes->reach($keys), $components, \Nitro\Compiler\ComponentCompiler::class)(array_keys($compiler->uses()['classes']));
    }

    /** What a view compiles to: its template and expressions, then the Blade components' views it calls. */
    private function compiled(string $template, Scope $scope, array $directives = ['include', 'props']): string
    {
        $compiler = new TemplateCompiler(fn (string $name) => Blade::getCustomDirectives()[$name] ?? in_array($name, $directives, true));

        return $compiler->compile($template, $scope, 'view.blade.php')."\n".implode("\n", app(ComponentViews::class)->definitions());
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
            'a component reading the Nitro component\'s properties' => ['<div><x-uses-count /></div>', '<x-uses-count> has no $count: a Blade component sees its @props, its attributes and its slots', self::VIEWS.'components/uses-count.blade.php', 2],
            'an $attributes method the browser has not' => ['<div><x-filtered /></div>', '$attributes->filter() is not supported in the browser (merge, class, only, except, get, has are)', self::VIEWS.'components/filtered.blade.php', 1],
            'a Nitro child in an included view' => ["<div>\n@include('inline-errors.with-child')</div>", '<nitro:...> inside inline-errors.with-child', self::VIEWS.'inline-errors/with-child.blade.php', 2],
            'an error in an included view, at its own line' => ["<div>\n\n\n@include('inline-errors.bad-line', ['cake' => \$count])</div>", '->price() is called on a value in the browser', self::VIEWS.'inline-errors/bad-line.blade.php', 3],
            'an $attributes->has() without a literal name, at its line' => ['<div><x-attribute-name /></div>', "\$attributes->has() takes the attribute's name as a string", self::VIEWS.'components/attribute-name.blade.php', 3],
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

    public function test_a_component_view_comments_and_verbatim_are_left_as_written(): void
    {
        $this->boot('laravel');

        $js = $this->compiled('<div><x-set-aside>Hi</x-set-aside></div>', new Scope(file: 'Component.php'));

        $this->assertStringContainsString('{{ $slot }} {{ $attributes }}', $js, '@verbatim prints its text, as Blade does');
        $this->assertSame(1, substr_count($js, '"Hi"'), 'the slot is placed once: in <p>, not in @verbatim');
        $this->assertStringContainsString('<code>{{ $slot }} {{ $attributes }}</code>', Blade::render('<x-set-aside>Hi</x-set-aside>'));
    }

    public function test_a_components_loop_variable_is_its_own_where_the_component_tests_it_with_isset(): void
    {
        $this->boot('laravel');

        $js = $this->compiled('<div><x-listing :rows="$count" /></div>', new Scope(properties: ['count'], file: 'Component.php'));

        $this->assertStringContainsString('P.isset($row, ["heading"])', $js, 'isset() reads the loop variable');
        $this->assertStringContainsString('P.get($row, "label")', $js, 'and so does the rest of the loop');
        $this->assertStringNotContainsString('P.isset(null', $js, 'not null for a name nothing gave the component');
    }

    public function test_get_gives_a_nitro_attributes_value_as_the_attribute_bag_does(): void
    {
        $this->boot('laravel');
        $js = $this->compiled('<div><x-model-field nitro:model="form.email" placeholder="Email" /></div>', new Scope(properties: ['form'], file: 'Component.php'));

        $this->assertStringContainsString('() => "form.email")', $js, 'the value, not the attribute as written');
        $this->assertStringContainsString('P.toStr("no")', $js, 'the default for an attribute the tag does not give, read by an echo');
    }

    public function test_a_components_attributes_read_the_views_use_directives(): void
    {
        $this->boot('laravel');
        $compiler = new TemplateCompiler(fn (string $name) => Blade::getCustomDirectives()[$name] ?? in_array($name, ['include', 'props', 'use'], true));

        $js = $compiler->compile("@use('Nitro\\Tests\\Fixtures\\Browser\\Money')\n<div><x-badge :tone=\"Money::format(\$count)\">x</x-badge></div>", new Scope(properties: ['count'], file: 'Component.php'), 'view.blade.php');

        $this->assertStringContainsString('Nitro\\\\Tests\\\\Fixtures\\\\Browser\\\\Money', $js, 'the alias names the imported class');
    }

    public function test_class_and_style_directives_on_a_component_tag_compile_as_on_an_element(): void
    {
        $this->boot('laravel');
        $compiler = new TemplateCompiler(fn (string $name) => Blade::getCustomDirectives()[$name] ?? in_array($name, ['include', 'props'], true));

        $js = $compiler->compile('<div><x-badge @class([\'big\' => $count > 2]) @style([\'color: red\' => $count > 5])>x</x-badge></div>', new Scope(properties: ['count'], file: 'Component.php'), 'view.blade.php');

        $this->assertStringContainsString('B.classes(', $js, 'Blade makes @class an Arr::toCssClasses() call: the runtime\'s @class');
        $this->assertStringContainsString('B.styles(', $js, 'and @style an Arr::toCssStyles() call');
    }

    public function test_a_slot_passed_on_to_another_component_is_its_content(): void
    {
        $this->boot('laravel');
        $compiler = new TemplateCompiler(fn (string $name) => Blade::getCustomDirectives()[$name] ?? in_array($name, ['include', 'props'], true));

        $js = $compiler->compile('<div><x-forwarder>Hello {{ $count }}</x-forwarder></div>', new Scope(properties: ['count'], file: 'Component.php'), 'view.blade.php');

        $this->assertStringContainsString('"Hello "', $js, 'the slot\'s content, inside the badge');
        $this->assertStringNotContainsString('SLOT', $js, 'never the marker that stood for it');
    }

    public function test_an_attribute_value_is_compiled_once_per_use_not_once_per_check(): void
    {
        $this->boot('laravel');
        $compiler = new TemplateCompiler(fn (string $name) => Blade::getCustomDirectives()[$name] ?? in_array($name, ['include', 'props'], true));

        $js = $compiler->compile('<div><x-badge data-n="{{ $count }}" :tone="$view">x</x-badge></div>', new Scope(properties: ['count', 'view'], file: 'Component.php'), 'view.blade.php');

        /** data-n is printed by {{ $attributes->merge() }}: its condition and its value, each reading it once. */
        $this->assertLessThanOrEqual(2, substr_count($js, '$this.count'), 'a value is read by the checks the bag makes, not copied into each');
        $this->assertLessThanOrEqual(2, substr_count($js, '$this.view'), 'and a prop merged into class likewise');
    }

    public function test_a_component_used_many_times_is_compiled_once(): void
    {
        $this->boot('laravel');
        $compiler = new TemplateCompiler(fn (string $name) => Blade::getCustomDirectives()[$name] ?? in_array($name, ['include', 'props'], true));
        $uses = str_repeat('<x-badge :tone="$view" data-n="{{ $count }}">{{ $count }}</x-badge>', 40);

        $js = $compiler->compile("<div>{$uses}</div>", new Scope(properties: ['count', 'view'], file: 'Component.php'), 'view.blade.php');

        $views = app(ComponentViews::class)->definitions();

        $this->assertCount(1, $views, 'one view for the forty tags, which give the same names');
        $this->assertStringContainsString('badge badge-', $views[0], 'compiled there');
        $this->assertSame(40, substr_count($js, '"t": "v"'), 'each tag a call of it');
        $this->assertStringNotContainsString('badge badge-', $js, 'a call is its inputs and its slot, not the view again');
        $this->assertStringNotContainsString('dataN', $js, 'and only the inputs the view reads');
    }

    public function test_a_field_named_by_a_components_caller_is_the_browsers_name_in_an_obfuscated_build(): void
    {
        $this->boot('laravel');
        $names = new \Nitro\Compiler\Obfuscator('key', [], aliasComponents: false);

        $js = $this->compiled('<div><x-field-error name="email" /></div>', new Scope(properties: ['email'], file: 'Component.php', names: $names));

        $this->assertSame(1, substr_count($js, json_encode($names->property('email'))), 'the call gives the field by its alias');
        $this->assertSame(2, substr_count($js, '$this.name'), '@error($name) and $errors->has($name) read it');
        $this->assertStringNotContainsString('"email"', $js, 'never by its own name, which the errors do not carry');
    }

    public function test_each_inlined_view_reads_its_own_use_directives(): void
    {
        $this->boot('laravel');

        /** The page and its component each name a class Money: a file's @use is its own, as Blade compiles them. */
        $js = $this->compiled("@use('Nitro\\Tests\\Fixtures\\Browser\\Money')\n<div>{{ Money::format(\$count) }}<x-alias-use /><x-alias-use /></div>", new Scope(properties: ['count'], file: 'Component.php'), ['include', 'props', 'use']);

        $this->assertStringContainsString('Browser\\\\Money"]["format"](', $js, 'the page\'s Money');
        $this->assertStringContainsString('Browser\\\\Priority', $js, 'the component\'s Money');
    }

    public function test_a_components_loop_variable_named_as_one_around_it_is_its_own(): void
    {
        $this->boot('laravel');

        /** The page loops over $row, and so does the component, over its own rows. */
        $js = $this->compiled('<div>@foreach ($count as $row)<x-listing :rows="$row[\'children\']" />{{ $row[\'name\'] }}@endforeach</div>', new Scope(properties: ['count'], file: 'Component.php'));

        $this->assertStringContainsString('P.isset($row, ["heading"])', $js, 'the component\'s $row, in its own view');
        $this->assertStringContainsString('P.peek($row, ["children"])', $js, 'the page\'s $row, given to it');
        $this->assertStringContainsString('P.get($row, "name")', $js, 'and after it');
    }

    public function test_a_components_for_loop_declares_its_own_variables(): void
    {
        $this->boot('laravel');

        $js = $this->compiled('<div><x-counted :times="$count" /></div>', new Scope(properties: ['count'], file: 'Component.php'));

        $this->assertStringContainsString('$i = 0', $js, 'the @for\'s $i is its own, not something the component is given');
        $this->assertStringContainsString('$this.count', $js, 'what the component is given is read through it');
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
        $bundle = $this->app->make(\Nitro\Build\Bundle::class);
        $before = $bundle->version();

        $partial = self::VIEWS.'partials/heading.blade.php';
        $time = filemtime($partial);
        touch($partial, $time + 10);
        clearstatcache();

        try {
            $this->app->forgetScopedInstances(); /* the next request's bundle */
            $this->assertNotSame($before, $this->app->make(\Nitro\Build\Bundle::class)->version());
        } finally {
            touch($partial, $time);
        }
    }
}
