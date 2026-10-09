<?php

namespace Nitro\Tests\Fuzz;

use InvalidArgumentException;
use Nitro\Support\Wire;
use Nitro\Tests\TestCase;
use Throwable;

/**
 * Fuzzing of the wire format (how state and arguments travel as JSON):
 *
 *   - random PHP values (scalars of every kind, strings in any script, lists and maps with integer,
 *     string and numeric-looking keys, nested) come back from encode, JSON and decode exactly as
 *     they were: the same types, keys, order;
 *   - random JSON shaped like the format but bent (markers in the wrong place, pairs of the wrong
 *     size, deep nesting) decodes or is refused with InvalidArgumentException, nothing else.
 *
 * Off unless asked for:   FUZZ_WIRE=5000 FUZZ_SEED=1 phpunit tests/Fuzz/WireFuzzTest.php
 */
class WireFuzzTest extends TestCase
{
    private const STRINGS = ['', '0', '00', '05', '-0', '1.0', '1e3', ' 1', 'a', 'é', '日本', '𝄞', "a\nb", "\t", '"', '\\', '$m', '$u', 'null', 'true', '[]', '{}', "\u{FEFF}"];

    private const KEYS = [0, 1, 2, -1, 10, PHP_INT_MAX, 'a', 'b', '$m', '$u', '01', ' 1', '1.5', '', 'é', 'key with spaces', '-0'];

    public function test_values_survive_the_wire_and_bent_input_is_refused(): void
    {
        $count = (int) getenv('FUZZ_WIRE');

        if ($count < 1) {
            $this->markTestSkipped('Set FUZZ_WIRE=N to send N random values through the wire format.');
        }

        $seed = (int) (getenv('FUZZ_SEED') ?: random_int(1, 1_000_000));
        $failures = [];
        $outcomes = ['round trips' => 0, 'bent, decoded' => 0, 'bent, refused' => 0];

        for ($i = 0; $i < $count; $i++) {
            $caseSeed = $seed + $i;
            $mutator = new Mutator($caseSeed);
            $value = $this->value($mutator, 0);

            try {
                $back = Wire::decode(json_decode(json_encode(Wire::encode($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR));
            } catch (Throwable $e) {
                $failures[] = "seed {$caseSeed}: ".get_class($e).": {$e->getMessage()}\n".var_export($value, true);

                continue;
            }

            if ($back !== $value) {
                $failures[] = "seed {$caseSeed}: came back different\n--- sent\n".var_export($value, true)."\n--- came back\n".var_export($back, true);

                continue;
            }

            $outcomes['round trips']++;

            /** The same, bent: what a hand-made request could send instead. */
            $bent = json_decode(json_encode($mutator->value(Wire::encode($value)), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), true, 512);

            try {
                Wire::decode($bent);
                $outcomes['bent, decoded']++;
            } catch (InvalidArgumentException) {
                $outcomes['bent, refused']++;
            } catch (Throwable $e) {
                $failures[] = "seed {$caseSeed}: bent input threw ".get_class($e).": {$e->getMessage()}\n".json_encode($bent);
            }
        }

        fwrite(STDERR, sprintf("\n  Wire fuzz: seeds %d to %d, %s, %d failures\n", $seed, $seed + $count - 1, json_encode($outcomes), count($failures)));

        $this->assertSame([], array_slice($failures, 0, 8), implode("\n\n", array_slice($failures, 0, 8)));
    }

    /** A random value of any kind the state can hold. */
    private function value(Mutator $mutator, int $depth): mixed
    {
        $kind = mt_rand(1, $depth > 3 ? 6 : 9);

        return match ($kind) {
            1 => null,
            2 => (bool) mt_rand(0, 1),
            3 => $mutator->pick([0, 1, -1, 42, PHP_INT_MAX, PHP_INT_MIN, 9007199254740993, mt_rand(-1000, 1000)]),
            4 => $mutator->pick([0.0, -0.0, 0.5, -1.25, 1.0, 1.0E-10, 1.0E300, 3.14159, (float) mt_rand(-100, 100)]),
            5, 6 => $mutator->pick(self::STRINGS).($mutator->chance(30) ? $mutator->pick(self::STRINGS) : ''),
            7 => array_map(fn () => $this->value($mutator, $depth + 1), range(1, mt_rand(0, 4)) ?: []),
            default => $this->map($mutator, $depth),
        };
    }

    private function map(Mutator $mutator, int $depth): array
    {
        $map = [];

        for ($n = mt_rand(0, 5); $n > 0; $n--) {
            $map[$mutator->pick(self::KEYS)] = $this->value($mutator, $depth + 1);
        }

        return $map;
    }
}
