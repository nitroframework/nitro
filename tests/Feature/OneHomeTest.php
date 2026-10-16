<?php

namespace Nitro\Tests\Feature;

use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentReader;
use Nitro\Tests\Fixtures\Invalid\SetInVueView;
use Nitro\Tests\Support\Node;
use Nitro\Tests\TestCase;
use Nitro\View\Adapters\VueAdapter;
use Nitro\View\ScriptEngine;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Each concept has one home, and the rest of Nitro asks it: a list of names written out again
 * elsewhere drifts from the first one. Where a name may be written is guarded by tests/Architecture;
 * these test that the homes themselves hold: config, the JavaScript views' names, the Vite plugin.
 */
class OneHomeTest extends TestCase
{
    /**
     * Two homes that cannot drift: the Vite plugin (Node, so it cannot ask PHP) leaves a <php>
     * element block out of the files that hold one, which are the adapters that read one.
     */
    public function test_vite_knows_the_files_that_hold_a_php_element_block_as_the_adapters_do(): void
    {
        $this->boot('laravel');
        $block = "<php>\nnew class extends Component {};\n</php>\n";
        $extensions = [];

        /** Nitro's adapters, with their files' extensions as config/nitro.php gives them. */
        foreach (require dirname(__DIR__, 2).'/config/nitro.php' as $key => $value) {
            if ($key !== 'views') {
                continue;
            }

            foreach ($value as $view) {
                $adapter = isset($view['adapter']) ? new $view['adapter']([], (array) $view['extensions']) : null;

                if ($adapter !== null && $adapter->phpBlock($block, 'view') !== null) {
                    $extensions = [...$extensions, ...$adapter->extensions()];
                }
            }
        }

        preg_match('/\/\\\\\.\(([a-z|]+)\)\(\$\|\\\\\?\)\//', (string) file_get_contents(dirname(__DIR__, 2).'/js/vite.js'), $match);

        $this->assertNotEmpty($match, 'vite.js names the files whose <php> block it leaves out');
        $this->assertEqualsCanonicalizing($extensions, explode('|', $match[1]));
    }

    /**
     * The same two homes for only(): the Vite plugin's React and Solid folders are the ones
     * config/nitro.php gives, and only() tells a plugin its own views from the other's.
     */
    public function test_vite_knows_where_react_and_solid_views_are_as_config_does(): void
    {
        $this->boot('laravel');
        $vite = Node::run('js/test/support/run-only.mjs', []);

        $this->assertSame([], $vite['failures']);

        /** Nitro's own defaults, not the fixture app's. */
        $views = (require dirname(__DIR__, 2).'/config/nitro.php')['views'];

        foreach (['react', 'solid'] as $framework) {
            $folders = array_map(
                fn (string $path) => str_replace('\\', '/', substr($path, strlen(base_path()) + 1)),
                (array) $views[$framework]['path'],
            );

            $this->assertSame($folders, $vite['folders'][$framework], "{$framework}'s folders");
        }
    }

    #[DataProvider('frameworks')]
    public function test_the_config_files_are_the_one_home_of_the_defaults(string $framework): void
    {
        $this->boot($framework);
        $config = $this->app->make('config');

        $this->assertSame(5, $config->get('nitro.ssr.timeout'), 'the app\'s value wins');
        $this->assertSame(base_path('bootstrap/ssr/ssr.js'), $config->get('nitro.ssr.bundle'), 'a key its published file lacks comes from Nitro\'s, deep inside');
        $this->assertSame(['jsx', 'tsx'], $config->get('nitro.views.react.extensions'), 'a list is the app\'s whole');
        $this->assertSame(['js'], $config->get('nitro.views.plain.extensions'), 'an entry of its own stays');
        $this->assertNotNull($config->get('nprogress.trickle_speed'));
        $this->assertStringContainsString('public function get(string $key): mixed', (string) file_get_contents(dirname(__DIR__, 2).'/src/Support/Config.php'), 'Config::get() takes no default');
    }

    public function test_the_names_javascript_views_get_are_one_list(): void
    {
        $names = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/js/adapters/names.json'), true);
        $adapter = new VueAdapter([], ['vue']);

        $this->assertSame([...$names['functions'], ...$names['values']], $adapter->reservedNames(), 'PHP reads the list the core reads');
        $this->assertSame([], (new ScriptEngine('mine', [], ['mine']))->reservedNames(), 'an engine of the app\'s own reserves nothing');

        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('$set of '.SetInVueView::class.': a Vue view gets set from Nitro');

        ComponentReader::read(SetInVueView::class, 'set-in-vue-view', 'nitro.components.set-in-vue-view')->assertNamesFree($adapter->reservedNames(), $adapter->label());
    }
}
