<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Tests\Fixtures\Components\Store;
use Nitro\Fusion\Tests\Fixtures\Models\Student;
use Nitro\Fusion\Tests\Fixtures\Models\User;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requests made without the page, by hand, to get what the page would never send: methods and
 * properties under other spellings, arguments by name, the wire format bent, model properties
 * swapped, the children of a component asked for, visits with forged headers. Each must be refused
 * or come to nothing; none may change what the server keeps (Store), or answer 500.
 */
class ForgedRequestTest extends TestCase
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

    /** A call with the method, params and updates exactly as given (not encoded): what a hand-made request can send. */
    private function raw(array $snapshot, mixed $call, mixed $updates = []): Response
    {
        $this->app->forgetScopedInstances();

        return $this->update(['snapshot' => $snapshot, 'updates' => $updates, 'call' => $call]);
    }

    private function body(string $json): Response
    {
        $this->app->forgetScopedInstances();

        return $this->request('POST', '/_fusion/update', [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ], $json);
    }

    private function refused(Response $response, string $what, array $codes = [403]): void
    {
        $this->assertContains($response->getStatusCode(), $codes, "{$what} must be refused, not ".$response->getStatusCode().': '.substr((string) $response->getContent(), 0, 300));
    }

    #[DataProvider('frameworks')]
    public function test_a_method_under_another_spelling_or_of_the_framework_is_refused(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->wallet();

        $names = [
            'WITHDRAW', 'Withdraw', ' withdraw', 'withdraw ', "withdraw\0", 'withdraw()', 'withdraw;drain',
            'Nitro\Fusion\Tests\Fixtures\Components\Wallet::withdraw', 'parent::withdraw', 'self::withdraw', 'static::withdraw',
            'boot', 'hydrate', 'dehydrate', 'updated', 'updatedAmount', 'mounted', 'destroyed',
            'head', 'js', 'skipRender', 'getName', 'authorize', '__call', '__get', '__set', '__invoke', '__toString', '__clone',
            'toArray', 'jsonSerialize', 'restore', 'fill', 'clientOnly', 'CLIENTONLY', 'drain', 'preview',
        ];

        foreach ($names as $method) {
            $this->refused($this->raw($snapshot, ['method' => $method, 'params' => [30]]), 'method '.json_encode($method));
        }

        foreach ([42, null, true, ['withdraw'], ['name' => 'withdraw'], 1.5] as $method) {
            $this->refused($this->raw($snapshot, ['method' => $method, 'params' => [30]]), 'method '.json_encode($method));
        }

        foreach (['withdraw', ['withdraw', [30]], 5, true] as $call) {
            $this->refused($this->raw($snapshot, $call), 'call '.json_encode($call));
        }

        $this->assertSame([], Store::$saved, 'nothing ran');
    }

    #[DataProvider('frameworks')]
    public function test_arguments_cannot_be_named_reshaped_or_smuggled(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->wallet();

        $params = [
            ['amount' => 30],                         // named: PHP would take it as a named argument
            ['amount' => 30, 'reason' => 'x'],
            [30, 'reason' => 'x'],
            ['1' => 'x', '0' => 30],                  // positions out of order: not a list
            [['$m' => [[0, 30]]]],                    // an array for an int
            [['$u' => ['ref' => 'x']]],               // a made-up upload reference
            [30, ['$m' => [['a', 1]]]],               // an array for a string
            array_fill(0, 500, 1),
            [PHP_INT_MAX + 1],                        // a float past int range
            ['30'],
            [true],
        ];

        foreach ($params as $list) {
            $this->refused($this->raw($snapshot, ['method' => 'withdraw', 'params' => $list]), 'params '.json_encode($list), [400, 403]);
        }

        $this->assertSame([], Store::$saved, 'nothing ran');

        /** The page's own call still works. */
        $this->assertSame(200, $this->raw($snapshot, ['method' => 'withdraw', 'params' => [30, 'rent']])->getStatusCode());
        $this->assertSame([30, 'rent'], Store::$saved['withdraw']);
    }

    #[DataProvider('frameworks')]
    public function test_a_property_under_another_name_or_of_the_framework_is_refused(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->wallet();

        $names = [
            'Balance', 'BALANCE', 'balance ', ' balance', "balance\0", 'balance.0', 'balance[]', '$balance',
            '$errors', 'errors', 'fusion', 'id', 'name', '__proto__', 'constructor', '0', '',
            'preview', 'withdraw', 'Amount',
        ];

        foreach ($names as $name) {
            $this->refused($this->raw($snapshot, null, [$name => 1]), 'property '.json_encode($name));
        }

        /** A list instead of a map: its keys are positions, which name no property. */
        $this->refused($this->raw($snapshot, null, [1000000]), 'updates as a list');

        /** A key twice in the JSON: the last one counts, and it is still checked. */
        $twice = $this->body('{"snapshot":'.json_encode($snapshot).',"updates":{"amount":1,"amount":"x"},"call":null}');
        $this->refused($twice, 'a key given twice, the second of the wrong type');
        $twiceLocked = $this->body('{"snapshot":'.json_encode($snapshot).',"updates":{"amount":1,"balance":999999},"call":{"method":"withdraw","params":[1]}}');
        $this->refused($twiceLocked, 'a locked property beside an open one');

        $this->assertSame([], Store::$saved, 'nothing ran');
    }

    #[DataProvider('frameworks')]
    public function test_a_model_property_cannot_be_pointed_at_another_record(string $framework): void
    {
        $this->boot($framework);
        Student::create(['name' => 'Grace Hopper', 'email' => 'grace@example.com', 'secret' => 'grace-secret']);
        $snapshot = $this->snapshotOf('/pair/1');

        foreach ([2, '2', ['id' => 2], ['$m' => [['id', 2]]], null, [], ['name' => 'Ada', 'secret' => 'x']] as $value) {
            foreach (['sure', 'maybe', 'mixed'] as $property) {
                $response = $this->raw($snapshot, ['method' => 'ping', 'params' => []], [$property => $value]);
                $this->refused($response, "\${$property} = ".json_encode($value), [400, 403]);
            }
        }
    }

    #[DataProvider('frameworks')]
    public function test_the_wire_format_bent_out_of_shape_is_refused(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshotOf('/students/1');

        $deep = 1;
        for ($i = 0; $i < 80; $i++) {
            $deep = [$deep];
        }

        $bent = [
            'too deep' => [['tags' => $deep], [400]],
            '$m pair with an array key' => [['tags' => ['$m' => [[[1], 'x']]]], [400]],
            '$m pair of three' => [['tags' => ['$m' => [['a', 1, 2]]]], [400]],
            '$m pair of one' => [['tags' => ['$m' => [['a']]]], [400]],
            '$m pair not a list' => [['tags' => ['$m' => [['k' => 'a', 'v' => 1]]]], [400]],
            '$m pair a string' => [['tags' => ['$m' => ['ab']]], [400]],
            'upload reference in an array' => [['tags' => [['$u' => ['ref' => 'made-up']]]], [400, 403]],
            'upload into an int' => [['copies' => ['$u' => 'x']], [400, 403]],
        ];

        foreach ($bent as $what => [$updates, $codes]) {
            $this->refused($this->raw($snapshot, ['method' => 'order', 'params' => []], $updates), $what, $codes);
        }

        /** Keys named like markers, in an array that is not one (a marker is alone in its object): data, kept as it is. */
        foreach ([['$m' => ['a' => 1], 'b' => 2], ['$u' => 'x', '$m' => []]] as $tags) {
            $response = $this->raw($snapshot, null, ['tags' => $tags]);
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $this->assertSame($tags, Wire::fromJson($this->json($response)['snapshot']['state'])['data']['tags']);
        }
    }

    #[DataProvider('frameworks')]
    public function test_the_snapshot_envelope_bent_out_of_shape_is_refused(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshotOf('/embed');

        $envelopes = [
            'state as an array' => ['state' => Wire::fromJson($snapshot['state']), 'checksum' => $snapshot['checksum']],
            'checksum as an int' => ['state' => $snapshot['state'], 'checksum' => 123],
            'checksum as an array' => ['state' => $snapshot['state'], 'checksum' => [$snapshot['checksum']]],
            'checksum upper case' => ['state' => $snapshot['state'], 'checksum' => strtoupper($snapshot['checksum'])],
            'checksum cut short' => ['state' => $snapshot['state'], 'checksum' => substr($snapshot['checksum'], 0, 32)],
            'state with a space' => ['state' => $snapshot['state'].' ', 'checksum' => $snapshot['checksum']],
            'state re-encoded' => ['state' => json_encode(json_decode($snapshot['state'], true)), 'checksum' => $snapshot['checksum']],
            'a list' => [$snapshot['state'], $snapshot['checksum']],
            'a string' => $snapshot['state'],
            'null' => null,
        ];

        foreach ($envelopes as $what => $envelope) {
            $this->app->forgetScopedInstances();
            $response = $this->update(['snapshot' => $envelope, 'call' => ['method' => 'save', 'params' => []]]);
            $this->refused($response, $what, [419]);
        }

        $this->assertArrayNotHasKey('counter', Store::$saved);
    }

    #[DataProvider('frameworks')]
    public function test_asking_for_children_gives_nothing_the_page_would_not_have(string $framework): void
    {
        $this->boot($framework);

        /** A component that places none: none. Something else than true: not asked. */
        $wallet = $this->wallet();
        $panels = $this->snapshotOf('/panels');
        $this->app->forgetScopedInstances();
        $answer = $this->update(['snapshot' => $wallet, 'call' => null, 'children' => true]);
        $this->assertSame(200, $answer->getStatusCode(), (string) $answer->getContent());
        $this->assertSame([], (array) ($this->json($answer)['children'] ?? []));

        $this->app->forgetScopedInstances();
        $answer = $this->update(['snapshot' => $panels, 'call' => null, 'children' => 'yes']);
        $this->assertSame(200, $answer->getStatusCode());

        /** Asked for: the children the page's view places, bound to the page, not to the endpoint. */
        $this->app->forgetScopedInstances();
        $answer = $this->update(['snapshot' => $panels, 'call' => null, 'children' => true]);
        $this->assertSame(200, $answer->getStatusCode(), (string) $answer->getContent());
        $children = $this->json($answer)['children'];
        $this->assertNotEmpty($children);

        foreach ($children as $id => $envelope) {
            $state = Wire::fromJson($envelope['state']);
            $this->assertSame($id, $state['id']);
            $this->assertStringStartsWith(Wire::fromJson($panels['state'])['id'].'.', $id, 'its id follows the page\'s');
            $this->assertSame('/panels', $state['memo']['route']['uri'] ?? null, 'its calls re-check the page\'s middleware, not the endpoint\'s');
        }

        /** Props or names from the state the browser sent that no child can take: refused, not an error. */
        foreach ([['stack' => ['nope']], ['stack' => [['x']]], ['stack' => [5]], ['stack' => ['pages::counter']], ['stack' => ['../x']]] as $updates) {
            $this->app->forgetScopedInstances();
            $this->refused($this->update(['snapshot' => $panels, 'updates' => array_map(Wire::encode(...), $updates), 'call' => null, 'children' => true]), 'children of '.json_encode($updates));
        }

        /** Rules on what the children are made from: a state that fails them makes none. */
        $this->app->forgetScopedInstances();
        $answer = $this->update(['snapshot' => $panels, 'updates' => ['badges' => Wire::encode([['x']])], 'call' => null, 'children' => true]);
        $this->assertSame(200, $answer->getStatusCode(), (string) $answer->getContent());
        $this->assertNull($this->json($answer)['children'], 'none made from a state that failed its rules');

        /** Its signed state works for its own calls, and only for its own component. */
        $stamp = collect($children)->first(fn (array $envelope) => Wire::fromJson($envelope['state'])['name'] === 'stamp');
        $this->assertNotNull($stamp);
        $this->app->forgetScopedInstances();
        $this->assertSame(200, $this->update(['snapshot' => $stamp, 'call' => ['method' => 'stamp', 'params' => []]])->getStatusCode());

        $renamed = Wire::fromJson($stamp['state']);
        $renamed['name'] = 'wallet';
        $this->app->forgetScopedInstances();
        $this->refused($this->update(['snapshot' => ['state' => Wire::json($renamed), 'checksum' => $stamp['checksum']], 'call' => ['method' => 'withdraw', 'params' => [1]]]), 'a child\'s state renamed', [419]);
    }

    #[DataProvider('frameworks')]
    public function test_forged_visit_headers_change_nothing_they_should_not(string $framework): void
    {
        $this->boot($framework);

        $visit = fn (string $uri, array $headers) => $this->request('GET', $uri, ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_FUSION_NAVIGATE' => '1', ...$headers]);

        /** An id that is not one: the page gets its own. */
        foreach (['../../etc', 'f'.str_repeat('a', 30), "fabc\ndefghi", 'FABCDEFGHIJ', ''] as $id) {
            $this->app->forgetScopedInstances();
            $response = $visit('/counter', ['HTTP_X_FUSION_ID' => $id]);
            $this->assertSame(200, $response->getStatusCode(), $id);
            $this->assertMatchesRegularExpression('/^f[a-z0-9]{10}$/', Wire::fromJson($this->json($response)['snapshot']['state'])['id'], json_encode($id));
        }

        /** A partial reload naming what is not a public property: never sent; hidden attributes stay hidden. */
        $this->app->forgetScopedInstances();
        $response = $visit('/students/1', ['HTTP_X_FUSION_PARTIAL' => 'fabcdefghij', 'HTTP_X_FUSION_ONLY' => 'student,secret,password,__construct,fusion,errors']);
        $this->assertContains($response->getStatusCode(), [200, 403], (string) $response->getContent());

        if ($response->getStatusCode() === 200) {
            $body = (string) $response->getContent();
            $this->assertStringNotContainsString('hidden', $body, 'the hidden attribute\'s value');
            $partial = Wire::fromJson($this->json($response)['partial']['state'] ?? '{"data":{}}');
            $this->assertSame([], array_diff(array_keys($partial['data'] ?? []), ['student', 'note', 'copies', 'tags']), 'public properties only');
        }

        /** Another build's version: a full load, never this build's data. */
        $this->app->forgetScopedInstances();
        $this->assertSame(409, $visit('/counter', ['HTTP_X_FUSION_VERSION' => 'not-this-one'])->getStatusCode());
    }

    /** An enum property (Showcase's OrderStatus $status): only the value of one of its cases, as its backing type. */
    #[DataProvider('frameworks')]
    public function test_an_enum_property_takes_only_its_cases_values(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshotOf('/showcase');

        $answer = $this->raw($snapshot, ['method' => 'confirm', 'params' => []], ['status' => 'paid']);
        $this->assertSame(200, $answer->getStatusCode(), (string) $answer->getContent());
        $this->assertStringContainsString('server saw Paid (paid)', (string) $answer->getContent(), 'the server has the case');

        foreach (['lost', 'PAID', 'Paid', '', null, 1, true, ['paid'], ['$m' => [['value', 'paid']]], ['$u' => 'x']] as $value) {
            $this->refused($this->raw($snapshot, ['method' => 'confirm', 'params' => []], ['status' => $value]), 'status '.json_encode($value), [400, 403]);
        }
    }

    #[DataProvider('frameworks')]
    public function test_the_endpoint_takes_post_only(string $framework): void
    {
        $this->boot($framework);

        foreach (['GET', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $this->app->forgetScopedInstances();
            $this->assertContains($this->request($method, '/_fusion/update', ['HTTP_ACCEPT' => 'application/json'])->getStatusCode(), [404, 405], $method);
        }
    }
}
