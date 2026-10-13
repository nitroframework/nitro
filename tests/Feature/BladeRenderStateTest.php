<?php

namespace Nitro\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Nitro\Compiler\Components;
use Nitro\Rendering\ComponentRenderer;
use Nitro\Tests\TestCase;

/**
 * Blade's render state around a component's view (BladeEngine): a Blade component in it that
 * fails makes Laravel flush the view factory (View::render()), and the views rendered after it,
 * a component in another's slot among them, render as they would have.
 */
class BladeRenderStateTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nitro-render-state-'.bin2hex(random_bytes(4));

        foreach ([
            'blade/box.blade.php' => '<div class="box">{{ $slot }}</div>',
            'blade/plain.blade.php' => "<b>{{ \$label ?? 'none' }}</b>",
            'blade/failing.blade.php' => '<i>{{ $undefined->name }}</i>',
            'views/components/boxed.blade.php' => '<div><x-state::box><x-state::plain label="L" /></x-state::box></div>',
            'views/components/failing-inside.blade.php' => '<div><x-state::box><x-state::failing /></x-state::box></div>',
            'blade/field.blade.php' => "@props(['name'])\n<p>@error(\$name)<b>{{ \$message }}</b>@enderror</p>",
            'views/components/with-field.blade.php' => '<div><x-state::field name="email" /></div>',
            'Cases/WithField.php' => '<?php namespace NitroRenderState; class WithField extends \Nitro\Component { public string $email = ""; }',
            'Cases/Boxed.php' => '<?php namespace NitroRenderState; class Boxed extends \Nitro\Component { }',
            'Cases/FailingInside.php' => '<?php namespace NitroRenderState; class FailingInside extends \Nitro\Component { }',
        ] as $path => $contents) {
            @mkdir(dirname(self::$root.'/'.$path), 0777, true);
            file_put_contents(self::$root.'/'.$path, $contents);
        }

        require_once self::$root.'/Cases/Boxed.php';
        require_once self::$root.'/Cases/WithField.php';
        require_once self::$root.'/Cases/FailingInside.php';
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem)->deleteDirectory(self::$root);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->boot('laravel');
        Blade::anonymousComponentPath(self::$root.'/blade', 'state');
        $this->app->make('view')->addNamespace('state', self::$root.'/views');
        config(['nitro.namespaces.state' => ['components' => ['namespace' => 'NitroRenderState', 'path' => self::$root.'/Cases', 'views' => 'state::components']]]);
        $this->app->forgetScopedInstances();
    }

    public function test_a_blade_component_in_the_view_reads_the_components_errors_as_the_browser_does(): void
    {
        $views = $this->app->make('view');
        $page = new \Illuminate\Support\ViewErrorBag;
        $views->share('errors', $page);
        $component = new \NitroRenderState\WithField;
        $component->nitro()->errors = new \Illuminate\Support\MessageBag(['email' => ['The email is taken.']]);

        $html = app(ComponentRenderer::class)->render($component, app(Components::class)->meta(\NitroRenderState\WithField::class));

        $this->assertStringContainsString('<b>The email is taken.</b>', $html, 'the component\'s errors, not the session\'s');
        $this->assertSame($page, $views->getShared()['errors'], 'the views after it see the session\'s again');
    }

    public function test_the_views_after_a_failing_blade_component_render_as_they_would_have(): void
    {
        $renderer = app(ComponentRenderer::class);
        $components = app(Components::class);

        try {
            $renderer->render(new \NitroRenderState\FailingInside, $components->meta(\NitroRenderState\FailingInside::class));
            $this->fail('the Blade component fails');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('$undefined', $e->getMessage());
        }

        $html = $renderer->render(new \NitroRenderState\Boxed, $components->meta(\NitroRenderState\Boxed::class));

        $this->assertStringContainsString('<div class="box"><b>L</b></div>', $html);
        $this->assertSame(0, (fn () => $this->renderCount)->call($this->app->make('view')), 'the factory counts no render left open');
    }
}
