<?php

namespace Nitro\Tests\Parity;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Nitro\Build\Bundle;
use Nitro\Component;
use Nitro\Compiler\Components;
use Nitro\Compiler\Obfuscator;
use Nitro\Compiler\StateProperties;
use Nitro\Rendering\ComponentRenderer;
use Nitro\Support\Protocol;
use Nitro\Support\Wire;
use Nitro\Tests\Support\Node;
use Nitro\Tests\TestCase;
use Illuminate\Support\ViewErrorBag;
use Nitro\Build\BrowserNames;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Blade conformance: every Blade feature Nitro compiles (Conformance/cases.php, the reviewer's; and
 * Conformance/nitro-cases.php, Nitro's own), in every context
 * a view can hold it (CONTEXTS), rendered by Blade on the server and by the compiled view in Node
 * (js/test/support/run-templates.mjs: built, tracked, hydrated), then changed to a second state
 * and updated: the same DOM each time. With and without obfuscation. Laravel's Blade renders
 * each case as the reference; Nitro's two renders of the compiled tree must give its DOM: the
 * server's (View\TreeRenderer, the HTML a page ships, which the browser hydrates) and the
 * browser's.
 *
 * Each case in each context is a component of its own, written to a folder of the test's and
 * compiled by the bundle as an app's are (ComponentCompiler), so what is checked is what ships.
 */
class BladeConformanceTest extends TestCase
{
    private const PHP = 'NitroConformance';

    /** A compiled definition larger than this is a sign of code copied where it should be shared. */
    private const MAX_DEFINITION_BYTES = 60_000;

    /** Where a case is placed. {S}: the case; {P}: the state passed to a component as attributes. */
    private const CONTEXTS = [
        'view' => '<div class="root">{S}</div>',
        'loop' => '<div class="root">@foreach ($items as $row)<section>{S}</section>@endforeach</div>',
        'if' => '<div class="root">@if ($flag)<section>{S}</section>@else<p>off</p>@endif</div>',
        'component' => '<div class="root"><x-conf::inner-{N} {P} /></div>',
        'slot' => '<div class="root"><x-conf::box>{S}</x-conf::box></div>',
        'slot passed on' => '<div class="root"><x-conf::outer>{S}</x-conf::outer></div>',
        'component in a component' => '<div class="root"><x-conf::nest-{N} {P} /></div>',
        /** The Nitro component's view is the Blade component: its root element is the Blade component's. */
        'component as the root' => '<x-conf::inner-{N} {P} />',
    ];

    /** The state every case has: its first values, then those it changes to. */
    private const STATE = ['name', 'count', 'price', 'flag', 'nothing', 'items', 'map', 'empty', 'html', 'tags'];

    private static string $root;

    /** @var list<array{id: string, case: string, context: string, class: string}> */
    private static array $generated = [];

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nitro-conformance-'.bin2hex(random_bytes(4));
        $php = self::PHP;
        $definition = require __DIR__.'/Conformance/cases.php';

        /** Nitro's own cases, after the reviewer's (their numbers stay theirs). */
        $own = require __DIR__.'/Conformance/nitro-cases.php';

        foreach (['components', 'partials', 'cases', 'refused'] as $part) {
            $definition[$part] = ($definition[$part] ?? []) + $own[$part];
        }

        $properties = "public string \$name = ''; public int \$count = 0; public float \$price = 0; public bool \$flag = false; public ?string \$nothing = null; "
            .'public array $items = []; public array $map = []; public array $empty = []; public string $html = \'\'; public array $tags = [];';
        self::write('Support/Base.php', "<?php namespace {$php}\\Support; abstract class Base extends \\Nitro\\Component { {$properties} public function noop(): void {} }");
        require_once self::$root.'/Support/Base.php';

        $props = implode(' ', array_map(fn ($name) => ":{$name}=\"\${$name}\"", self::STATE));
        $propsList = "@props(['name' => '', 'count' => 0, 'price' => 0, 'flag' => false, 'nothing' => null, 'items' => [], 'map' => [], 'empty' => [], 'html' => '', 'tags' => []])";

        foreach ($definition['components'] as $name => $view) {
            self::write("views/blade/{$name}.blade.php", $view);
        }
        foreach ($definition['partials'] as $name => $view) {
            self::write("views/partials/{$name}.blade.php", $view);
        }
        self::write('views/blade/box.blade.php', '<div class="box">{{ $slot }}</div>');
        self::write('views/blade/outer.blade.php', '<div class="outer"><x-conf::box>{{ $slot }}</x-conf::box></div>');

        $n = 0;

        /** NITRO_CONFORMANCE_ONLY="part|other part": only the cases whose name has one (while working on them). */
        $only = getenv('NITRO_CONFORMANCE_ONLY') ? explode('|', (string) getenv('NITRO_CONFORMANCE_ONLY')) : null;

        foreach ($definition['cases'] as $case => $source) {
            $n++;

            if ($only !== null && ! array_filter($only, fn ($part) => str_contains($case, $part))) {
                continue;
            }

            /** @use is a PHP use statement: at the top of a view, never inside a block (Blade's rule). */
            preg_match_all('/^@use\(.*\)$/m', $source, $uses);
            $imports = implode("\n", $uses[0]);
            $source = trim((string) preg_replace('/^@use\(.*\)$\n?/m', '', $source));
            self::write("views/blade/inner-{$n}.blade.php", ($imports === '' ? '' : "{$imports}\n")."{$propsList}\n<div class=\"inner\">{$source}</div>");
            self::write("views/blade/nest-{$n}.blade.php", "{$propsList}\n<div class=\"nest\"><x-conf::inner-{$n} {$props} /></div>");

            $refused = $definition['refused'][$case] ?? null;

            /**
             * A case Nitro refuses: in the view and in a component, where the message must point;
             * in a namespace of its own (Refused), registered only where the refusals are checked,
             * as a build stops at them.
             */
            $folder = $refused === null ? 'Cases' : 'Refused';

            foreach ($refused === null ? self::CONTEXTS : array_intersect_key(self::CONTEXTS, ['view' => 1, 'component' => 1]) as $context => $wrapper) {
                $class = 'C'.str_pad((string) $n, 3, '0', STR_PAD_LEFT).Str::studly($context);
                self::write("{$folder}/{$class}.php", "<?php namespace {$php}\\{$folder}; class {$class} extends \\{$php}\\Support\\Base { }");
                self::write('views/'.strtolower($folder).'/'.Str::kebab($class).'.blade.php', ($imports === '' ? '' : "{$imports}\n").str_replace(['{S}', '{N}', '{P}'], [$source, (string) $n, $props], $wrapper));
                require_once self::$root."/{$folder}/{$class}.php";
                self::$generated[] = ['id' => "{$n}", 'case' => $case, 'context' => $context, 'class' => "{$php}\\{$folder}\\{$class}", 'refused' => $refused];
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem)->deleteDirectory(self::$root);
    }

    protected function tearDown(): void
    {
        /** The obfuscated run's build: gone, as the other tests expect none. */
        if ($this->app !== null) {
            $this->console()->call('nitro:clear');
        }

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->boot('laravel');
        $this->app->make('config')->set('nitro.namespaces.conformance', [
            'components' => ['namespace' => self::PHP.'\\Cases', 'path' => self::$root.'/Cases', 'views' => 'conformance::cases'],
        ]);
        $this->app->make('view')->addNamespace('conformance', self::$root.'/views');
        Blade::anonymousComponentPath(self::$root.'/views/blade', 'conf');
        $this->app->forgetScopedInstances();

        /** As during a page request: a session (@csrf reads its token). */
        $request = \Illuminate\Http\Request::create('/');
        $session = $this->app->make('session')->driver();
        $session->start();
        $request->setLaravelSession($session);
        $this->app->instance('request', $request);
    }

    /** @return array<string, array{0: bool}> */
    public static function builds(): array
    {
        return ['readable' => [false], 'obfuscated' => [true]];
    }

    #[DataProvider('builds')]
    public function test_every_blade_feature_in_every_context_renders_as_blade_renders_it(bool $obfuscate): void
    {
        config(['nitro.build.obfuscate' => $obfuscate]);
        $this->app->forgetScopedInstances();

        /** Obfuscated: built, as an app deploys it, so the server's HTML is the build's too (aliases, no handlers' text). */
        if ($obfuscate) {
            $this->assertSame(0, $this->console()->call('nitro:build'), $this->console()->output());
            $this->app->forgetScopedInstances();
        }

        $bundleParts = $this->app->make(Bundle::class);
        $names = $bundleParts->obfuscator($obfuscate);
        $bundle = implode("\n", $bundleParts->compileAll($obfuscate));

        $failures = [];
        $cases = [];

        foreach (self::$generated as $i => $generated) {
            if ($generated['refused'] !== null) {
                continue;
            }

            $label = "{$generated['case']} · {$generated['context']}";
            $meta = app(Components::class)->meta($generated['class']);
            $browserName = $names?->component($meta->name) ?? $meta->name;

            /** A view the compiler refused: its message (the case is Blade Nitro compiles, so none should be). */
            try {
                $bundleParts->assertCompiled([$meta->name]);
            } catch (\Nitro\Compiler\CompileException $e) {
                $failures[] = "{$label}: does not compile: {$e->getMessage()}";

                continue;
            }

            /** Its compiled definition: not one that copies code over and over. */
            if (! preg_match('/Nitro\.define\('.preg_quote(json_encode($browserName), '/').', \{[\s\S]*?\n\}\);/', $bundle, $defined)) {
                $failures[] = "{$label}: no definition found to measure";
            } elseif (strlen($defined[0]) > self::MAX_DEFINITION_BYTES) {
                $failures[] = "{$label}: its definition is ".strlen($defined[0]).' bytes';
            }

            [$state, $errors, $ssr, $tree] = $this->rendered($meta, self::first(), ['count' => ['Count is wrong.']], $names);
            [$nextState, $nextErrors, $nextSsr, $nextTree] = $this->rendered($meta, self::second(), ['name' => ['Name is wrong.'], 'items.1.label' => ['Bad label.']], $names);

            if (str_starts_with($ssr, 'server error: ') || str_starts_with($nextSsr, 'server error: ')) {
                $failures[] = "{$label}: Blade: {$ssr}";

                continue;
            }

            $changed = [Protocol::signal('errors')];
            foreach (self::STATE as $property) {
                if ($state[$property] !== $nextState[$property]) {
                    $changed[] = $names?->property($property) ?? $property;
                }
            }

            $cases[] = [
                'id' => count($cases),
                'generated' => $i,
                'name' => $browserName,
                'componentId' => 'p',
                'state' => Wire::encode($this->browserKeys($state, $names)),
                'errors' => Wire::encode($this->browserErrors($errors, $names)),
                'ssr' => $ssr,
                'tree' => $tree,
                'page' => '',
                'emptyPage' => '',
                'stores' => (object) [],
                'next' => [
                    'state' => Wire::encode($this->browserKeys($nextState, $names)),
                    'errors' => Wire::encode($this->browserErrors($nextErrors, $names)),
                    'ssr' => $nextSsr,
                    'tree' => $nextTree,
                    'changed' => $changed,
                ],
            ];
        }

        $results = Node::run('js/test/support/run-templates.mjs', [
            'bundle' => $bundle,
            'csrf' => csrf_token(),
            'globals' => app(\Nitro\Rendering\Scripts::class)->globals(),
            'cases' => $cases,
        ]);

        foreach ($results as $failure) {
            $generated = self::$generated[$cases[$failure['id']]['generated']];
            $failures[] = "{$generated['case']} · {$generated['context']} · ".preg_replace('/^.*\(/', '(', $failure['name'])
                ."\n--- server\n{$failure['expected']}\n--- browser\n{$failure['actual']}";
        }

        $this->assertSame([], $failures, count($failures).' of '.count($cases).' cases differ');
    }

    public function test_what_nitro_does_not_compile_fails_the_build_with_its_message_at_its_view(): void
    {
        $this->app->make('config')->set('nitro.namespaces.refused', [
            'components' => ['namespace' => self::PHP.'\\Refused', 'path' => self::$root.'/Refused', 'views' => 'conformance::refused'],
        ]);
        $this->app->forgetScopedInstances();
        $bundle = $this->app->make(Bundle::class);
        $bundle->compileAll();
        $failures = [];
        $checked = 0;

        foreach (self::$generated as $generated) {
            if ($generated['refused'] === null) {
                continue;
            }

            $checked++;
            $label = "{$generated['case']} · {$generated['context']}";
            $meta = app(Components::class)->meta($generated['class']);
            [$message, $file] = is_array($generated['refused']) ? $generated['refused'] : [$generated['refused'], null];
            /** In a component, the message is the component's file's; in the view, the view's (or the helper's that holds it). */
            $file ??= $generated['context'] === 'component' ? "inner-{$generated['id']}.blade.php" : Str::kebab(class_basename($generated['class'])).'.blade.php';
            $generated['refused'] = $message;

            try {
                $bundle->assertCompiled([$meta->name]);
                $failures[] = "{$label}: compiles, but Nitro does not render it as Blade does";
            } catch (\Nitro\Compiler\CompileException $e) {
                if (! str_contains($e->getMessage(), $generated['refused'])) {
                    $failures[] = "{$label}: says \"{$e->getMessage()}\", not \"{$generated['refused']}\"";
                } elseif (! str_ends_with(str_replace('\\', '/', (string) $e->sourceFile), $file)) {
                    $failures[] = "{$label}: points at {$e->sourceFile}, not {$file}";
                }
            }
        }

        $this->assertSame([], $failures);
        $this->assertGreaterThan(0, $checked);
    }

    /**
     * The component in a state: its state as the browser receives it, its errors, its view as
     * Laravel's Blade renders it (what both of Nitro's renders must give), and its HTML as the
     * server renders it (the compiled tree: TreeRenderer).
     *
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>, 2: string, 3: string}
     */
    private function rendered(\Nitro\Compiler\ComponentMeta $meta, array $values, array $errors, ?Obfuscator $names): array
    {
        /** @var Component $component */
        $component = $this->app->make($meta->class);
        $component->nitro()->id = 'p';
        $component->nitro()->name = $meta->name;
        $component->nitro()->errors = new MessageBag($errors);

        foreach ($values as $property => $value) {
            $component->{$property} = $value;
        }

        $html = $this->failing(fn () => app(ComponentRenderer::class)->render($component, $meta));
        $blade = $this->failing(fn () => $this->blade($component, $meta, $names));

        return [StateProperties::values($component), $errors, $blade, $html];
    }

    /**
     * The component's view rendered by Laravel's Blade: its properties as variables, its errors as
     * $errors (shared, as Laravel shares the session's, for the Blade components in it), with the
     * browser's names as the server's HTML has them (an obfuscated build).
     */
    private function blade(Component $component, \Nitro\Compiler\ComponentMeta $meta, ?Obfuscator $names): string
    {
        $views = $this->app->make('view');
        $errors = new ViewErrorBag;
        $errors->put('default', $component->nitro()->errors);
        $shared = $views->getShared();
        $views->share('errors', $errors);

        /** Its loops start fresh, as a component's view's do (whatever a view that failed left open). */
        $loops = (fn () => $this->loopsStack)->call($views);
        (fn () => $this->loopsStack = [])->call($views);

        try {
            $html = trim($views->make($meta->view, [...StateProperties::values($component), 'errors' => $errors])->render());
        } finally {
            (fn () => $this->loopsStack = $loops)->call($views);

            if (array_key_exists('errors', $shared)) {
                $views->share('errors', $shared['errors']);
            } else {
                (function (): void {
                    unset($this->shared['errors']);
                })->call($views);
            }
        }

        return $names === null ? $html : app(BrowserNames::class)->obscure($html, [...$meta->browserMethods, ...$meta->serverMethods], array_keys($meta->properties));
    }

    /** What a render gives, or its error. */
    private function failing(\Closure $render): string
    {
        try {
            return $render();
        } catch (\Throwable $e) {
            $origin = $e->getPrevious() ?? $e;

            return 'server error: '.$e->getMessage().' at '.$origin->getFile().':'.$origin->getLine()."\n".implode("\n", array_slice(explode("\n", $origin->getTraceAsString()), 0, 6));
        }
    }

    /** @param  array<string, mixed>  $state */
    private function browserKeys(array $state, ?Obfuscator $names): array
    {
        $keyed = [];

        foreach ($state as $property => $value) {
            $keyed[$names?->property($property) ?? $property] = $value;
        }

        return $keyed;
    }

    /** @param  array<string, list<string>>  $errors */
    private function browserErrors(array $errors, ?Obfuscator $names): array
    {
        $keyed = [];

        foreach ($errors as $path => $messages) {
            $keyed[$names?->path($path) ?? $path] = $messages;
        }

        return $keyed;
    }

    /** @return array<string, mixed> */
    private static function first(): array
    {
        return [
            'name' => "Ada <b>&\"x\"'",
            'count' => 3,
            'price' => 12.5,
            'flag' => true,
            'nothing' => null,
            'items' => [['id' => 1, 'label' => 'One', 'note' => 'n1'], ['id' => 2, 'label' => 'Two & <i>']],
            'map' => ['a' => 1, 'b' => 'two'],
            'empty' => [],
            'html' => '<b>x</b>',
            'tags' => ['x', 'y'],
        ];
    }

    /** @return array<string, mixed> */
    private static function second(): array
    {
        return [
            'name' => 'Grace',
            'count' => 0,
            'price' => 7.25,
            'flag' => false,
            'nothing' => 'now',
            'items' => [['id' => 2, 'label' => 'Two & <i>'], ['id' => 3, 'label' => 'Three', 'note' => 'n3'], ['id' => 1, 'label' => 'One', 'note' => 'n1']],
            'map' => ['b' => 'two', 'c' => 3],
            'empty' => ['z'],
            'html' => '<i>y</i>',
            'tags' => ['y'],
        ];
    }

    private static function write(string $path, string $contents): void
    {
        @mkdir(dirname(self::$root.'/'.$path), 0777, true);
        file_put_contents(self::$root.'/'.$path, $contents);
    }
}
