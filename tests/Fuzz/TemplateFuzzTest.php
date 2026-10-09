<?php

namespace Nitro\Tests\Fuzz;

use Illuminate\Support\MessageBag;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentCompiler;
use Nitro\Support\Wire;
use Nitro\Compiler\StateProperties;
use Nitro\Tests\Support\Node;
use Nitro\Tests\TestCase;
use Throwable;

/**
 * Template fuzzing: random views (TemplateGenerator) rendered by Blade on the server and by their
 * compiled render function in Node, built, tracked and hydrated (js/test/support/run-templates.mjs):
 * the DOM must be the same. Off unless asked for:
 *
 *   FUZZ_TEMPLATES=500 FUZZ_SEED=1 phpunit tests/Fuzz/TemplateFuzzTest.php
 *
 * A view Blade cannot render (an error in PHP) or the compiler refuses is skipped; a compiler
 * crash or a different DOM fails, with the seed that makes it again (FUZZ_SEED=N FUZZ_TEMPLATES=1).
 */
class TemplateFuzzTest extends TestCase
{
    public function test_random_views_render_in_the_browser_what_blade_renders(): void
    {
        $count = (int) getenv('FUZZ_TEMPLATES');

        if ($count < 1) {
            $this->markTestSkipped('Set FUZZ_TEMPLATES=N to fuzz N random views.');
        }

        $seed = (int) (getenv('FUZZ_SEED') ?: random_int(1, 1_000_000));
        $this->boot('laravel');

        $request = \Illuminate\Http\Request::create('/');
        $session = $this->app->make('session')->driver();
        $session->start();
        $request->setLaravelSession($session);
        $this->app->instance('request', $request);

        $views = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nitro-template-fuzz';
        @mkdir($views);
        $this->app['view']->addLocation($views);

        /** Before the fuzz components are registered: globals() compiles every component there is. */
        $globals = app(\Nitro\Rendering\Scripts::class)->globals();
        $compiler = new ComponentCompiler(fn () => false, cloneStatic: true);
        $cases = [];
        $definitions = [];
        $programs = [];
        $skipped = [];
        $crashes = [];

        for ($i = 0; $i < $count; $i++) {
            $programSeed = $seed + $i;
            $program = (new TemplateGenerator($programSeed))->program();
            $name = "fuzz-t{$programSeed}";
            $class = "FuzzT{$programSeed}";
            $view = "fuzzt{$programSeed}";
            file_put_contents("{$views}/{$view}.blade.php", $program['view']);

            $properties = implode("\n", array_map(fn ($p, $v) => "    public \${$p} = {$v};", array_keys($program['state']), $program['state']));
            eval("namespace Nitro\\Tests\\Fuzz\\Generated;\n\n#[\\Nitro\\Attributes\\View('{$view}')]\nclass {$class} extends \\Nitro\\Component\n{\n{$properties}\n}");
            app(\Nitro\Support\Registry::class)->register($name, "Nitro\\Tests\\Fuzz\\Generated\\{$class}");
            $meta = app(\Nitro\Compiler\Components::class)->meta($name);

            try {
                $definition = $compiler->compile($meta, $program['view'], "{$views}/{$view}.blade.php");
            } catch (CompileException $e) {
                $skipped['refused: '.strtok($e->reason, "\n")] = ($skipped['refused: '.strtok($e->reason, "\n")] ?? 0) + 1;

                continue;
            } catch (Throwable $e) {
                $crashes[] = "seed {$programSeed}: ".get_class($e).': '.$e->getMessage()."\n".$program['view'];

                continue;
            }

            $component = $this->app->make($meta->class);
            $component->nitro()->id = 'p';
            $component->nitro()->name = $name;
            $component->nitro()->errors = new MessageBag;

            try {
                $ssr = app(\Nitro\Rendering\ComponentRenderer::class)->render($component, $meta);
            } catch (Throwable $e) {
                $reason = 'Blade: '.(new \ReflectionClass($e->getPrevious() ?? $e))->getShortName();
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

                continue;
            }

            $definitions[] = $definition;
            $programs[$programSeed] = $program;
            $cases[] = [
                'id' => $programSeed,
                'name' => $name,
                'componentId' => 'p',
                'state' => Wire::encode(StateProperties::values($component)),
                'errors' => Wire::encode([]),
                'ssr' => $ssr,
                'page' => '',
                'emptyPage' => '',
            ];
        }

        $failures = $cases === [] ? [] : Node::run('js/test/support/run-templates.mjs', [
            'bundle' => "(function (Nitro, P, B) {\n\"use strict\";\n".implode("\n\n", $definitions)."\n})(window.Nitro, window.Nitro.P, window.Nitro.B);\n",
            'csrf' => csrf_token(),
            'globals' => $globals,
            'cases' => $cases,
        ], 600);

        /** One report per view (each fails up to three ways: built, tracked, hydrated). */
        $byView = [];

        foreach ($failures as $failure) {
            $byView[$failure['id']][] = $failure;
        }

        arsort($skipped);
        fwrite(STDERR, sprintf("\n  Template fuzz: seeds %d to %d, %d views compared, %d skipped, %d crashes, %d different\n", $seed, $seed + $count - 1, count($cases), array_sum($skipped), count($crashes), count($byView)));

        foreach (array_slice($skipped, 0, 6, true) as $reason => $times) {
            fwrite(STDERR, "    skipped {$times}x  {$reason}\n");
        }

        $report = $crashes;

        foreach (array_slice($byView, 0, 8, true) as $id => $list) {
            $program = $programs[$id];
            $report[] = "seed {$id} (".implode(', ', array_map(fn ($f) => preg_replace('/^.*\((\w+)\)$/', '$1', $f['name']), $list)).")\n"
                .'state: '.json_encode($program['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
                ."view:  {$program['view']}\n"
                ."--- server\n{$list[0]['expected']}\n--- browser\n{$list[0]['actual']}";
        }

        $this->assertSame([], $report, implode("\n\n", $report));
    }
}
