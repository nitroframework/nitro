<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\ComponentReader;
use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\Fixtures\Components\PlainCounter;
use Nitro\Fusion\Tests\TestCase;
use Nitro\Fusion\View\Adapters\ReactAdapter;
use Nitro\Fusion\View\Adapters\SolidAdapter;
use Nitro\Fusion\View\Adapters\VueAdapter;
use Nitro\Fusion\View\ColocatedViews;
use Nitro\Fusion\View\ScriptEngine;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Views written for a JavaScript framework (#[View(vue: ...)], or an engine of the app's own):
 * named on the class, found in fusion.views, rendered by the server as the element they mount
 * in, compiled without a template (the browser tests' "view engines" mount one).
 */
class ViewEnginesTest extends TestCase
{
    public function test_view_names_its_engine(): void
    {
        $this->assertSame(['blade', 'shared.form'], [(new View('shared.form'))->engine, (new View('shared.form'))->name]);
        $this->assertSame(['vue', 'cart/badge'], [(new View(vue: 'cart/badge'))->engine, (new View(vue: 'cart/badge'))->name]);
        $this->assertSame(['react', 'chart'], [(new View(react: 'chart'))->engine, (new View(react: 'chart'))->name]);
        $this->assertSame(['solid', 'clock'], [(new View(solid: 'clock'))->engine, (new View(solid: 'clock'))->name]);
        $this->assertSame(['plain', 'map'], [(new View('map', engine: 'plain'))->engine, (new View('map', engine: 'plain'))->name]);

        $meta = ComponentReader::read(PlainCounter::class, 'plain-counter', 'fusion.components.plain-counter');
        $this->assertSame(['plain', 'plain-counter'], [$meta->engine, $meta->view]);
    }

    public function test_a_view_names_one_view(): void
    {
        $class = get_class(new #[View(vue: 'a', react: 'b')] class extends Component {});

        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('#[View] names one view');

        ComponentReader::read($class, 'x', 'fusion.x');
    }

    #[DataProvider('frameworks')]
    public function test_the_server_renders_the_element_it_mounts_in(string $framework): void
    {
        $this->boot($framework);
        $html = (string) $this->get('/engines')->getContent();

        $this->assertMatchesRegularExpression('/<div class="plain-side"><div fusion:id="[^"]+" fusion:name="plain-counter"><\/div><\/div>/', $html);
    }

    #[DataProvider('frameworks')]
    public function test_it_compiles_without_a_template_naming_its_engine_and_view(string $framework): void
    {
        $this->boot($framework);
        $bundle = implode("\n", $this->app->make(\Nitro\Fusion\Build\Bundle::class)->compileAll());

        preg_match('/Fusion\.define\("plain-counter", \{[\s\S]*?\n\}\);/', $bundle, $definition);
        $this->assertNotEmpty($definition);
        $this->assertStringContainsString('engine: "plain",', $definition[0]);
        $this->assertStringContainsString('view: "plain-counter",', $definition[0]);
        $this->assertStringContainsString('"increment": function', $definition[0], 'every browser method: the view may call any');
        $this->assertStringNotContainsString('template:', $definition[0]);
        $this->assertStringNotContainsString('subs:', $definition[0]);
    }

    public function test_a_views_php_block_becomes_its_class_with_its_line_numbers(): void
    {
        $vue = "<template>\n  <p>{{ count }}</p>\n</template>\n\n<php>\nuse App\\Models\\Post;\n\nnew #[Url] class {\n    public int \$count = 0;\n\n    #[Server]\n    public function save(): void {}\n};\n</php>\n";
        $class = ColocatedViews::generate('Fusion\\Views\\Vue\\Cart\\Badge', 'vue', 'cart/badge', 'badge.vue', (new VueAdapter([], []))->phpBlock($vue, 'badge.vue'));
        $lines = explode("\n", $class);

        $this->assertStringStartsWith('<?php namespace Fusion\\Views\\Vue\\Cart; use App\\Models\\Post; use Nitro\\Fusion\\Component;', $lines[0], 'its imports, then Fusion\'s');
        $this->assertStringContainsString('use Nitro\\Fusion\\Attributes\\Url;', $lines[0], 'Fusion\'s attributes need no use');
        $this->assertSame("#[Url] #[\\Nitro\\Fusion\\Attributes\\View('cart/badge', engine: 'vue')] final class Badge extends Component {", $lines[7], 'on line 8, as in the view; no extends: Component');
        $this->assertSame('    public function save(): void {}', $lines[11], 'every line where the view has it');

        $jsx = "/* <php>\nnew class extends Component {\n    public string \$name = '';\n};\n</php> */\nexport default function Badge() {}\n";
        $this->assertSame(["\nnew class extends Component {\n    public string \$name = '';\n};\n", 1], (new ReactAdapter([], []))->phpBlock($jsx, 'badge.jsx'), 'in a .jsx file, the block is a comment');
        $this->assertNull((new SolidAdapter([], []))->phpBlock("<php>\nnew class {};\n</php>\nexport default 1;", 'plain.jsx'), 'a .jsx file\'s block is in a comment, or it is not one');
        $this->assertNotNull((new VueAdapter([], []))->phpBlock($vue, 'badge.js'), 'a .vue file\'s is a <php> element, whatever the path says');
    }

    #[DataProvider('frameworks')]
    public function test_fusions_frameworks_have_their_adapters(string $framework): void
    {
        $this->boot($framework);
        $engines = $this->app->make(\Nitro\Fusion\View\ViewEngines::class);

        $this->assertInstanceOf(VueAdapter::class, $engines->named('vue'));
        $this->assertInstanceOf(ReactAdapter::class, $engines->named('react'));
        $this->assertInstanceOf(SolidAdapter::class, $engines->named('solid'));
        $this->assertInstanceOf(\Nitro\Fusion\View\Adapters\SvelteAdapter::class, $engines->named('svelte'));
        $this->assertSame(ScriptEngine::class, get_class($engines->named('plain')), 'an engine of the app\'s own');
        $this->assertSame(['Blade', 'Vue', 'React', 'Solid', 'Svelte', 'Plain'], array_map(fn (string $name) => $engines->named($name)?->label(), ['blade', 'vue', 'react', 'solid', 'svelte', 'plain']));
    }

    public function test_the_adapters_are_the_configs_and_an_entry_without_one_is_a_plain_engine(): void
    {
        $this->boot('laravel');
        $this->app['config']->set('fusion.views.vue', ['path' => resource_path('views/vue'), 'extensions' => ['vue']]);
        $this->app['config']->set('fusion.views.svelte', ['adapter' => VueAdapter::class, 'path' => resource_path('views/svelte'), 'extensions' => ['svelte']]);
        $this->app->forgetScopedInstances();

        $this->expectExceptionMessage('fusion.views.svelte: the adapter [Nitro\Fusion\View\Adapters\VueAdapter] is not a view engine named [svelte]');

        try {
            $this->app->make(\Nitro\Fusion\View\ViewEngines::class);
        } finally {
            $this->app['config']->set('fusion.views.svelte', ['path' => resource_path('views/svelte'), 'extensions' => ['svelte']]);
            $this->app->forgetScopedInstances();
            $engines = $this->app->make(\Nitro\Fusion\View\ViewEngines::class);

            $this->assertSame(ScriptEngine::class, get_class($engines->named('vue')), 'no adapter in the config, none made');
            $this->assertSame(['svelte', ['svelte']], [$engines->named('svelte')?->name(), $engines->named('svelte')?->extensions()]);
            $this->assertInstanceOf(ReactAdapter::class, $engines->named('react'));
        }
    }

    public static function brokenBlocks(): array
    {
        return [
            'no class' => ["<template></template>\n<php>\n\$x = 1;\n</php>", 'holds the component as an anonymous class', 2],
            'not PHP' => ["<template></template>\n<php>\nnew class {\n    public function (\n};\n</php>", 'is not valid PHP', 4],
        ];
    }

    #[DataProvider('brokenBlocks')]
    public function test_a_broken_block_is_a_compile_error_naming_the_views_line(string $view, string $message, int $line): void
    {
        try {
            ColocatedViews::generate('Fusion\\Views\\Vue\\Broken', 'vue', 'broken', 'broken.vue', (new VueAdapter([], []))->phpBlock($view, 'broken.vue') ?? ['', 1]);
            $this->fail('compiled');
        } catch (CompileException $e) {
            $this->assertStringContainsString($message, $e->reason);
            $this->assertSame(['broken.vue', $line], [$e->sourceFile, $e->sourceLine]);
        }
    }

    #[DataProvider('frameworks')]
    public function test_a_view_with_a_php_block_is_a_component(string $framework): void
    {
        $this->boot($framework);
        $components = app(\Nitro\Fusion\Support\Registry::class)->all();

        $this->assertSame('Fusion\\Views\\Vue\\ColocatedTally', $components['colocated-tally']);
        $this->assertSame('Fusion\\Views\\React\\ColocatedGreeting', $components['colocated-greeting']);
        $this->assertSame('Fusion\\Views\\Svelte\\ColocatedScore', $components['colocated-score'], 'a <php> block in a .svelte file, as in a .vue file');
        $meta = app(\Nitro\Fusion\Compiler\Components::class)->meta('colocated-greeting');
        $this->assertSame(['react', 'colocated-greeting', ['greet']], [$meta->engine, $meta->view, $meta->serverMethods]);
        $this->assertStringContainsString('<div class="colocated-react"><div fusion:id=', (string) $this->get('/engines')->getContent());
    }

    #[DataProvider('frameworks')]
    public function test_a_missing_view_is_a_compile_error_naming_where_it_was_looked_for(string $framework): void
    {
        $this->boot($framework);
        $found = new ScriptEngine('vue', [resource_path('js/vue'), resource_path('views/vue')], ['vue']);
        $this->assertSame(resource_path('views/vue').DIRECTORY_SEPARATOR.'vue-note.vue', $found->file(ComponentReader::read(\Nitro\Fusion\Tests\Fixtures\Components\VueNote::class, 'vue-note', 'fusion.vue-note')), 'in the first of its paths that has it');

        $engine = new ScriptEngine('vue', [resource_path('views/vue')], ['vue']);
        $meta = ComponentReader::read(get_class(new #[View(vue: 'nowhere/card')] class extends Component {}), 'card', 'fusion.card');

        $this->assertSame([], $engine->files($meta), 'Fusion\'s build has nothing of the view: changing it builds nothing again');

        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('View [nowhere/card] of');

        $engine->file($meta);
    }
}
