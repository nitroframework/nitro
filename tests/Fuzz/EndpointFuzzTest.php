<?php

namespace Nitro\Tests\Fuzz;

use Nitro\Support\Wire;
use Nitro\Tests\Fixtures\Components\Store;
use Nitro\Tests\Fixtures\Models\Student;
use Nitro\Tests\Fixtures\Models\User;
use Nitro\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Robustness fuzzing of the server's endpoints: real requests (the #[Server] calls the fixture
 * pages make, and nitro:navigate visits with their headers) mutated at random, in their JSON and
 * in their bytes, and sent. Whatever comes in:
 *
 *   - the answer is never a server error (5xx): bad input is refused (4xx) or handled;
 *   - a refused call changes nothing the server keeps (Store, the fixtures' record of what ran);
 *   - an accepted call answers JSON with a signed snapshot;
 *   - a check runs nothing of its component, accepted or not, and answers its messages only.
 *
 * Off unless asked for:   FUZZ_ENDPOINT=500 FUZZ_SEED=1 phpunit tests/Fuzz/EndpointFuzzTest.php
 * A failure names its seed: FUZZ_SEED=N FUZZ_ENDPOINT=1 makes it again.
 */
class EndpointFuzzTest extends TestCase
{
    public function test_mutated_requests_are_refused_or_handled_never_an_error(): void
    {
        $count = (int) getenv('FUZZ_ENDPOINT');

        if ($count < 1) {
            $this->markTestSkipped('Set FUZZ_ENDPOINT=N to send N mutated requests.');
        }

        $seed = (int) (getenv('FUZZ_SEED') ?: random_int(1, 1_000_000));
        $this->boot('laravel');
        $bases = $this->bases();
        $failures = [];
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $caseSeed = $seed + $i;
            $mutator = new Mutator($caseSeed);
            [$label, $base] = $mutator->pick($bases);
            Store::$saved = [];
            Store::$log = [];

            /** An accepted remove() deletes the student: back, so the next requests meet the page as it was. */
            if (! Student::query()->whereKey(1)->exists()) {
                Student::create(['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'secret' => 'hidden']);
            }

            $isCheck = false;

            if ($base['kind'] === 'visit') {
                $headers = $base['headers'];
                $header = $mutator->pick(array_keys($headers));
                $headers[$header] = is_string($value = $mutator->value($headers[$header])) ? $value : json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
                $what = "{$label} with {$header}: ".substr((string) $headers[$header], 0, 120);
                $response = $this->send(fn () => $this->request('GET', $base['uri'], $headers));
            } else {
                $payload = $base['payload'];

                /** Mostly past the signature (a bent snapshot stops at 419): the updates, the call, its arguments; a check's paths. */
                for ($n = mt_rand(1, 3); $n > 0; $n--) {
                    $part = $mutator->pick($base['kind'] === 'check'
                        ? ['updates', 'updates', 'check', 'check', 'check', 'partials', 'snapshot', '']
                        : ['updates', 'updates', 'updates', 'call', 'call', 'call', 'children', 'partials', 'snapshot', '']);
                    $part === '' || ! is_array($payload)
                        ? $payload = $mutator->value($payload)
                        : $payload[$part] = $mutator->value($payload[$part] ?? null);
                }

                $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

                /** Sometimes the bytes too: the JSON itself cut or spliced. */
                if ($mutator->chance(15)) {
                    $json = $mutator->text((string) $json, ['"', '{', '}', '[', ']', ',', ':', '\\', 'null', '1e999', '"$m"'], 1);
                }

                $what = "{$label}: ".substr((string) $json, 0, 300);
                /** A check is what was sent with a check in it: mutated out of it, it is a call. */
                $sent = json_decode((string) $json, true);
                $isCheck = is_array($sent) && array_key_exists('check', $sent);
                $response = $this->send(fn () => $this->request('POST', '/_nitro/update', [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_SEC_FETCH_SITE' => 'same-origin',
                ], (string) $json));
            }

            if (is_string($response)) {
                $failures[] = "seed {$caseSeed}, {$what}\n  threw {$response}";

                continue;
            }

            $status = $response->getStatusCode();
            $codes[$status] = ($codes[$status] ?? 0) + 1;

            if ($status >= 500) {
                $failures[] = "seed {$caseSeed}, {$what}\n  answered {$status}: ".substr((string) $response->getContent(), 0, getenv('FUZZ_VERBOSE') ? 100000 : 400);
            } elseif ($status >= 400 && $base['kind'] === 'call' && Store::$saved !== []) {
                $failures[] = "seed {$caseSeed}, {$what}\n  refused ({$status}) but ran: ".json_encode(Store::$saved);
            } elseif ($status === 200 && $base['kind'] === 'call' && ! is_array($this->json($response)['snapshot'] ?? null)) {
                $failures[] = "seed {$caseSeed}, {$what}\n  accepted without a snapshot: ".substr((string) $response->getContent(), 0, 300);
            } elseif ($isCheck && (Store::$log !== [] || Store::$saved !== [])) {
                $failures[] = "seed {$caseSeed}, {$what}\n  a check ran its component ({$status}): ".json_encode([Store::$log, Store::$saved]);
            } elseif ($status === 200 && $isCheck && array_keys($this->json($response) ?? []) !== ['errors']) {
                $failures[] = "seed {$caseSeed}, {$what}\n  a check answered more than messages: ".substr((string) $response->getContent(), 0, 300);
            }
        }

        ksort($codes);
        fwrite(STDERR, sprintf("\n  Endpoint fuzz: seeds %d to %d, %d requests, answers %s, %d failures\n", $seed, $seed + $count - 1, $count, json_encode($codes), count($failures)));

        $this->assertSame([], array_slice($failures, 0, 10), implode("\n\n", array_slice($failures, 0, 10)));
    }

    /** A request, its failure to answer as a string (an exception the kernel let through). */
    private function send(callable $request): Response|string
    {
        $this->app->forgetScopedInstances();

        try {
            return $request();
        } catch (\Throwable $e) {
            return get_class($e).': '.$e->getMessage();
        }
    }

    /**
     * The requests the fixture pages make, to mutate: #[Server] calls with their snapshot, updates
     * and arguments (signed in this session, for the logged-in user), and visits with their headers.
     *
     * @return list<array{string, array}>
     */
    private function bases(): array
    {
        $user = User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x']);
        $this->app->instance('request', \Illuminate\Http\Request::create('/'));
        $this->app->make('auth')->guard('web')->setUser($user);

        $snapshot = function (string $uri, string $name) {
            $this->app->forgetScopedInstances();
            $html = (string) $this->get($uri)->getContent();

            foreach ($this->snapshots($html) as $found) {
                if ($found['state']['name'] === $name) {
                    return $found['envelope'];
                }
            }

            $this->fail("no {$name} on {$uri}");
        };

        $call = fn (array $envelope, ?string $method, array $params = [], array $updates = [], array $extra = []) => ['kind' => 'call', 'payload' => [
            'snapshot' => $envelope,
            'updates' => array_map(Wire::encode(...), $updates),
            'call' => $method === null ? null : ['method' => $method, 'params' => array_map(Wire::encode(...), $params)],
        ] + $extra];

        $wallet = $snapshot('/secure', 'wallet');
        $counter = $snapshot('/embed', 'counter');
        $student = $snapshot('/students/1', 'pages::student-page');
        $panels = $snapshot('/panels', 'pages::panels');
        $shelf = $snapshot('/shop', 'shop-shelf');
        $signUp = $snapshot('/sign-up', 'pages::sign-up');
        $check = fn (array $envelope, array $paths, array $updates) => ['kind' => 'check', 'payload' => [
            'snapshot' => $envelope,
            'updates' => array_map(Wire::encode(...), $updates),
            'check' => $paths,
        ]];
        $this->app->forgetScopedInstances();
        preg_match('#data-nitro-globals>(.*?)</script>#s', (string) $this->get('/shop')->getContent(), $globals);
        $cart = json_decode($globals[1], true)['stores']['stores::cart'];
        $visit = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_NITRO_NAVIGATE' => '1', 'HTTP_X_NITRO_VERSION' => '', 'HTTP_X_NITRO_ID' => 'fabcdefghij'];

        return [
            ['wallet withdraw', $call($wallet, 'withdraw', [30, 'rent'], ['amount' => 5, 'label' => 'x'])],
            ['wallet refresh', $call($wallet, null, [], ['ratio' => 1.5])],
            ['counter save', $call($counter, 'save', [], ['count' => 3, 'history' => [1, 2, 3]])],
            ['student remove', $call($student, 'remove', [1], ['note' => 'hi', 'tags' => ['a', 'b']])],
            ['student grade', $call($student, 'grade', ['a'], ['copies' => 2])],
            ['panels children', $call($panels, null, [], ['rows' => ['a', 'b'], 'badges' => ['x']], ['children' => true])],
            ['panels badge', $call($panels, 'addBadge', [], ['key' => 'enter'])],
            ['panels dynamic', $call($panels, null, [], ['stack' => ['card', 'search-box'], 'panel' => 'search-box'], ['children' => true])],
            ['shelf with its store', $call($shelf, 'checkout', [], [], ['stores' => ['stores::cart' => ['snapshot' => $cart, 'updates' => ['items' => Wire::encode([['name' => 'Pen', 'price' => 2]])]]]])],
            ['store save', $call($cart, 'save', [], ['items' => [['name' => 'Pen', 'price' => 2]]])],
            ['store made in the browser', ['kind' => 'call', 'payload' => ['snapshot' => null, 'store' => 'stores::cart', 'updates' => ['items' => Wire::encode([['name' => 'Book', 'price' => 10]])], 'call' => ['method' => 'save', 'params' => []]]]],
            ['sign-up check', $check($signUp, ['email', 'lines.0.qty'], ['email' => 'x', 'password' => 'secret-words', 'password_confirmation' => 'other', 'lines' => [['qty' => 0]]])],
            ['student check', $check($student, ['copies', 'tags.1'], ['copies' => 50, 'tags' => ['ok', 'x']])],
            ['visit counter', ['kind' => 'visit', 'uri' => '/counter', 'headers' => $visit]],
            ['partial student', ['kind' => 'visit', 'uri' => '/students/1', 'headers' => [...$visit, 'HTTP_X_NITRO_PARTIAL' => 'fabcdefghij', 'HTTP_X_NITRO_ONLY' => 'note,tags']]],
        ];
    }
}
