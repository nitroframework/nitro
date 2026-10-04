<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Tests\Fixtures\Components\Store;
use Nitro\Fusion\Tests\Fixtures\Models\Student;
use Nitro\Fusion\Tests\Fixtures\Models\User;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST /_fusion/update: #[Server] calls, and everything the endpoint must refuse.
 */
class ServerCallTest extends TestCase
{
    private function snapshotOf(string $uri, int $index = 0): array
    {
        $response = $this->get($uri);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->snapshots((string) $response->getContent())[$index]['envelope'];
    }

    private function wallet(): array
    {
        $user = User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x']);
        $this->app->instance('request', \Illuminate\Http\Request::create('/'));
        $this->app->make('auth')->guard('web')->setUser($user);

        return $this->snapshotOf('/secure');
    }

    private function call(array $snapshot, ?string $method, array $params = [], array $updates = []): \Symfony\Component\HttpFoundation\Response
    {
        return $this->update([
            'snapshot' => $snapshot,
            'updates' => array_map(Wire::encode(...), $updates),
            'call' => $method === null ? null : ['method' => $method, 'params' => array_map(Wire::encode(...), $params)],
        ]);
    }

    #[DataProvider('frameworks')]
    public function test_a_server_method_runs_with_the_browser_state_and_returns_the_new_state(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshotOf('/embed');

        $response = $this->call($snapshot, 'save', [], ['count' => 5, 'history' => [2, 5]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);

        $this->assertSame(5, Store::$saved['counter']);
        $this->assertSame(5, Wire::decode($body['effects']['returns']));
        $this->assertSame([['event' => 'counter-saved', 'params' => [5]]], $body['effects']['dispatches']);

        $state = Wire::fromJson($body['snapshot']['state']);
        $this->assertSame(['count' => 5, 'step' => 2, 'history' => [2, 5]], $state['data']);

        /** The new snapshot is signed: it can be used for the next call. */
        $again = $this->call($body['snapshot'], 'save');
        $this->assertSame(200, $again->getStatusCode());
        $this->assertSame(5, Store::$saved['counter']);
    }

    /**
     * Livewire's hooks: boot() on every request, mount() when created, hydrate() on a #[Server]
     * call once the state is back, dehydrate() before the state goes out. $this->js() from the
     * server comes back as an effect.
     */
    #[DataProvider('frameworks')]
    public function test_the_server_lifecycle_hooks_run_in_order(string $framework): void
    {
        $this->boot($framework);

        $snapshot = $this->snapshotOf('/journal');
        $this->assertSame(['boot', 'mount', 'dehydrate'], Store::$log);

        Store::$log = [];
        $response = $this->call($snapshot, 'save', [], ['note' => 'second']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(['boot', 'hydrate', 'dehydrate'], Store::$log);

        $body = $this->json($response);
        $this->assertSame(['window.savedJournal = $fusion.note + " / " + $refs.status.textContent + " / " + $el.tagName'], $body['effects']['js']);
        $this->assertSame(['second'], Wire::fromJson($body['snapshot']['state'])['data']['saved']);
    }

    #[DataProvider('frameworks')]
    public function test_a_changed_or_forged_snapshot_is_refused(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshotOf('/embed');

        $state = Wire::fromJson($snapshot['state']);
        $state['data']['count'] = 999;
        $forged = ['state' => Wire::json($state), 'checksum' => $snapshot['checksum']];
        $this->assertSame(419, $this->call($forged, 'save')->getStatusCode());

        $renamed = Wire::fromJson($snapshot['state']);
        $renamed['name'] = 'wallet';
        $this->assertSame(419, $this->call(['state' => Wire::json($renamed), 'checksum' => $snapshot['checksum']], 'withdraw', [1])->getStatusCode());

        $this->assertSame(419, $this->call(['state' => $snapshot['state'], 'checksum' => str_repeat('0', 64)], 'save')->getStatusCode());
        $this->assertSame(419, $this->call(['state' => $snapshot['state']], 'save')->getStatusCode());
        $this->assertSame(419, $this->update(['call' => ['method' => 'save', 'params' => []]])->getStatusCode());
        $this->assertArrayNotHasKey('counter', Store::$saved);
    }

    #[DataProvider('frameworks')]
    public function test_only_server_methods_can_be_called(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->wallet();

        foreach (['clientOnly', 'drain', 'preview', 'mount', 'render', 'callAction', 'validate', 'dispatch', 'reset',
            'redirect', 'fusion', 'getId', '__construct', '__destruct', 'secret', 'nonexistent', ''] as $method) {
            $response = $this->call($snapshot, $method);
            $this->assertSame(403, $response->getStatusCode(), "{$method}() must be refused: ".$response->getContent());
        }

        $this->assertSame(403, $this->update(['snapshot' => $snapshot, 'call' => ['method' => ['withdraw'], 'params' => []]])->getStatusCode());
        $this->assertSame(403, $this->update(['snapshot' => $snapshot, 'call' => ['method' => 'withdraw', 'params' => 'x']])->getStatusCode());
        $this->assertSame([], Store::$saved);
    }

    #[DataProvider('frameworks')]
    public function test_only_open_properties_can_be_changed_and_only_with_their_type(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->wallet();

        $refused = [
            ['balance' => 1000000],   // #[Locked]
            ['nope' => 1],            // not a property
            ['amount' => '5'],        // int property, string value
            ['amount' => 1.5],
            ['amount' => null],       // not nullable
            ['amount' => [1]],
            ['label' => 5],           // string property, int value
            ['ratio' => 'x'],
        ];

        foreach ($refused as $updates) {
            $response = $this->call($snapshot, null, [], $updates);
            $this->assertSame(403, $response->getStatusCode(), json_encode($updates).' must be refused');
        }

        /** Accepted: a value of the declared type (an int for a float property, null for a nullable one). */
        $response = $this->call($snapshot, null, [], ['amount' => 7, 'ratio' => 2, 'label' => 'x']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = Wire::fromJson($this->json($response)['snapshot']['state'])['data'];
        $this->assertSame([100, 7, 2.0, 'x'], [$data['balance'], $data['amount'], $data['ratio'], $data['label']]);

        $this->assertSame(200, $this->call($snapshot, null, [], ['ratio' => null])->getStatusCode());
    }

    #[DataProvider('frameworks')]
    public function test_arguments_must_match_the_parameters(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->wallet();

        foreach ([['50'], [50.5], [null], [], [50, 'why', 'extra'], [50, 5], [[50]]] as $params) {
            $response = $this->call($snapshot, 'withdraw', $params);
            $this->assertSame(403, $response->getStatusCode(), json_encode($params).' must be refused');
        }

        $response = $this->call($snapshot, 'withdraw', [30, 'rent']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame([30, 'rent'], Store::$saved['withdraw']);
        $this->assertSame(70, Wire::decode($this->json($response)['effects']['returns']));

        $this->assertSame(200, $this->call($snapshot, 'withdraw', [10])->getStatusCode());
        $this->assertSame([10, 'none'], Store::$saved['withdraw']);
    }

    #[DataProvider('frameworks')]
    public function test_validation_errors_come_back_in_the_state(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->wallet();

        $response = $this->call($snapshot, 'withdraw', [500]);
        $this->assertSame(200, $response->getStatusCode());
        $state = Wire::fromJson($this->json($response)['snapshot']['state']);

        $this->assertSame(['amount' => ['Not enough money.']], $state['memo']['errors']);
        $this->assertSame(100, $state['data']['balance']);

        /** The next successful call clears them. */
        $next = $this->call($this->json($response)['snapshot'], 'withdraw', [5]);
        $this->assertArrayNotHasKey('errors', Wire::fromJson($this->json($next)['snapshot']['state'])['memo']);

        /** $this->validate() in a component. */
        $list = $this->snapshotOf('/embed', 1);
        $response = $this->call($list, 'persist', [], ['todos' => [['id' => 1, 'title' => str_repeat('x', 30), 'done' => false]]]);
        $errors = Wire::fromJson($this->json($response)['snapshot']['state'])['memo']['errors'];
        $this->assertArrayHasKey('todos.0.title', $errors);
        $this->assertArrayNotHasKey('todos', Store::$saved);
    }

    #[DataProvider('frameworks')]
    public function test_models_are_read_again_on_the_server(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshotOf('/students/1');

        /** A browser cannot swap the model: the property is locked, and the reference is signed. */
        $this->assertSame(403, $this->call($snapshot, null, [], ['student' => ['id' => 2, 'name' => 'Eve']])->getStatusCode());

        $response = $this->call($snapshot, 'rename', ['Renamed'], ['note' => 'hello']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('Renamed', Student::find(1)->name);

        $state = Wire::fromJson($this->json($response)['snapshot']['state']);
        $this->assertSame('Renamed', $state['data']['student']['name']);
        $this->assertSame('hello', $state['data']['note']);
        $this->assertArrayNotHasKey('secret', $state['data']['student']);

        /** A deleted model: never a stale copy. $student is nullable, so it is null (ModelStateTest: a non-nullable one answers 404). */
        Student::find(1)->delete();
        $gone = $this->call($this->json($response)['snapshot'], null);
        $this->assertSame(200, $gone->getStatusCode(), (string) $gone->getContent());
        $this->assertNull(Wire::fromJson($this->json($gone)['snapshot']['state'])['data']['student']);
    }

    #[DataProvider('frameworks')]
    public function test_the_page_middleware_is_checked_again(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->wallet();

        $this->assertSame(200, $this->call($snapshot, 'withdraw', [1])->getStatusCode());

        /** After logout, the snapshot from the page is useless. */
        $this->app->make('auth')->guard('web')->logout();
        $this->app->make('auth')->forgetGuards();

        $response = $this->call($snapshot, 'withdraw', [1]);
        $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    }

    #[DataProvider('frameworks')]
    public function test_malformed_and_oversized_updates_are_refused(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshotOf('/embed');

        $bad = fn (string $body) => $this->request('POST', '/_fusion/update', ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)->getStatusCode();

        $this->assertSame(400, $bad('{not json'));
        $this->assertSame(400, $bad('"a string"'));
        $this->assertSame(413, $bad(json_encode(['snapshot' => $snapshot, 'pad' => str_repeat('x', 70 * 1024)])));
        $this->assertSame(400, $this->update(['snapshot' => $snapshot, 'updates' => 'x'])->getStatusCode());
        $this->assertSame(400, $this->update(['snapshot' => $snapshot, 'updates' => ['todos' => ['$m' => [['k']]]]])->getStatusCode());
    }

    #[DataProvider('frameworks')]
    public function test_the_endpoint_is_protected_against_cross_site_requests(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshotOf('/embed');

        /** Outside of tests the web group checks CSRF: switch the fixture out of its testing env. */
        $this->app['env'] = 'local';

        $body = json_encode(['snapshot' => $snapshot, 'call' => ['method' => 'save', 'params' => []]]);
        $json = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        $this->assertSame(419, $this->request('POST', '/_fusion/update', $json, $body)->getStatusCode());
        $this->assertSame(419, $this->request('POST', '/_fusion/update', [...$json, 'HTTP_SEC_FETCH_SITE' => 'cross-site'], $body)->getStatusCode());
        $this->assertSame(200, $this->request('POST', '/_fusion/update', [...$json, 'HTTP_SEC_FETCH_SITE' => 'same-origin'], $body)->getStatusCode());

        $token = $this->app->make('session')->driver()->token();
        $this->assertSame(200, $this->request('POST', '/_fusion/update', [...$json, 'HTTP_X_CSRF_TOKEN' => $token], $body)->getStatusCode());
    }
}
