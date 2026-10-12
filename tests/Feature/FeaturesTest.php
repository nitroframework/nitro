<?php

namespace Nitro\Tests\Feature;

use Nitro\Support\Wire;
use Nitro\Tests\Fixtures\Components\Store;
use Nitro\Tests\Fixtures\Features\Around;
use Nitro\Tests\Fixtures\Features\Blocker;
use Nitro\Tests\Fixtures\Features\Catcher;
use Nitro\Tests\Fixtures\Features\Recorder;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Features (nitro.features): the application's hooks around every component on the server, each
 * after the component's own and after Nitro's own features (#[Url], #[Validate]). The journal
 * logs its own hooks; Recorder logs the features' beside them.
 */
class FeaturesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Store::$log = [];
    }

    private function features(array $classes): void
    {
        $this->app->make('config')->set('nitro.features', $classes);
        $this->app->forgetScopedInstances();
    }

    private function save(array $updates): Response
    {
        $snapshot = $this->snapshots((string) $this->get('/journal')->getContent())[0]['envelope'];
        $this->app->forgetScopedInstances();
        Store::$log = [];

        return $this->update([
            'snapshot' => $snapshot,
            'updates' => array_map(Wire::encode(...), $updates),
            'call' => ['method' => 'save', 'params' => []],
        ]);
    }

    #[DataProvider('frameworks')]
    public function test_a_page_runs_the_hooks_of_creating_a_component(string $framework): void
    {
        $this->boot($framework);
        $this->features([Recorder::class]);

        $this->assertSame(200, $this->get('/journal')->getStatusCode());
        $this->assertSame([
            'boot', 'feature boot Journal GET',
            'feature mounting Journal GET', 'mount',
            'dehydrate', 'feature dehydrate Journal GET',
        ], Store::$log);
    }

    #[DataProvider('frameworks')]
    public function test_a_server_call_runs_the_hooks_around_the_method(string $framework): void
    {
        $this->boot($framework);
        $this->features([Recorder::class]);

        $response = $this->save(['note' => 'second entry']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame([
            'boot', 'feature boot Journal POST',
            'hydrate', 'feature hydrate Journal POST',
            'feature updated note Journal POST',
            'feature calling save() Journal POST',
            'feature called save -> null Journal POST',
            'dehydrate', 'feature dehydrate Journal POST',
        ], Store::$log);
    }

    #[DataProvider('frameworks')]
    public function test_a_feature_can_keep_the_method_from_running(string $framework): void
    {
        $this->boot($framework);
        $this->features([Blocker::class, Recorder::class]);

        $response = $this->save(['note' => 'blocked']);
        $state = Wire::fromJson($this->json($response)['snapshot']['state']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $state['data']['saved'], 'save() did not run');
        $this->assertSame(['This note is blocked.'], $state['memo']['errors']['note'], 'its error reaches the browser');
        $this->assertContains('feature updated note (invalid: note) Journal POST', Store::$log, 'the features after it hear it all the same, and see the error');
        $this->assertNotContains('feature calling save() Journal POST', Store::$log);
    }

    /** Nitro's own run first: #[Validate]'s failure is there for the application's to see (StudentPage's copies: min 1). */
    #[DataProvider('frameworks')]
    public function test_nitros_own_features_run_first(string $framework): void
    {
        $this->boot($framework);
        $this->features([Recorder::class]);

        $snapshot = $this->snapshots((string) $this->get('/students/1')->getContent())[0]['envelope'];
        $this->app->forgetScopedInstances();
        Store::$log = [];
        $this->update(['snapshot' => $snapshot, 'updates' => ['copies' => -5], 'call' => ['method' => 'order', 'params' => []]]);

        $this->assertContains('feature updated copies (invalid: copies) StudentPage POST', Store::$log);
        $this->assertNotContains('ordered -5', Store::$log, 'and the method does not run');
    }

    /** A store is a component: made for the page's components, it runs them too. */
    #[DataProvider('frameworks')]
    public function test_stores_run_them_too(string $framework): void
    {
        $this->boot($framework);
        $this->features([Recorder::class]);

        $this->assertSame(200, $this->get('/shop')->getStatusCode());
        $this->assertContains('feature boot Cart GET', Store::$log);
        $this->assertContains('feature mounting Cart GET', Store::$log);
        $this->assertContains('feature dehydrate Cart GET', Store::$log);
    }

    /** call(): around the method, the first listed outermost; what it returns is the answer. */
    #[DataProvider('frameworks')]
    public function test_a_feature_runs_around_the_method(string $framework): void
    {
        $this->boot($framework);
        $this->features([Recorder::class, Around::class]);

        $response = $this->save(['note' => 'second entry']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(['feature calling save() Journal POST', 'around: before save', 'around: after save', 'feature called save -> null Journal POST'], array_values(array_filter(Store::$log, fn (string $line) => str_contains($line, 'save'))));
        $this->assertContains('second entry', $this->state($response)['data']['saved'], 'the method ran');
    }

    #[DataProvider('frameworks')]
    public function test_a_feature_can_answer_without_the_method(string $framework): void
    {
        $this->boot($framework);
        $this->features([Recorder::class, Around::class]);

        $response = $this->save(['note' => 'draft']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertNotContains('draft', $this->state($response)['data']['saved'], 'the method did not run');
        $this->assertContains('feature called save -> "kept as a draft" Journal POST', Store::$log, 'called() hears the answer');
    }

    /** exception(): after the component's own, for a method that threw; the first feature to handle it ends it. */
    #[DataProvider('frameworks')]
    public function test_a_feature_can_handle_what_a_method_throws(string $framework): void
    {
        $this->boot($framework);
        $flaky = fn () => array_values(array_filter($this->snapshots((string) $this->get('/fallbacks')->getContent()), fn (array $s) => $s['state']['name'] === 'flaky'))[0]['envelope'];

        $this->assertSame(500, $this->update(['snapshot' => $flaky(), 'call' => ['method' => 'save', 'params' => []]])->getStatusCode(), 'without one: thrown, as before');

        $this->features([Catcher::class]);
        Store::$log = [];
        $response = $this->update(['snapshot' => $flaky(), 'call' => ['method' => 'save', 'params' => []]]);

        $this->assertSame(200, $response->getStatusCode(), 'handled: the call answers with the state as it is');
        $this->assertSame(['caught Flaky::save: The flaky service is down.'], Store::$log);
    }

    #[DataProvider('frameworks')]
    public function test_the_components_own_exception_comes_first(string $framework): void
    {
        $this->boot($framework);
        $this->features([Catcher::class]);
        $snapshot = $this->snapshots((string) $this->get('/lifecycle')->getContent())[0]['envelope'];
        Store::$log = [];

        $response = $this->update(['snapshot' => $snapshot, 'updates' => [], 'call' => ['method' => 'explode', 'params' => []]]);

        $this->assertSame('handled: it broke', $this->state($response)['data']['failure']);
        $this->assertSame([], Store::$log, 'handled by the component: the features do not hear it');
    }

    private function state(Response $response): array
    {
        return Wire::fromJson($this->json($response)['snapshot']['state']);
    }

    #[DataProvider('frameworks')]
    public function test_a_class_that_is_not_a_feature_is_refused(string $framework): void
    {
        $this->boot($framework);
        $this->features([\stdClass::class]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nitro.features: stdClass is not a feature (a class extending Nitro\Feature).');
        $this->app->make(\Nitro\Lifecycle\Features::class)->boot(new \Nitro\Tests\Fixtures\Components\Journal);
    }
}
