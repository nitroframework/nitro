<?php

namespace Nitro\Fusion\Tests\Fuzz;

use Illuminate\Support\Facades\Route;
use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Tests\Support\Node;
use Nitro\Fusion\Tests\TestCase;

/**
 * #[Server] round trips: random values (nested arrays with int and string keys, "05", "$m",
 * Unicode, floats, empty arrays) go server -> browser -> server -> browser as the runtime sends
 * them, and arrive as they left:
 *
 *  1. the wire: Wire::json in PHP, P.decode and P.encode in the browser, Wire::fromJson back;
 *  2. a #[Server] call: the browser's encoding sent as an update to a property of each kind (untyped,
 *     array, ?string, float, int, bool), the value the method sees, and the next snapshot read by
 *     the browser (readState).
 *
 * Off unless asked for:   FUZZ_ROUNDTRIP=500 FUZZ_SEED=1 phpunit tests/Fuzz/RoundTripFuzzTest.php
 */
class RoundTripFuzzTest extends TestCase
{
    private const PAGE = 'Nitro\Fusion\Tests\Fuzz\Generated\RoundTrip';

    private const PROPERTIES = ['any' => 'any', 'data' => 'array', 'text' => 'string', 'ratio' => 'float', 'count' => 'int', 'flag' => 'bool'];

    public function test_values_survive_server_calls_as_they_left(): void
    {
        $count = (int) getenv('FUZZ_ROUNDTRIP');

        if ($count < 1) {
            $this->markTestSkipped('Set FUZZ_ROUNDTRIP=N to send N random values through #[Server] calls.');
        }

        $seed = (int) (getenv('FUZZ_SEED') ?: random_int(1, 1_000_000));
        mt_srand($seed);
        $this->boot('laravel');
        $envelope = $this->page();

        /** Each case: a value, and the property of its kind it goes to. */
        $cases = [];

        for ($i = 0; $i < $count; $i++) {
            $property = array_rand(self::PROPERTIES);
            $value = $this->value(self::PROPERTIES[$property], 3);
            $cases[] = ['property' => $property, 'value' => $value, 'canon' => Fuzzer::canon($value)];
        }

        $failures = [];

        /** 1. The browser reads each value and sends it back. */
        $browser = Node::run('js/test/support/run-wire.mjs', ['values' => array_map(
            fn ($case, $id) => ['id' => $id, 'json' => Wire::json($case['value'])],
            $cases,
            array_keys($cases),
        )], 600)['values'];

        $states = [];

        foreach ($browser as ['id' => $id, 'canon' => $read, 'wire' => $wire]) {
            $case = $cases[$id];

            if ($read !== $case['canon']) {
                $failures[] = "#{$id} the browser read {$read}\n   PHP sent {$case['canon']}";

                continue;
            }

            $back = Fuzzer::canon(Wire::fromJson($wire));

            if ($back !== $case['canon']) {
                $failures[] = "#{$id} the browser sent back {$back}\n   it had {$case['canon']}";

                continue;
            }

            /** 2. As an update of a #[Server] call: what the method sees, and the snapshot it answers with. */
            $this->app->forgetScopedInstances();
            app(\Nitro\Fusion\Support\Registry::class)->register('pages::fuzz-roundtrip', self::PAGE);
            $response = $this->update(['snapshot' => $envelope, 'updates' => [$case['property'] => json_decode($wire, true)], 'call' => ['method' => 'keep', 'params' => []]]);

            if ($response->getStatusCode() !== 200) {
                $failures[] = "#{$id} \${$case['property']} = {$case['canon']}: the server answered ".$response->getStatusCode();

                continue;
            }

            $body = $this->json($response);
            $seen = Fuzzer::canon(Wire::decode($body['effects']['returns'])[$case['property']]);

            if ($seen !== $case['canon']) {
                $failures[] = "#{$id} \${$case['property']}: the #[Server] method saw {$seen}\n   the browser sent {$case['canon']}";

                continue;
            }

            $states[] = ['id' => $id, 'envelope' => $body['snapshot']];
        }

        /** 3. The browser reads the snapshots the calls answered with. */
        foreach ($states === [] ? [] : Node::run('js/test/support/run-wire.mjs', ['states' => $states], 600)['states'] as ['id' => $id, 'data' => $data]) {
            $case = $cases[$id];

            if (($data[$case['property']] ?? null) !== $case['canon']) {
                $failures[] = "#{$id} \${$case['property']}: the browser read ".($data[$case['property']] ?? 'nothing')." from the answer\n   it sent {$case['canon']}";
            }
        }

        fwrite(STDERR, sprintf("\n  Round-trip fuzz: seed %d, %d values, %d through #[Server] calls, %d failures\n", $seed, $count, count($states), count($failures)));
        $this->assertSame([], array_slice($failures, 0, 15), implode("\n", array_slice($failures, 0, 15)));
    }

    /** The fuzz page: a property of each kind, and a #[Server] method returning what it sees. */
    private function page(): array
    {
        $views = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fusion-roundtrip-fuzz';
        @mkdir($views);
        file_put_contents("{$views}/roundtrip.blade.php", '<div>{{ $count }}</div>');
        $this->app['view']->addLocation($views);

        eval(<<<'PHP'
            namespace Nitro\Fusion\Tests\Fuzz\Generated;

            #[\Nitro\Fusion\Attributes\View('roundtrip')]
            class RoundTrip extends \Nitro\Fusion\Component
            {
                public $any = null;
                public array $data = [];
                public ?string $text = null;
                public float $ratio = 0;
                public int $count = 0;
                public bool $flag = false;

                #[\Nitro\Fusion\Attributes\Server]
                public function keep(): array
                {
                    return ['any' => $this->any, 'data' => $this->data, 'text' => $this->text, 'ratio' => $this->ratio, 'count' => $this->count, 'flag' => $this->flag];
                }
            }
            PHP);
        app(\Nitro\Fusion\Support\Registry::class)->register('pages::fuzz-roundtrip', 'Nitro\\Fusion\\Tests\\Fuzz\\Generated\\RoundTrip');
        Route::middleware('web')->group(fn () => Route::fusion('/fuzz-roundtrip', 'Nitro\\Fusion\\Tests\\Fuzz\\Generated\\RoundTrip'));
        $this->app['router']->getRoutes()->refreshNameLookups();

        $response = $this->request('GET', '/fuzz-roundtrip');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->snapshots((string) $response->getContent())[0]['envelope'];
    }

    // ------------------------------------------------------------ random values

    private function value(string $kind, int $depth): mixed
    {
        return match ($kind) {
            'array' => $this->array($depth),
            'string' => mt_rand(1, 8) === 1 ? null : $this->string(),
            'float' => $this->pick([0, 1, -1, 1.5, -2.5, 0.1, 0.1 + 0.2, 1e20, 1.0E-7, 3.0, 123456.789, 9007199254740991]),
            'int' => $this->pick([0, 1, -1, 7, -7, 2147483648, 9007199254740991, -9007199254740991]),
            'bool' => (bool) mt_rand(0, 1),
            default => match (mt_rand(1, 6)) {
                1 => null,
                2 => $this->value('int', 0),
                3 => $this->value('float', 0),
                4 => $this->string(),
                5 => $this->value('bool', 0),
                default => $this->array($depth),
            },
        };
    }

    private function array(int $depth): array
    {
        $items = [];
        $list = mt_rand(1, 3) === 1;

        for ($i = mt_rand(0, 4); $i > 0; $i--) {
            $item = $depth > 0 && mt_rand(1, 3) === 1 ? $this->array($depth - 1) : $this->value($this->pick(['int', 'float', 'string', 'bool', 'any']), 0);

            if ($list) {
                $items[] = $item;
            } else {
                $items[$this->pick([0, 1, 5, -1, 'k', '05', '', '$m', '$u', '1.5', 'ünï', ' 1', '1', 'a b'])] = $item;
            }
        }

        return $items;
    }

    private function string(): string
    {
        return $this->pick(['', '0', '05', 'abc', 'ünï', '😀', 'a"b', "it's", "line\nbreak", '<b>x</b>', '$m', '$u', ' 7', '1e3', "\u{2028}", '{"$m":[]}', 'tab	x', '\\']);
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }
}
