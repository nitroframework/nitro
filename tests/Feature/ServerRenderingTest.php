<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Tests\TestCase;
use Nitro\Fusion\View\ServerRendering\NodeRenderer;
use Nitro\Fusion\View\ServerRendering\NullRenderer;
use Nitro\Fusion\View\ServerRendering\ServerRenderer;
use Nitro\Fusion\View\ServerRendering\ViewState;
use Nitro\Fusion\View\ViewEngines;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;

/**
 * Vue, React and Solid views in the first HTML (fusion.ssr): what the render service is sent,
 * the element the browser hydrates, and the page going out as before when it cannot render.
 * The browser tests' "server rendering" scenario renders with the real service.
 */
class ServerRenderingTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_off_the_element_is_empty_as_before(string $framework): void
    {
        $this->boot($framework);

        $this->assertInstanceOf(NullRenderer::class, $this->app->make(ServerRenderer::class));
        $this->assertMatchesRegularExpression('/<div class="vue"><div fusion:id="[^"]+" fusion:name="vue-parity"><\/div><\/div>/', (string) $this->get('/parity')->getContent());
    }

    #[DataProvider('frameworks')]
    public function test_the_view_renders_from_the_state_by_the_php_names_and_the_browser_is_told(string $framework): void
    {
        $this->boot($framework);
        $renderer = $this->recording(fn (ViewState $state) => "<p>{$state->engine}: {$state->properties['count']}</p>");

        $html = (string) $this->get('/parity')->getContent();

        $this->assertSame(['vue', 'react', 'solid', 'svelte'], array_map(fn (ViewState $state) => $state->engine, $renderer->states));
        $this->assertMatchesRegularExpression('/<div class="react"><div fusion:id="[^"]+" fusion:name="react-parity" fusion:rendered><p>react: 1<\/p><\/div><\/div>/', $html);
        $this->assertMatchesRegularExpression('/<div class="blade"><div [^>]*fusion:name="blade-parity"[^>]*>\s*<span class="count">1<\/span>/', $html, 'Blade renders itself, as always');

        $vue = json_decode((string) json_encode($renderer->states[0]), true);
        $this->assertMatchesRegularExpression('/^f[a-z0-9]{10}\.c1$/', $vue['id'], 'the component\'s id on the page');
        unset($vue['id']);
        $this->assertSame([
            'engine' => 'vue',
            'view' => 'vue-parity',
            'properties' => ['count' => 1, 'title' => '', 'form' => ['$m' => [['email', ''], ['tags', ['a']]]], 'said' => '', 'photo' => null, 'photos' => []],
            'computed' => ['doubled' => 2],
            /** As the browser's handle lists them: #[Computed] ones too (the adapters read those as values). */
            'methods' => ['doubled', 'increment', 'addTag'],
            'server' => ['save'],
            'errors' => [],
        ], $vue, 'the wire format the browser\'s state comes in');
    }

    #[DataProvider('frameworks')]
    public function test_a_view_it_cannot_render_is_left_to_the_browser(string $framework): void
    {
        $this->boot($framework);
        $this->recording(fn (ViewState $state) => $state->engine === 'react' ? null : '<p>here</p>');

        $html = (string) $this->get('/parity')->getContent();

        $this->assertMatchesRegularExpression('/<div class="react"><div fusion:id="[^"]+" fusion:name="react-parity"><\/div>/', $html);
        $this->assertMatchesRegularExpression('/<div class="solid"><div fusion:id=[^>]+ fusion:rendered><p>here<\/p>/', $html, 'the others are rendered');
    }

    public function test_a_service_that_is_not_running_is_asked_once_and_logged(): void
    {
        $log = new class extends AbstractLogger
        {
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = "{$level}: {$message}";
            }
        };
        /** A port nothing listens on: refused at once. */
        $renderer = new NodeRenderer('http://127.0.0.1:1', 1, $log);
        $state = new ViewState('f0000000000', 'vue', 'card', [], [], [], [], []);

        $started = microtime(true);
        $this->assertNull($renderer->render($state));
        $this->assertNull($renderer->render($state));

        $this->assertLessThan(1.5, microtime(true) - $started, 'not held up by the timeout');
        $this->assertSame(['warning: Fusion server rendering: no render service at http://127.0.0.1:1 (php artisan fusion:ssr); the browser renders this request\'s views.'], $log->lines, 'once: the second view is not tried');
    }

    #[DataProvider('frameworks')]
    public function test_on_in_the_config_it_is_the_render_service(string $framework): void
    {
        $this->boot($framework);
        $this->app['config']->set('fusion.ssr.enabled', true);
        $this->app->forgetScopedInstances();

        $this->assertInstanceOf(NodeRenderer::class, $this->app->make(ServerRenderer::class));
        $this->assertSame(
            [$this->app->make(ServerRenderer::class)],
            array_unique(array_map(fn (string $name) => (fn () => $this->server)->call($this->app->make(ViewEngines::class)->named($name)), ['vue', 'react', 'solid', 'plain']), SORT_REGULAR),
            'every JavaScript engine, adapter or not, uses it',
        );
    }

    #[DataProvider('frameworks')]
    public function test_the_service_will_not_start_without_its_bundle(string $framework): void
    {
        $this->boot($framework);

        $this->assertSame(1, $this->console()->call('fusion:ssr', ['--bundle' => self::APP.'/bootstrap/ssr/missing.js']));
        $this->assertStringContainsString('npx vite build --ssr resources/js/ssr.js', $this->console()->output());
    }

    /**
     * A renderer that answers with $answer and keeps what it was sent.
     *
     * @param  \Closure(ViewState): ?string  $answer
     */
    private function recording(\Closure $answer): object
    {
        $renderer = new class($answer) implements ServerRenderer
        {
            /** @var list<ViewState> */
            public array $states = [];

            public function __construct(private readonly \Closure $answer)
            {
            }

            public function render(ViewState $state): ?string
            {
                $this->states[] = $state;

                return ($this->answer)($state);
            }
        };

        $this->app->instance(ServerRenderer::class, $renderer);
        $this->app->forgetScopedInstances();
        $this->app->instance(ServerRenderer::class, $renderer);

        return $renderer;
    }
}
