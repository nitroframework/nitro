<?php

namespace Nitro\Tests\Feature;

use Nitro\Attributes\View;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentReader;
use Nitro\Component;
use Nitro\Tests\Fixtures\Components\PlainCounter;
use Nitro\Tests\TestCase;
use Nitro\View\Adapters\ReactAdapter;
use Nitro\View\Adapters\SolidAdapter;
use Nitro\View\Adapters\VueAdapter;
use Nitro\View\ColocatedViews;
use Nitro\View\ScriptEngine;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Views written for a JavaScript framework (#[View(vue: ...)], or an engine of the app's own):
 * named on the class, found in nitro.views, rendered by the server as the element they mount
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

        $meta = ComponentReader::read(PlainCounter::class, 'plain-counter', 'nitro.components.plain-counter');
        $this->assertSame(['plain', 'plain-counter'], [$meta->engine, $meta->view]);
    }

    public function test_a_view_names_one_view(): void
    {
        $class = get_class(new #[View(vue: 'a', react: 'b')] class extends Component {});

        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('#[View] names one view');

        ComponentReader::read($class, 'x', 'nitro.x');
    }

    #[DataProvider('frameworks')]
    public function test_the_server_renders_the_element_it_mounts_in(string $framework): void
    {
        $this->boot($framework);
        $html = (string) $this->get('/engines')->getContent();

        $this->assertMatchesRegularExpression('/<div class="plain-side"><div nitro:id="[^"]+" nitro:name="plain-counter"><\/div><\/div>/', $html);
    }

    #[DataProvider('frameworks')]
    public function test_it_compiles_without_a_template_naming_its_engine_and_view(string $framework): void
    {
        $this->boot($framework);
        $bundle = implode("\n", $this->app->make(\Nitro\Build\Bundle::class)->compileAll());

        preg_match('/Nitro\.define\("plain-counter", \{[\s\S]*?\n\}\);/', $bundle, $definition);
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
        $class = ColocatedViews::generate('Nitro\\Views\\Vue\\Cart\\Badge', 'vue', 'cart/badge', 'badge.vue', (new VueAdapter([], []))->phpBlock($vue, 'badge.vue'));
        $lines = explode("\n", $class);

        $this->assertStringStartsWith('<?php namespace Nitro\\Views\\Vue\\Cart; use App\\Models\\Post; use Nitro\\Component;', $lines[0], 'its imports, then Nitro\'s');
        $this->assertStringContainsString('use Nitro\\Attributes\\Url;', $lines[0], 'Nitro\'s attributes need no use');
        $this->assertSame("#[Url] #[\\Nitro\\Attributes\\View('cart/badge', engine: 'vue')] final class Badge extends Component {", $lines[7], 'on line 8, as in the view; no extends: Component');
        $this->assertSame('    public function save(): void {}', $lines[11], 'every line where the view has it');

        $jsx = "/* <php>\nnew class extends Component {\n    public string \$name = '';\n};\n</php> */\nexport default function Badge() {}\n";
        $this->assertSame(["\nnew class extends Component {\n    public string \$name = '';\n};\n", 1], (new ReactAdapter([], []))->phpBlock($jsx, 'badge.jsx'), 'in a .jsx file, the block is a comment');
        $this->assertNull((new SolidAdapter([], []))->phpBlock("<php>\nnew class {};\n</php>\nexport default 1;", 'plain.jsx'), 'a .jsx file\'s block is in a comment, or it is not one');
        $this->assertNotNull((new VueAdapter([], []))->phpBlock($vue, 'badge.js'), 'a .vue file\'s is a <php> element, whatever the path says');
    }

    #[DataProvider('frameworks')]
    public function test_nitros_frameworks_have_their_adapters(string $framework): void
    {
        $this->boot($framework);
        $engines = $this->app->make(\Nitro\View\ViewEngines::class);

        $this->assertInstanceOf(VueAdapter::class, $engines->named('vue'));
        $this->assertInstanceOf(ReactAdapter::class, $engines->named('react'));
        $this->assertInstanceOf(SolidAdapter::class, $engines->named('solid'));
        $this->assertInstanceOf(\Nitro\View\Adapters\SvelteAdapter::class, $engines->named('svelte'));
        $this->assertSame(ScriptEngine::class, get_class($engines->named('plain')), 'an engine of the app\'s own');
        $this->assertSame(['Blade', 'Vue', 'React', 'Solid', 'Svelte', 'Plain'], array_map(fn (string $name) => $engines->named($name)?->label(), ['blade', 'vue', 'react', 'solid', 'svelte', 'plain']));
    }

    public function test_the_adapters_are_the_configs_and_an_entry_without_one_is_a_plain_engine(): void
    {
        $this->boot('laravel');
        $this->app['config']->set('nitro.views.vue', ['path' => resource_path('views/vue'), 'extensions' => ['vue']]);
        $this->app['config']->set('nitro.views.svelte', ['adapter' => VueAdapter::class, 'path' => resource_path('views/svelte'), 'extensions' => ['svelte']]);
        $this->app->forgetScopedInstances();

        $this->expectExceptionMessage('nitro.views.svelte: the adapter [Nitro\View\Adapters\VueAdapter] is not a view engine named [svelte]');

        try {
            $this->app->make(\Nitro\View\ViewEngines::class);
        } finally {
            $this->app['config']->set('nitro.views.svelte', ['path' => resource_path('views/svelte'), 'extensions' => ['svelte']]);
            $this->app->forgetScopedInstances();
            $engines = $this->app->make(\Nitro\View\ViewEngines::class);

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
            ColocatedViews::generate('Nitro\\Views\\Vue\\Broken', 'vue', 'broken', 'broken.vue', (new VueAdapter([], []))->phpBlock($view, 'broken.vue') ?? ['', 1]);
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
        $components = app(\Nitro\Support\Registry::class)->all();

        $this->assertSame('Nitro\\Views\\Vue\\ColocatedTally', $components['colocated-tally']);
        $this->assertSame('Nitro\\Views\\React\\ColocatedGreeting', $components['colocated-greeting']);
        $this->assertSame('Nitro\\Views\\Svelte\\ColocatedScore', $components['colocated-score'], 'a <php> block in a .svelte file, as in a .vue file');
        $meta = app(\Nitro\Compiler\Components::class)->meta('colocated-greeting');
        $this->assertSame(['react', 'colocated-greeting', ['greet']], [$meta->engine, $meta->view, $meta->serverMethods]);
        $this->assertStringContainsString('<div class="colocated-react"><div nitro:id=', (string) $this->get('/engines')->getContent());
    }

    #[DataProvider('frameworks')]
    public function test_a_missing_view_is_a_compile_error_naming_where_it_was_looked_for(string $framework): void
    {
        $this->boot($framework);
        $found = new ScriptEngine('vue', [resource_path('js/vue'), resource_path('views/vue')], ['vue']);
        $this->assertSame(resource_path('views/vue').DIRECTORY_SEPARATOR.'vue-note.vue', $found->file(ComponentReader::read(\Nitro\Tests\Fixtures\Components\VueNote::class, 'vue-note', 'nitro.vue-note')), 'in the first of its paths that has it');

        $engine = new ScriptEngine('vue', [resource_path('views/vue')], ['vue']);
        $meta = ComponentReader::read(get_class(new #[View(vue: 'nowhere/card')] class extends Component {}), 'card', 'nitro.card');

        $this->assertSame([], $engine->files($meta), 'Nitro\'s build has nothing of the view: changing it builds nothing again');

        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('View [nowhere/card] of');

        $engine->file($meta);
    }

    /** @var list<string> the folders views() made, removed after each test */
    private array $folders = [];

    protected function tearDown(): void
    {
        foreach ($this->folders as $folder) {
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($folder);
        }

        parent::tearDown();
    }

    /** Views in a folder of the test's own: path => contents. */
    private function views(array $files): string
    {
        $root = $this->folders[] = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nitro-dotted-views-'.bin2hex(random_bytes(4));

        foreach ($files as $path => $contents) {
            @mkdir(dirname($root.'/'.$path), 0777, true);
            file_put_contents($root.'/'.$path, $contents);
        }

        return $root;
    }

    /** A Vue component's meta, its view named so. */
    private function named(string $view): \Nitro\Compiler\ComponentMeta
    {
        $meta = ComponentReader::read(get_class(new #[View(vue: 'placeholder')] class extends Component {}), 'x', 'nitro.x');

        return new \Nitro\Compiler\ComponentMeta(...['view' => $view] + get_object_vars($meta));
    }

    /** A JavaScript view's name reads dots as folders too, as Blade's: "auth.login" is auth/login.vue. */
    public function test_a_javascript_views_dots_and_slashes_are_folders(): void
    {
        $root = $this->views(['auth/login.vue' => '', 'admin/auth/login.vue' => '', 'card.vue' => '']);
        $engine = new ScriptEngine('vue', [$root], ['vue']);

        foreach (['auth.login', 'auth/login', 'auth\\login'] as $name) {
            $this->assertSame($root.DIRECTORY_SEPARATOR.'auth'.DIRECTORY_SEPARATOR.'login.vue', $engine->file($this->named($name)), $name);
            $this->assertSame('auth/login', $engine->resolved($this->named($name)), "{$name}: the browser and the render service get its path");
        }

        $this->assertSame('admin/auth/login', $engine->resolved($this->named('admin/auth.login')), 'slashes and dots together');
        $this->assertSame('admin/auth/login', $engine->resolved($this->named('admin.auth.login')));
        $this->assertSame('card', $engine->resolved($this->named('card')), 'a name without either, as before');
    }

    public function test_a_file_with_dots_in_its_own_name_is_still_found(): void
    {
        $engine = new ScriptEngine('vue', [$this->views(['chart.client.vue' => ''])], ['vue']);

        $this->assertSame('chart.client', $engine->resolved($this->named('chart.client')), 'the name as written first');
    }

    public function test_a_name_that_is_two_files_is_a_compile_error(): void
    {
        $engine = new ScriptEngine('vue', [$this->views(['chart.client.vue' => '', 'chart/client.vue' => ''])], ['vue']);

        $this->expectException(CompileException::class);
        $this->expectExceptionMessageMatches('/View \[chart\.client\] of .* is two files: .*chart\.client\.vue and .*client\.vue\. Rename one, or name the view by its path with slashes\./');

        $engine->file($this->named('chart.client'));
    }

    public function test_a_namespaces_view_reads_dots_as_folders_too(): void
    {
        $engine = new ScriptEngine('vue', [], ['vue']);
        $engine->addNamespace('shop', [$this->views(['cart/badge.vue' => ''])]);

        $this->assertSame('shop::cart/badge', $engine->resolved($this->named('shop::cart.badge')));
    }

    /** What the browser loads and what the render service renders: the path, so neither reads dots. */
    public function test_the_browser_and_the_render_service_get_the_path(): void
    {
        $root = $this->views(['auth/login.vue' => '']);
        $engine = new ScriptEngine('vue', [$root], ['vue']);
        $meta = $this->named('auth.login');

        $definition = (new \Nitro\Compiler\ComponentCompiler(fn () => false))->compile($meta, null, $engine->file($meta), $engine->resolved($meta));
        $this->assertStringContainsString('view: "auth/login",', $definition);

        $renderer = new class implements \Nitro\View\ServerRendering\ServerRenderer
        {
            public ?\Nitro\View\ServerRendering\ViewState $state = null;

            public function render(\Nitro\View\ServerRendering\ViewState $state): ?string
            {
                $this->state = $state;

                return null;
            }
        };

        $this->boot('laravel');
        (new ScriptEngine('vue', [$root], ['vue'], $renderer))->render(new ($meta->class), $meta);
        $this->assertSame('auth/login', $renderer->state?->view);
    }
}
