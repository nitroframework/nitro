<?php

namespace Nitro\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Nitro\Attributes\View;
use Nitro\Build\Bundle;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentReader;
use Nitro\Compiler\Components;
use Nitro\Component;
use Nitro\Support\Naming;
use Nitro\Support\Protocol;
use Nitro\Support\Registry;
use Nitro\Tests\Support\Node;
use Nitro\Tests\TestCase;
use Nitro\View\ScriptEngine;
use Nitro\View\ViewEngines;

/**
 * nitro.namespaces: a module's components, pages and stores in folders of its own, named with
 * the namespace ("shop::cart-badge", "pages::shop::orders.index", "stores::shop::basket"),
 * placed as <nitro:shop::cart-badge />, their Vue views "shop::tally". The module here is
 * written to a folder of the test's own, as a module of an app would be.
 */
class NamespacesTest extends TestCase
{
    private const PHP = 'NitroNamespacesTest\\Shop';

    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nitro-namespaces-'.bin2hex(random_bytes(4));
        $php = self::PHP;

        self::write('Ui/CartBadge.php', "<?php namespace {$php}\\Ui; class CartBadge extends \\Nitro\\Component { public int \$count = 0; }");
        self::write('Ui/OrderList.php', "<?php namespace {$php}\\Ui; class OrderList extends \\Nitro\\Component { public int \$open = 2; }");
        /** Its pages inside its components' folder: pages all the same. */
        self::write('Ui/Pages/Orders/Index.php', "<?php namespace {$php}\\Ui\\Pages\\Orders; #[\\Nitro\\Attributes\\Title('Orders')] class Index extends \\Nitro\\Component { }");
        self::write('Stores/Basket.php', "<?php namespace {$php}\\Stores; class Basket extends \\Nitro\\Store { public array \$items = []; }");
        self::write('views/components/cart-badge.blade.php', '<span class="badge">{{ $count }}</span>');
        self::write('views/components/order-list.blade.php', '<div class="orders"><nitro:shop::cart-badge :count="$open" /></div>');
        self::write('views/pages/orders/index.blade.php', '<div>Orders</div>');
        self::write('vue/tally.vue', "<php>\nnew class extends Component {\n    public int \$count = 1;\n};\n</php>\n<template><b>{{ count }}</b></template>\n");
        /** Its view by #[View] in a Blade namespace of another folder (View::addNamespace), not its own views. */
        self::write('Ui/Login.php', "<?php namespace {$php}\\Ui; #[\\Nitro\\Attributes\\View('admin::login')] class Login extends \\Nitro\\Component { public string \$email = ''; }");
        self::write('admin-views/login.blade.php', '<form class="login"><input nitro:model="email"> {{ $email }}</form>');

        foreach (['Ui/CartBadge.php', 'Ui/OrderList.php', 'Ui/Login.php', 'Ui/Pages/Orders/Index.php', 'Stores/Basket.php'] as $file) {
            require_once self::$root.'/'.$file;
        }
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem)->deleteDirectory(self::$root);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->boot('laravel');
        $this->app->make('config')->set('nitro.namespaces.shop', self::shop());
        $this->app->make('view')->addNamespace('shop', self::$root.'/views');
        $this->app->make('view')->addNamespace('admin', self::$root.'/admin-views');
        /** As between requests: the parts read nitro.* again. */
        $this->app->forgetScopedInstances();
    }

    /** @return array<string, mixed> */
    private static function shop(): array
    {
        return [
            'components' => ['namespace' => self::PHP.'\\Ui', 'path' => self::$root.'/Ui', 'views' => 'shop::components'],
            'pages' => ['namespace' => self::PHP.'\\Ui\\Pages', 'path' => self::$root.'/Ui/Pages', 'views' => 'shop::pages'],
            'stores' => ['namespace' => self::PHP.'\\Stores', 'path' => self::$root.'/Stores'],
            'views' => ['vue' => self::$root.'/vue'],
        ];
    }

    private static function write(string $path, string $contents): void
    {
        @mkdir(dirname(self::$root.'/'.$path), 0777, true);
        file_put_contents(self::$root.'/'.$path, $contents);
    }

    public function test_a_namespaces_components_pages_and_stores_are_found_and_named_in_it(): void
    {
        $registry = $this->app->make(Registry::class);
        $all = $registry->all();

        $this->assertSame(self::PHP.'\\Ui\\CartBadge', $all['shop::cart-badge'] ?? null);
        $this->assertSame(self::PHP.'\\Ui\\Pages\\Orders\\Index', $all['pages::shop::orders.index'] ?? null);
        $this->assertSame(self::PHP.'\\Stores\\Basket', $all['stores::shop::basket'] ?? null);
        $this->assertArrayNotHasKey('shop::pages.orders.index', $all, 'a page in the components\' folder is the pages\' location\'s, the most specific');
        $this->assertArrayHasKey('counter', $all, 'the app\'s own are found as before');

        $this->assertTrue($registry->isPageClass(self::PHP.'\\Ui\\Pages\\Orders\\Index'));
        $this->assertFalse($registry->isPageClass(self::PHP.'\\Ui\\CartBadge'));
        $this->assertSame('shop::components.cart-badge', $registry->view('shop::cart-badge'));
        $this->assertSame('shop::pages.orders.index', $registry->view('pages::shop::orders.index'));
    }

    public function test_a_namespace_without_views_has_them_in_the_blade_namespace_of_its_name(): void
    {
        $shop = self::shop();
        unset($shop['components']['views']);
        $this->app->make('config')->set('nitro.namespaces.shop', $shop);
        $this->app->forgetScopedInstances();

        $this->assertSame('shop::cart-badge', $this->app->make(Registry::class)->view('shop::cart-badge'));
    }

    public function test_a_tag_places_a_namespaced_component_in_blade(): void
    {
        $view = self::APP.'/resources/views/namespaced-tag.blade.php';
        file_put_contents($view, "<main><nitro:shop::cart-badge :count=\"3\" /></main>\n");

        try {
            $html = view('namespaced-tag')->render();
        } finally {
            @unlink($view);
        }

        $this->assertMatchesRegularExpression('/<span nitro:id="\w+" nitro:name="shop::cart-badge" class="badge">3<\/span>/', $html);
    }

    /** #[View('admin::login')]: a Blade view of any folder Laravel's view finder knows, by its Blade namespace. */
    public function test_a_view_named_in_a_blade_namespace_of_another_folder_renders_on_both_sides(): void
    {
        $this->assertSame('admin::login', $this->app->make(Components::class)->meta('shop::login')->view);

        $view = self::APP.'/resources/views/admin-login-tag.blade.php';
        file_put_contents($view, "<main><nitro:shop::login email=\"ada@example.com\" /></main>\n");

        try {
            $html = view('admin-login-tag')->render();
        } finally {
            @unlink($view);
        }

        $this->assertMatchesRegularExpression('/<form nitro:id="\w+" nitro:name="shop::login" class="login">/', $html, 'the server renders it from admin-views/login.blade.php');
        $this->assertStringContainsString('ada@example.com</form>', $html);

        $bundle = implode("\n", $this->app->make(Bundle::class)->compileAll());
        $this->assertMatchesRegularExpression('/Nitro\.define\("shop::login", \{/', $bundle, 'and the browser has it compiled');
    }

    public function test_a_namespaced_child_compiles_for_the_browser(): void
    {
        $bundle = implode("\n", $this->app->make(Bundle::class)->compileAll());

        preg_match('/Nitro\.define\("shop::order-list", \{[\s\S]*?\n\}\);/', $bundle, $definition);
        $this->assertNotEmpty($definition, 'the namespaced component is in the bundle');
        $this->assertStringContainsString('"shop::cart-badge"', $definition[0], 'its child, by its namespaced name');
    }

    public function test_a_namespaced_page_is_a_chunk_whose_name_holds_no_separator(): void
    {
        $this->assertSame('shop.orders.index', $this->app->make(Components::class)->meta('pages::shop::orders.index')->lazy);
    }

    public function test_a_namespaces_vue_view_is_looked_for_in_its_views_and_may_hold_its_php(): void
    {
        $components = $this->app->make(Components::class);
        $meta = $components->meta('shop::tally');

        $this->assertSame('Nitro\\Views\\Namespaced\\Shop\\Vue\\Tally', $this->app->make(Registry::class)->class('shop::tally'));
        $this->assertSame('shop::tally', $meta->view);
        $this->assertSame(self::$root.'/vue'.DIRECTORY_SEPARATOR.'tally.vue', $this->app->make(ViewEngines::class)->of($meta)->file($meta));
    }

    public function test_a_view_of_a_namespace_without_views_for_its_engine_is_a_compile_error(): void
    {
        $engine = new ScriptEngine('vue', [resource_path('views/vue')], ['vue']);
        $meta = ComponentReader::read(get_class(new #[View(vue: 'shop::tally')] class extends Component {}), 'card', 'nitro.card');

        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('[shop] has no vue views (nitro.namespaces.shop.views.vue)');

        $engine->file($meta);
    }

    public function test_a_namespace_is_named_in_kebab_case_and_not_as_a_kind(): void
    {
        foreach (['Shop', 'pages', 'stores', 'my_shop'] as $name) {
            try {
                new Registry(['namespace' => 'A', 'path' => '', 'views' => ''], ['namespace' => 'B', 'path' => '', 'views' => ''], namespaces: [$name => []]);
                $this->fail("[{$name}] was taken as a namespace");
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString("[{$name}] cannot name a namespace", $exception->getMessage());
            }
        }
    }

    public function test_names_carry_their_namespace(): void
    {
        $this->assertSame([null, 'shop', 'shop', 'shop'], array_map(Naming::namespaceOf(...), ['posts.index', 'shop::cart', 'pages::shop::orders.index', 'stores::shop::basket']));
        $this->assertSame(['cart', 'orders.index', 'posts.index'], array_map(Naming::local(...), ['shop::cart', 'pages::shop::orders.index', 'pages::posts.index']));
        $this->assertSame(['posts.index', 'shop.orders.index'], array_map(Naming::group(...), ['pages::posts.index', 'pages::shop::orders.index']));
        $this->assertTrue(Naming::isValid('shop::cart-badge'));
        $this->assertFalse(Naming::isValid('shop::'));
        $this->assertFalse(Naming::isValid('Shop::cart'));
        $this->assertSame(Protocol::get('view')['namespaceSeparator'], Naming::SEPARATOR, 'the separator the adapters read is the one names are written with');
    }

    public function test_the_adapters_find_a_namespaced_view_among_its_namespaces_views_only(): void
    {
        $failures = Node::run('js/test/support/run-find-view.mjs', [
            'app' => ['../views/vue/list.vue', '../views/vue/cart/badge.vue'],
            'namespaces' => [
                'shop' => ['../../modules/Shop/vue/list.vue', '../../modules/Shop/vue/orders/table.vue'],
                'blog' => ['../../modules/Blog/vue/list.vue'],
            ],
            'cases' => [
                ['list', '../views/vue/list.vue'],
                ['cart/badge', '../views/vue/cart/badge.vue'],
                ['shop::list', '../../modules/Shop/vue/list.vue'],
                ['shop::orders/table', '../../modules/Shop/vue/orders/table.vue'],
                ['blog::list', '../../modules/Blog/vue/list.vue'],
                ['orders/table', null],
                ['blog::cart/badge', null],
                ['news::list', null],
            ],
        ]);

        $this->assertSame([], $failures);
    }
}
