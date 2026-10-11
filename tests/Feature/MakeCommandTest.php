<?php

namespace Nitro\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Nitro\Tests\TestCase;

/**
 * make:nitro writes a component (class and view), a page (class with #[Title] and mount(), and
 * view) or a store (class), where nitro.components, nitro.pages and nitro.stores say, and tells
 * what to do next. Here every path is in a folder of the test's own.
 */
class MakeCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boot('laravel');

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nitro-make-'.bin2hex(random_bytes(4));
        $config = $this->app->make('config');
        foreach (['components', 'pages', 'stores'] as $kind) {
            $config->set("nitro.{$kind}.namespace", 'App\\Nitro\\'.ucfirst($kind));
            $config->set("nitro.{$kind}.path", $this->dir.'/app/Nitro/'.ucfirst($kind));
            $config->set("nitro.{$kind}.views", "nitro.{$kind}");
        }
        $this->app->make('view')->getFinder()->setPaths([$this->dir.'/views']);
        /** As between requests: the parts read nitro.* again (Registry is per request). */
        $this->app->forgetScopedInstances();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @return array{int, string} the exit code and what it printed */
    private function make(string ...$arguments): array
    {
        $code = $this->console()->call('make:nitro', ['name' => array_shift($arguments), ...array_fill_keys($arguments, true)]);

        return [$code, $this->console()->output()];
    }

    private function file(string $path): string
    {
        $this->assertFileExists($this->dir.'/'.$path);

        return (string) file_get_contents($this->dir.'/'.$path);
    }

    /** Each file written is PHP that parses. */
    private function assertParses(string $path): void
    {
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($this->dir.'/'.$path), $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    public function test_a_component_is_a_class_and_a_view(): void
    {
        [$code, $output] = $this->make('Counter');

        $this->assertSame(0, $code, $output);
        $class = $this->file('app/Nitro/Components/Counter.php');
        $this->assertStringContainsString("namespace App\\Nitro\\Components;\n", $class);
        $this->assertStringContainsString('class Counter extends Component', $class);
        $this->assertParses('app/Nitro/Components/Counter.php');
        $this->assertStringStartsWith('<div>', $this->file('views/nitro/components/counter.blade.php'), 'one root element');
        $this->assertStringContainsString('<nitro:counter />', $output, 'where to use it');
    }

    public function test_a_page_in_a_folder(): void
    {
        [$code, $output] = $this->make('posts/index', '--page');

        $this->assertSame(0, $code, $output);
        $class = $this->file('app/Nitro/Pages/Posts/Index.php');
        $this->assertStringContainsString("namespace App\\Nitro\\Pages\\Posts;\n", $class);
        $this->assertStringContainsString("#[Title('Posts Index')]", $class);
        $this->assertStringContainsString('public function mount(): void', $class);
        $this->assertParses('app/Nitro/Pages/Posts/Index.php');
        $this->file('views/nitro/pages/posts/index.blade.php');
        $this->assertStringContainsString("Route::nitro('/posts', \\App\\Nitro\\Pages\\Posts\\Index::class);", $output, 'an Index is its folder\'s page');
    }

    public function test_a_store_has_no_view(): void
    {
        [$code, $output] = $this->make('ShoppingCart', '--store');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('class ShoppingCart extends Store', $this->file('app/Nitro/Stores/ShoppingCart.php'));
        $this->assertParses('app/Nitro/Stores/ShoppingCart.php');
        $this->assertDirectoryDoesNotExist($this->dir.'/views');
        $this->assertStringContainsString('public \\App\\Nitro\\Stores\\ShoppingCart $shoppingCart;', $output);
    }

    public function test_a_file_there_already_is_left_alone_without_force(): void
    {
        $this->make('Counter');
        file_put_contents($this->dir.'/app/Nitro/Components/Counter.php', '<?php // mine');

        [$code, $output] = $this->make('Counter');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('is there already: --force writes over it', $output);
        $this->assertSame('<?php // mine', $this->file('app/Nitro/Components/Counter.php'));

        [$code] = $this->make('Counter', '--force');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('class Counter', $this->file('app/Nitro/Components/Counter.php'));
    }

    public function test_what_is_not_a_name_or_not_one_kind(): void
    {
        $this->assertSame(1, $this->make('1st')[0], 'a name starts with a letter');
        $this->assertSame(1, $this->make('Cart', '--page', '--store')[0]);
        $this->assertDirectoryDoesNotExist($this->dir.'/app');
    }

    /** issues::CommentBox: in the namespace's folders, its view in the Blade namespace the module loads. */
    public function test_a_component_of_a_namespace(): void
    {
        $this->app->make('config')->set('nitro.namespaces.issues', [
            'components' => ['namespace' => 'Issues\\Ui', 'path' => $this->dir.'/modules/Issues/Ui', 'views' => 'issues::components'],
        ]);
        $this->app->make('view')->addNamespace('issues', $this->dir.'/modules/Issues/views');
        $this->app->forgetScopedInstances();

        [$code, $output] = $this->make('issues::CommentBox');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString("namespace Issues\\Ui;\n", $this->file('modules/Issues/Ui/CommentBox.php'));
        $this->assertParses('modules/Issues/Ui/CommentBox.php');
        $this->file('modules/Issues/views/components/comment-box.blade.php');
        $this->assertStringContainsString('<nitro:issues::comment-box />', $output);

        [$code, $output] = $this->make('issues::Orders/Index', '--page');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('No place for pages of [issues] is configured (nitro.namespaces.issues)', $output);

        [$code, $output] = $this->make('billing::Invoice');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('There is no namespace [billing] in nitro.namespaces', $output);
    }

    /** The app's own stubs/nitro.*.stub, when it has one, in place of Nitro's. */
    public function test_the_apps_own_template(): void
    {
        $own = $this->app->basePath('stubs/nitro.component.stub');
        (new Filesystem)->ensureDirectoryExists(dirname($own));
        file_put_contents($own, "<?php\n\nnamespace {{ namespace }};\n\n// Ours.\nclass {{ class }} extends \\Nitro\\Component {}\n");

        try {
            $this->make('Counter');
            $this->assertStringContainsString('// Ours.', $this->file('app/Nitro/Components/Counter.php'));
        } finally {
            (new Filesystem)->deleteDirectory(dirname($own));
        }
    }
}
