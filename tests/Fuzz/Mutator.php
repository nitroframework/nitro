<?php

namespace Nitro\Fusion\Tests\Fuzz;

/**
 * Seeded mutations for robustness fuzzing: a valid input bent a little (a value of another type, a
 * key gone or added, a string cut or stuffed, text spliced with tokens), so the code under test
 * meets what a careless or hostile client, or a typo, would give it. The same seed gives the same
 * mutations.
 */
final class Mutator
{
    /** Values that tend to break code: types, boundaries, encodings, markers of the wire format. */
    public const NASTY = [
        null, true, false, 0, -1, 1, PHP_INT_MAX, PHP_INT_MIN, 0.5, -0.0, 1.0E308, 9007199254740993,
        '', ' ', '0', '-1', '1e3', '0x1A', 'null', 'true', '[]', '{}', "\0", "\u{FEFF}", 'é', '𝄞', "a\nb", '%s%n',
        '../../etc/passwd', '<script>alert(1)</script>', '{{ 1 }}', '@php', '<?php', '$m', '$u', '__proto__', 'constructor',
        [], [1], ['a' => 1], ['$m' => []], ['$m' => [['k', 1]]], ['$u' => 'x'], [[[[[]]]]], ['$m' => 'x'],
    ];

    public function __construct(private int $seed)
    {
        mt_srand($seed);
    }

    public function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }

    /** @template T  @param  list<T>  $list  @return T */
    public function pick(array $list): mixed
    {
        return $list[mt_rand(0, count($list) - 1)];
    }

    /** One random change somewhere in a value (an array is changed at any depth). */
    public function value(mixed $value, int $depth = 0): mixed
    {
        if (is_array($value) && $value !== [] && $depth < 6 && $this->chance(70)) {
            $keys = array_keys($value);
            $key = $this->pick($keys);

            return match (mt_rand(1, 6)) {
                1 => $this->without($value, $key),
                2 => $value + [$this->pick(['extra', '0', 99, '$m', '__proto__', '']) => $this->pick(self::NASTY)],
                3 => array_is_list($value) ? array_reverse($value) : array_combine(array_reverse($keys), array_values($value)),
                default => array_replace($value, [$key => $this->value($value[$key], $depth + 1)]),
            };
        }

        return match (mt_rand(1, 6)) {
            1 => $this->pick(self::NASTY),
            2 => is_string($value) ? $this->string($value) : $this->pick(self::NASTY),
            3 => is_int($value) ? $this->pick([$value + 1, -$value, $value * 1000, (float) $value, (string) $value]) : $this->pick(self::NASTY),
            4 => [$value],
            5 => $this->chance(50) ? str_repeat('x', mt_rand(1000, 60000)) : array_fill(0, mt_rand(50, 400), $value),
            default => $value,
        };
    }

    /** A string cut, doubled, stuffed with nasty characters, or flipped. */
    public function string(string $value): string
    {
        $at = $value === '' ? 0 : mt_rand(0, strlen($value));

        return match (mt_rand(1, 5)) {
            1 => substr($value, 0, $at),
            2 => $value.$value,
            3 => substr($value, 0, $at).$this->pick(["\0", '"', "'", '\\', '}', '{', '%', "\u{FEFF}", '𝄞', "\x80", '<', '>']).substr($value, $at),
            4 => strrev($value),
            default => strtoupper($value),
        };
    }

    /**
     * Source text with $count edits: a span cut out, a span doubled, or one of $tokens put in. Cuts
     * and inserts fall anywhere, so the result is often invalid, which is the point.
     *
     * @param  list<string>  $tokens
     */
    public function text(string $source, array $tokens, int $count): string
    {
        for ($i = 0; $i < $count; $i++) {
            $length = strlen($source);
            $at = $length === 0 ? 0 : mt_rand(0, $length);
            $span = mt_rand(1, 24);

            $source = match (mt_rand(1, 4)) {
                1 => substr($source, 0, $at).substr($source, min($length, $at + $span)),
                2 => substr($source, 0, $at).substr($source, $at, $span).substr($source, $at),
                default => substr($source, 0, $at).$this->pick($tokens).substr($source, $at),
            };
        }

        return $source;
    }

    private function without(array $array, int|string $key): array
    {
        unset($array[$key]);

        return $array;
    }
}
