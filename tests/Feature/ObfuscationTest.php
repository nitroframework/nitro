<?php

namespace Nitro\Fusion\Tests\Feature;

use Illuminate\Testing\TestResponse;
use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Compiler\Obfuscator;
use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Testing\AssertableFusion;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An obfuscated build (fusion.build.obfuscate): methods, #[Server] methods and pages under
 * aliases keyed by APP_KEY, in the bundle and in the page; handlers' text left out of the page.
 * (The browser tests run every scenario against one too.)
 */
class ObfuscationTest extends TestCase
{
    private const COMPILED = self::APP.'/storage/framework/fusion';

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->console()->call('fusion:clear');
        }

        parent::tearDown();
    }

    private function build(): Obfuscator
    {
        config(['fusion.build.obfuscate' => true]);
        $this->app->forgetScopedInstances();
        $this->assertSame(0, $this->console()->call('fusion:build'), $this->console()->output());

        return $this->app->make(Bundle::class)->obfuscator() ?? $this->fail('the build is not obfuscated');
    }

    private function page(string $uri): TestResponse
    {
        $this->app->forgetScopedInstances();

        return TestResponse::fromBaseResponse($this->get($uri));
    }

    #[DataProvider('frameworks')]
    public function test_the_bundle_names_methods_and_pages_by_aliases(string $framework): void
    {
        $this->boot($framework);
        $names = $this->build();
        $bundle = $this->app->make(Bundle::class);
        $code = implode("\n", array_map('file_get_contents', glob(self::COMPILED.'/app-'.$bundle->version().'*.min.js')));

        /** Counter: increment(), decrement() and doubled() run in the browser; save() is #[Server]. */
        foreach (['increment', 'decrement', 'doubled', 'save'] as $method) {
            $this->assertMatchesRegularExpression('/^m[0-9a-f]{7}$/', $names->method($method));
            $this->assertStringContainsString($names->method($method), $code);
            /** As code names a method: a key of methods, a call, a #[Server] call, a listed #[Server] method. */
            $this->assertDoesNotMatchRegularExpression('/[{,]"?'.$method.'"?:function|\.'.$method.'\(|\$call\("'.$method.'"|server:\[[^\]]*"'.$method.'"/', $code, "{$method}: not by its name");
        }

        $this->assertStringNotContainsString('pages::', $code, 'pages by aliases');
        $this->assertStringContainsString('"'.$names->component('pages::counter').'"', $code);
        $this->assertSame('pages::counter', $names->componentName($names->component('pages::counter')));
        $this->assertSame('counter', $names->component('counter'), 'components keep their names: the fixture app has a <fusion:dynamic-component>');
        $this->assertNotSame('mounted', $names->method('mounted'), 'hooks are aliased as any method: the runtime finds them by the definition');
        $this->assertNotSame('updatedStep', $names->method('updatedStep'), 'a property hook too');

        /** Properties: by aliases in the definitions, and so in every read. */
        $this->assertMatchesRegularExpression('/^p[0-9a-f]{7}$/', $names->property('count'));
        $this->assertStringContainsString('props:{'.$names->property('count').':', $code);
        $this->assertDoesNotMatchRegularExpression('/props:\{(count|step|history):/', $code);
        $this->assertSame('form.email', $names->propertyPath($names->path('form.email'), ['form']), 'a path keeps the keys under its property');
    }

    #[DataProvider('frameworks')]
    public function test_the_page_leaves_handlers_text_and_names_out(string $framework): void
    {
        $this->boot($framework);
        $names = $this->build();

        $html = (string) $this->page('/counter')->getContent();

        $this->assertStringNotContainsString('fusion:click="increment"', $html);
        $this->assertStringContainsString('fusion:click=""', $html);
        $this->assertStringContainsString('fusion:target="'.$names->method('save').'"', $html, "the counter's fusion:target");
        $this->assertStringContainsString('fusion:model.number="'.$names->property('step').'"', $html, 'fusion:model by the property\'s alias');
        $this->assertStringNotContainsString('\&quot;count\&quot;', $html, 'the snapshot names the properties by their aliases');
        $this->assertStringNotContainsString('pages::counter', $html);
        $this->assertStringContainsString('fusion:name="'.$names->component('pages::counter').'"', $html);

        /** The upload's fusion:target names a property: by its alias. */
        $this->assertStringContainsString('fusion:target="'.$names->property('photo').'"', (string) $this->page('/gallery')->getContent());
    }

    #[DataProvider('frameworks')]
    public function test_a_server_call_by_its_alias_runs_the_method(string $framework): void
    {
        $this->boot($framework);
        $names = $this->build();
        $snapshot = $this->snapshots((string) $this->page('/counter')->getContent())[0]['envelope'];

        $this->app->forgetScopedInstances();
        $response = $this->update(['snapshot' => $snapshot, 'updates' => [$names->property('count') => 3], 'call' => ['method' => $names->method('save'), 'params' => []]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $state = Wire::fromJson($this->json($response)['snapshot']['state']);
        $this->assertSame($names->component('pages::counter'), $state['name'], 'the answer names the page by its alias too');
        $this->assertSame(3, $state['data'][$names->property('count')], 'updates and answers by the properties\' aliases');
        $this->assertArrayNotHasKey('count', $state['data']);
    }

    #[DataProvider('frameworks')]
    public function test_the_test_helpers_see_the_page_by_its_name(string $framework): void
    {
        $this->boot($framework);
        $this->build();

        $this->page('/counter')->assertFusion(fn (AssertableFusion $page) => $page->page('pages::counter')->has('count')->where('step', 1));
    }

    #[DataProvider('frameworks')]
    public function test_turning_it_on_or_off_makes_another_build(string $framework): void
    {
        $this->boot($framework);
        $plain = $this->app->make(Bundle::class)->version();

        config(['fusion.build.obfuscate' => true]);
        $this->app->forgetScopedInstances();

        $this->assertNotSame($plain, $this->app->make(Bundle::class)->version());
    }

    public function test_error_fields_are_by_the_properties_aliases_written_out_or_built(): void
    {
        $names = new Obfuscator('key', [], aliasComponents: true);
        $scope = (new \Nitro\Fusion\Compiler\Scope(properties: ['items', 'form']))->withNames($names);
        $templates = new \Nitro\Fusion\Compiler\Template\TemplateCompiler(fn () => false);
        $templates->compile(
            "<div>\n@error('form.email') <p>{{ \$message }}</p> @enderror\n"
            ."@foreach (\$items as \$i => \$item)\n    @error('items.'.\$i.'.price') <p>{{ \$message }}</p> @enderror\n"
            ."    <span>{{ \$errors->first('items.'.\$i.'.name') }}</span>\n@endforeach\n</div>",
            $scope,
            'view.blade.php',
        );
        $code = implode("\n", $templates->expressions());

        $this->assertStringContainsString('"'.$names->path('form.email').'"', $code);
        $this->assertStringContainsString('"'.$names->property('items').'."', $code, "'items.'.\$i: its start");
        $this->assertStringNotContainsString('"items.', $code);
        $this->assertStringNotContainsString('"form.', $code);
    }

    public function test_aliases_come_from_the_key(): void
    {
        $one = new Obfuscator('key-one', ['pages::orders', 'card'], aliasComponents: true);
        $two = new Obfuscator('key-two', ['pages::orders', 'card'], aliasComponents: true);

        $this->assertNotSame($one->method('save'), $two->method('save'));
        $this->assertSame($one->method('save'), (new Obfuscator('key-one', [], true))->method('save'), 'the same key, the same alias');
        $this->assertMatchesRegularExpression('/^c[0-9a-f]{9}$/', $one->component('card'));
        $this->assertSame('card', $one->componentName($one->component('card')));
        $this->assertSame('save', $one->methodName($one->method('save'), ['save', 'load']));
        $this->assertSame('save', $one->methodName('save', ['save']), 'its own name works too');
    }
}
