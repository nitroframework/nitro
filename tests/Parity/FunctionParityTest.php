<?php

namespace Nitro\Fusion\Tests\Parity;

use Illuminate\Support\Str;
use IntlChar;
use Nitro\Fusion\Compiler\Functions;
use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Tests\Support\Node;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Every case runs in PHP and through js/src/php.js; the results must be the same value (same
 * array keys in the same order, same numbers, same strings) or the same error class.
 */
class FunctionParityTest extends TestCase
{
    public function test_operators_conversions_and_functions_match_php(): void
    {
        $cases = [];

        foreach (self::cases() as $i => [$fn, $args]) {
            $cases[] = [
                'id' => $i,
                'fn' => $fn,
                'args' => array_map(Wire::encode(...), $args),
                'expected' => self::runInPhp($fn, $args),
            ];
        }

        $failures = Node::run('js/test/support/run-functions.mjs', $cases);

        $this->assertSame([], array_map(
            fn ($f) => sprintf('#%d %s(%s): PHP %s, JS %s', $f['id'], $f['fn'],
                json_encode(Wire::encode(self::cases()[$f['id']][1]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                json_encode($f['expected'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                json_encode($f['actual'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)),
            $failures
        ));
        $this->assertGreaterThan(300, count($cases));

        /** Every function in js/functions is tested (those starting with "_" are theirs, not PHP's). */
        $untested = array_diff(
            array_map(fn (string $name) => preg_replace('/^Str_/', 'Str::', $name), array_filter(array_keys(Functions::all()), fn (string $name) => ! str_starts_with($name, '_'))),
            array_column(self::cases(), 0),
        );
        $this->assertSame([], array_values($untested), 'js/functions without parity cases');
    }

    private static function runInPhp(string $fn, array $args): array
    {
        set_error_handler(static fn () => true);

        try {
            $callable = self::ops()[$fn] ?? (str_starts_with($fn, 'Str::') ? Str::class.substr($fn, 3) : $fn);
            $byRef = in_array($fn, ['sort', 'rsort', 'usort', 'uasort', 'uksort', 'asort', 'arsort', 'ksort', 'krsort',
                'array_push', 'array_pop', 'array_shift', 'array_unshift', 'array_splice'], true);

            if ($byRef) {
                $array = $args[0];
                $return = $fn($array, ...array_slice($args, 1));
                $result = [$array, $return];
            } elseif ($fn === 'preg_match' || $fn === 'preg_match_all') {
                /** $matches (a reference): the browser's returns [result, matches]. */
                $matches = [];
                $return = $fn($args[0], $args[1], $matches, ...array_slice($args, 2));
                $result = [$return, $matches];
            } else {
                $result = $callable(...$args);
            }

            return ['value' => Wire::encode($result)];
        } catch (Throwable $e) {
            return ['error' => (new \ReflectionClass($e))->getShortName()];
        } finally {
            restore_error_handler();
        }
    }

    private static function ops(): array
    {
        return [
            'op:add' => fn ($a, $b) => $a + $b,
            'op:sub' => fn ($a, $b) => $a - $b,
            'op:mul' => fn ($a, $b) => $a * $b,
            'op:div' => fn ($a, $b) => $a / $b,
            'op:mod' => fn ($a, $b) => $a % $b,
            'op:pow' => fn ($a, $b) => $a ** $b,
            'op:concat' => fn (...$parts) => implode('', array_map(fn ($p) => (string) $p, $parts)),
            'op:eq' => fn ($a, $b) => $a == $b,
            'op:identical' => fn ($a, $b) => $a === $b,
            'op:cmp' => fn ($a, $b) => $a <=> $b,
            'op:lt' => fn ($a, $b) => $a < $b,
            'op:gt' => fn ($a, $b) => $a > $b,
            'op:lte' => fn ($a, $b) => $a <= $b,
            'op:gte' => fn ($a, $b) => $a >= $b,
            'op:bool' => fn ($a) => (bool) $a,
            'op:toStr' => fn ($a) => (string) $a,
            'op:toInt' => fn ($a) => (int) $a,
            'op:toFloat' => fn ($a) => (float) $a,
            'op:e' => fn ($a) => e($a),
            'op:inc' => function ($a) { $a++; return $a; },
            'op:dec' => function ($a) { $a--; return $a; },
            'op:get' => fn ($a, $k) => $a[$k] ?? null,
            'op:set' => function ($a, $k, $v) { $a[$k] = $v; return $a; },
            'op:push' => function ($a, $v) { $a[] = $v; return $a; },
            'op:setIn' => function ($a, $path, $v) {
                $ref = &$a;
                foreach ($path as $k) {
                    if ($k === null) {
                        $ref[] = null;
                        $k = array_key_last($ref);
                    }
                    $ref = &$ref[$k];
                }
                $ref = $v;

                return $a;
            },
            'op:unsetIn' => function ($a, $path) {
                $last = array_pop($path);
                $ref = &$a;
                foreach ($path as $k) {
                    if (! is_array($ref) || ! array_key_exists($k, $ref)) {
                        return $a;
                    }
                    $ref = &$ref[$k];
                }
                if (is_array($ref)) {
                    unset($ref[$last]);
                }

                return $a;
            },
            'op:isset' => function ($a, $path) {
                $v = $a;
                foreach ($path as $k) {
                    if (is_array($v)) {
                        if (! array_key_exists($k, $v)) {
                            return false;
                        }
                        $v = $v[$k];
                    } elseif (is_string($v)) {
                        return isset($v[$k]);
                    } else {
                        return false;
                    }
                }

                return $v !== null;
            },
            'op:empty' => function ($a, $path) {
                $v = $a;
                foreach ($path as $k) {
                    if (! is_array($v) || ! array_key_exists($k, $v)) {
                        return true;
                    }
                    $v = $v[$k];
                }

                return empty($v);
            },
            'op:literal' => function ($items) {
                $out = [];
                foreach ($items as [$k, $v, $spread]) {
                    if ($spread) {
                        $out = [...$out, ...$v];
                    } elseif ($k === null) {
                        $out[] = $v;
                    } else {
                        $out[$k] = $v;
                    }
                }

                return $out;
            },
        ];
    }

    /** @return list<array{0: string, 1: array}> */
    public static function cases(): array
    {
        $values = [null, true, false, 0, 1, -1, 1.5, 0.0, '', '0', '1', '01', '1e1', ' 1', '1 ', 'abc', '12abc', 'a', 'b', [], [1, 2], ['a' => 1], [1 => 'x']];
        $cases = [];

        foreach ($values as $a) {
            $cases[] = ['op:bool', [$a]];
            $cases[] = ['op:toInt', [$a]];
            if (! is_array($a)) {
                $cases[] = ['op:toStr', [$a]];
                $cases[] = ['op:toFloat', [$a]];
            }
            foreach ($values as $b) {
                $cases[] = ['op:eq', [$a, $b]];
                $cases[] = ['op:cmp', [$a, $b]];
            }
        }

        foreach ([[1, 2], ['5', 3], ['1.5', 2], [null, 1], [true, 1], ['abc', 1], ['12abc', 1], [[1], [2, 3]], [[1], 1], [0.1, 0.2], [PHP_INT_MAX, 0]] as $pair) {
            $cases[] = ['op:add', $pair];
            $cases[] = ['op:sub', $pair];
            $cases[] = ['op:mul', $pair];
        }

        foreach ([[7, 2], [6, 3], [1, 0], [7, -3], [-7, 3], [7.9, 3], ['9', '4'], [0, 5], [2, 10], [2, -1], [2, 0.5]] as $pair) {
            $cases[] = ['op:div', $pair];
            $cases[] = ['op:mod', $pair];
            $cases[] = ['op:pow', $pair];
        }

        foreach ([0.1 + 0.2, 1 / 3, 1e13, 0.0001, 0.00001, 1.5e-7, 123456789012345678.0, 2.5, 100.0, 1e100, -1.5, -0.001, 1e21, 0.1, 7.0E-10, 12345.678901234567] as $f) {
            $cases[] = ['op:toStr', [$f]];
        }

        $cases[] = ['op:concat', ['a', 1, 1.5, null, true, false]];
        $cases[] = ['op:concat', [0.1 + 0.2, '|', 1e20]];

        foreach (['<a href="x">&amp;</a>', "it's", 'é ✓', 5, 1.5, true, null, ''] as $s) {
            $cases[] = ['op:e', [$s]];
        }

        foreach ([0, 5, '5', 'a', 'z', 'Az', 'zz', 'a9', null, 1.5, ''] as $v) {
            $cases[] = ['op:inc', [$v]];
            $cases[] = ['op:dec', [$v]];
        }

        // array reads / writes along paths
        $nested = ['a' => ['b' => [1, 2]], 'list' => [10, 20], 5 => 'five', '6' => 'six', 'n' => null];
        foreach ([['a'], ['list'], [5], ['5'], [6], ['missing'], ['n'], [true], [null], [1.7]] as [$k]) {
            $cases[] = ['op:get', [$nested, $k]];
        }
        $cases[] = ['op:get', [[10, 20, 30], 1]];
        $cases[] = ['op:get', [[10, 20, 30], '1']];
        $cases[] = ['op:get', [[10, 20, 30], 3]];
        $cases[] = ['op:get', [[10, 20, 30], -1]];
        $cases[] = ['op:get', [null, 'x']];
        $cases[] = ['op:get', ['hello', 1]];

        foreach ([[[1, 2], 2, 'x'], [[1, 2], 0, 'x'], [[1, 2], 5, 'x'], [[1, 2], 'k', 'x'], [['a' => 1], 'b', 2], [['a' => 1], '0', 2],
            [[], 0, 'x'], [[5 => 'a'], 'x', 'y'], [[1, 2], '1', 'x'], [null, 'a', 1], [[1, 2], true, 'x'], [[1, 2], null, 'x']] as $args) {
            $cases[] = ['op:set', $args];
        }

        foreach ([[[1, 2], 3], [['a' => 1], 2], [[5 => 'a', 'k' => 'b'], 'c'], [[], 1], [[3 => 'a', 1 => 'b'], 'c']] as $args) {
            $cases[] = ['op:push', $args];
        }

        $cases[] = ['op:setIn', [$nested, ['a', 'b', 0], 'X']];
        $cases[] = ['op:setIn', [$nested, ['a', 'c'], 'new']];
        $cases[] = ['op:setIn', [$nested, ['list', null], 30]];
        $cases[] = ['op:setIn', [$nested, ['new', 'deep', 'er'], 1]];
        $cases[] = ['op:setIn', [[], [null, 'x'], 1]];
        $cases[] = ['op:setIn', [[[1, 2], [3, 4]], [1, 0], 'z']];

        $cases[] = ['op:unsetIn', [[1, 2, 3], [2]]];
        $cases[] = ['op:unsetIn', [[1, 2, 3], [0]]];
        $cases[] = ['op:unsetIn', [[1, 2, 3], [1]]];
        $cases[] = ['op:unsetIn', [$nested, ['a', 'b', 1]]];
        $cases[] = ['op:unsetIn', [$nested, ['missing', 'x']]];
        $cases[] = ['op:unsetIn', [['a' => 1, 'b' => 2], ['a']]];

        foreach ([['a'], ['a', 'b'], ['a', 'b', 1], ['a', 'b', 5], ['n'], ['missing'], ['list', 0], [5]] as $path) {
            $cases[] = ['op:isset', [$nested, $path]];
            $cases[] = ['op:empty', [$nested, $path]];
        }

        $cases[] = ['op:literal', [[[null, 'a', false], [null, 'b', false]]]];
        $cases[] = ['op:literal', [[['x', 1, false], [null, 'b', false], [5, 'c', false], [null, 'd', false]]]];
        $cases[] = ['op:literal', [[[null, 'a', false], [null, [1, 2], true], ['k', 'v', false]]]];
        $cases[] = ['op:literal', [[[null, 'a', false], [null, ['x' => 1, 3 => 'n'], true]]]];
        $cases[] = ['op:literal', [[['1', 'a', false], [null, 'b', false]]]];

        // strings
        foreach (['Hello World', '  padded  ', 'ÉCOLE élan', "tab\tnew\nline", '', 'a,b,,c', 'x'] as $s) {
            foreach (['strlen', 'mb_strlen', 'strtolower', 'strtoupper', 'mb_strtolower', 'mb_strtoupper', 'ucfirst', 'lcfirst', 'ucwords', 'trim', 'ltrim', 'rtrim', 'strrev', 'nl2br'] as $fn) {
                $cases[] = [$fn, [$s]];
            }
            $cases[] = ['str_split', [$s, 3]];
            $cases[] = ['substr', [$s, 1, 3]];
            $cases[] = ['substr', [$s, -3]];
            $cases[] = ['substr', [$s, 2, -1]];
            $cases[] = ['mb_substr', [$s, 1, 3]];
            $cases[] = ['str_pad', [$s, 14, '-=', STR_PAD_LEFT]];
            $cases[] = ['str_pad', [$s, 14, '*', STR_PAD_BOTH]];
            $cases[] = ['str_pad', [$s, 14]];
            $cases[] = ['str_contains', [$s, 'o']];
            $cases[] = ['str_starts_with', [$s, 'He']];
            $cases[] = ['str_ends_with', [$s, 'd']];
            $cases[] = ['strpos', [$s, 'l']];
            $cases[] = ['stripos', [$s, 'L']];
            $cases[] = ['strrpos', [$s, 'l']];
            $cases[] = ['str_repeat', [$s, 2]];
        }
        $cases[] = ['trim', ['xxhixx', 'x']];
        $cases[] = ['trim', ['abcHIcba', 'a..c']];
        $cases[] = ['ucwords', ['hello big-world', '-']];
        $cases[] = ['str_replace', ['a', 'b', 'banana']];
        $cases[] = ['str_replace', [['a', 'n'], ['1', '2'], 'banana']];
        $cases[] = ['str_replace', [['a', 'n'], 'x', 'banana']];
        $cases[] = ['str_replace', ['a', 'b', ['x' => 'aa', 'y' => 'ba']]];
        $cases[] = ['str_ireplace', ['A', 'b', 'banana']];
        $cases[] = ['str_ireplace', ['é', 'x', 'É é']];
        $cases[] = ['str_ireplace', [['A', 'N'], ['1', '2'], ['x' => 'bAnaNa', 'y' => 'NA']]];
        $cases[] = ['str_replace', ['.', '$1', 'a.b']];
        $cases[] = ['substr_count', ['hello hello', 'll']];
        $cases[] = ['str_repeat', ['x', -1]];
        $cases[] = ['implode', [', ', ['a', 1, 1.5, true, null]]];
        $cases[] = ['implode', [', ', ['x' => 'a', 'y' => 'b']]];
        $cases[] = ['explode', [',', 'a,b,,c']];
        $cases[] = ['explode', [',', 'a,b,c', 2]];
        $cases[] = ['explode', [',', 'a,b,c', -1]];
        $cases[] = ['explode', [',', 'a,b,c', 0]];
        $cases[] = ['explode', ['', 'abc']];
        $cases[] = ['sprintf', ['%05.2f|%-6s|%\'*8d|%+d|%x|%X|%o|%b|%c', 3.14159, 'ab', 42, 5, 255, 255, 8, 5, 65]];
        $cases[] = ['sprintf', ['%1$s %2$s %1$s', 'a', 'b']];
        $cases[] = ['sprintf', ['%.3e|%e|%10.4f|%-10s|', 12345.678, 0.00012, -3.14159, 'hi']];
        $cases[] = ['sprintf', ['%d%%', 50]];
        $cases[] = ['sprintf', ['%s %s', 'one']];
        $cases[] = ['sprintf', ['%.2f', 1.005]];
        $cases[] = ['sprintf', ['%u', -1]];
        $cases[] = ['sprintf', ['%05d', -42]];

        // numbers
        foreach ([[1.005, 2], [0.285, 2], [1.955, 2], [5.055, 2], [2.5, 0], [-2.5, 0], [1234.5678, 2], [1.45, 1], [-1.45, 1], [0.5, 0], [0.05, 0], [999.5, 0],
            [1234.5678, -2], [1.4999999999999999, 0], [5.045, 2], [0.1 + 0.2, 10], [3, 0], [-0.4, 0], [1e20, 2]] as [$v, $p]) {
            $cases[] = ['round', [$v, $p]];
            $cases[] = ['number_format', [$v, max(0, $p)]];
        }
        $cases[] = ['number_format', [1234567.891, 2, ',', '.']];
        $cases[] = ['number_format', [1234567.891]];
        $cases[] = ['number_format', [-1234.567, 1]];
        $cases[] = ['number_format', [-0.001, 2]];
        $cases[] = ['number_format', [0.5]];
        foreach ([-3.7, 3.2, '4.5', null, true] as $v) {
            $cases[] = ['abs', [$v]];
            $cases[] = ['floor', [$v]];
            $cases[] = ['ceil', [$v]];
            $cases[] = ['intval', [$v]];
            $cases[] = ['floatval', [$v]];
            $cases[] = ['boolval', [$v]];
        }
        $cases[] = ['intdiv', [7, 2]];
        $cases[] = ['intdiv', [-7, 2]];
        $cases[] = ['intdiv', [1, 0]];
        $cases[] = ['fmod', [7.5, 2]];
        $cases[] = ['sqrt', [16]];
        $cases[] = ['max', [[1, '10', 9.5]]];
        $cases[] = ['max', [1, 5, 3]];
        $cases[] = ['min', ['apple', 'banana']];
        $cases[] = ['max', ['abc', 0]];
        $cases[] = ['max', [[]]];
        $cases[] = ['min', [[3, 1, 2]]];
        foreach ([5, '5', '5.5', '5a', 'abc', null, [], true, 1.5, ' 5', '5 '] as $v) {
            foreach (['is_numeric', 'is_string', 'is_array', 'is_bool', 'is_null'] as $fn) {
                $cases[] = [$fn, [$v]];
            }
        }

        // arrays
        $list = [3, 1, 2];
        $assoc = ['b' => 2, 'a' => 1, 'c' => 3];
        $mixed = [5 => 'x', 'k' => 'y', 2 => 'z'];
        $rows = [['id' => 1, 'name' => 'Ann', 'team' => 'a'], ['id' => 2, 'name' => 'Bob', 'team' => 'b'], ['id' => 3, 'name' => 'Cy']];
        foreach ([$list, $assoc, $mixed, [], [0, '', null, 'a', false, 1]] as $a) {
            foreach (['count', 'array_keys', 'array_values', 'array_key_first', 'array_key_last', 'array_is_list', 'array_sum', 'array_unique', 'array_flip', 'array_reverse'] as $fn) {
                $cases[] = [$fn, [$a]];
            }
            $cases[] = ['array_filter', [$a]];
            $cases[] = ['array_slice', [$a, 1]];
            $cases[] = ['array_slice', [$a, 1, 1, true]];
            $cases[] = ['array_reverse', [$a, true]];
            $cases[] = ['array_chunk', [$a, 2]];
            $cases[] = ['array_chunk', [$a, 2, true]];
            $cases[] = ['array_pad', [$a, 5, 0]];
            $cases[] = ['array_pad', [$a, -5, 0]];
            foreach (['sort', 'rsort', 'asort', 'arsort', 'ksort', 'krsort', 'array_pop', 'array_shift'] as $fn) {
                $cases[] = [$fn, [$a]];
            }
            $cases[] = ['array_push', [$a, 'p', 'q']];
            $cases[] = ['array_unshift', [$a, 'u']];
            $cases[] = ['array_splice', [$a, 1, 1, ['r']]];
            $cases[] = ['in_array', ['1', $a]];
            $cases[] = ['in_array', ['1', $a, true]];
            $cases[] = ['array_search', [1, $a]];
            $cases[] = ['array_key_exists', ['k', $a]];
            $cases[] = ['array_key_exists', [1, $a]];
        }
        $cases[] = ['array_merge', [$list, $assoc, $mixed]];
        $cases[] = ['array_merge', [[5 => 'a'], [5 => 'b']]];
        $cases[] = ['array_merge', [['a' => 1], ['a' => 2]]];
        $cases[] = ['array_column', [$rows, 'name']];
        $cases[] = ['array_column', [$rows, 'name', 'id']];
        $cases[] = ['array_column', [$rows, 'team']];
        $cases[] = ['array_column', [$rows, null, 'id']];
        $cases[] = ['array_combine', [['a', 'b'], [1, 2]]];
        $cases[] = ['array_combine', [['a'], [1, 2]]];
        $cases[] = ['array_fill', [5, 3, 'v']];
        $cases[] = ['array_fill_keys', [['a', 5], 0]];
        $cases[] = ['array_diff', [[1, 2, 3, 4], [2, 4]]];
        $cases[] = ['array_intersect', [[1, 2, 3, 4], [2, 4, 5]]];
        $cases[] = ['array_diff_key', [['a' => 1, 'b' => 2, 'c' => 3], ['a' => 9]]];
        $cases[] = ['array_intersect_key', [['a' => 1, 'b' => 2, 'c' => 3], ['a' => 9, 'c' => 9]]];
        $cases[] = ['range', [1, 5]];
        $cases[] = ['range', [5, 1]];
        $cases[] = ['range', [0, 10, 3]];
        $cases[] = ['range', ['a', 'e']];
        $cases[] = ['range', [0, 1, 0.25]];
        $cases[] = ['array_unique', [[1, '1', 2, 2.0, 'a', 'A']]];
        $cases[] = ['sort', [[10, '9', 2.5, '10', 1]]];
        $cases[] = ['sort', [['banana', 'apple', 'Cherry']]];
        $cases[] = ['count', ['string']];
        $cases[] = ['json_encode', [['a/b', 'é', '<>&\'"', 1.5, [1 => 'x'], [], ['a' => null], true, 0]]];
        $cases[] = ['json_encode', [['a' => 1], JSON_PRETTY_PRINT]];
        $cases[] = ['json_encode', [['é/x'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES]];
        $cases[] = ['json_decode', ['{"b":1,"a":{"5":2,"x":[1,2]}}', true]];
        $cases[] = ['json_decode', ['not json', true]];

        // PHP callables given by name
        $cases[] = ['array_map', ['intval', ['1', '2x', 3.7]]];
        $cases[] = ['array_map', ['strtoupper', ['a' => 'x', 'b' => 'y']]];
        $cases[] = ['array_filter', [[1, '', 'abc', 0], 'strlen']];
        $cases[] = ['array_map', ['no_such_function', [1]]];

        return [...$cases, ...self::portedCases()];
    }

    /**
     * js/functions: Viewi's PhpJsFunctions, ported to Fusion's values. Where PHP does something
     * else than Viewi's (locutus') code, these cases are why the port does what PHP does.
     */
    private static function portedCases(): array
    {
        $cases = [];

        // comparing and searching strings
        foreach ([['a', 'b'], ['b', 'a'], ['abc', 'abc'], ['abc', 'ab'], ['', 'a'], ['Hello', 'hello'], ['É', 'é'], ["\u{FFFD}", "\u{1F600}"], [10, 9], ['10', '9'], [null, '']] as [$x, $y]) {
            foreach (['strcmp', 'strcasecmp'] as $fn) {
                $cases[] = [$fn, [$x, $y]];
            }
            foreach ([0, 1, 2, 5] as $n) {
                $cases[] = ['strncmp', [$x, $y, $n]];
                $cases[] = ['strncasecmp', [$x, $y, $n]];
            }
        }
        $cases[] = ['strncmp', ['a', 'b', -1]];
        $cases[] = ['strncasecmp', ['a', 'b', -1]];
        foreach (['user@example.com', 'Hello World', 'naïve café', ''] as $s) {
            foreach (['@', 'o', 'O', 'é', 'É', 'xyz', ''] as $needle) {
                $cases[] = ['strstr', [$s, $needle]];
                $cases[] = ['strstr', [$s, $needle, true]];
                $cases[] = ['stristr', [$s, $needle]];
                $cases[] = ['stristr', [$s, $needle, true]];
                $cases[] = ['strchr', [$s, $needle]];
                $cases[] = ['strrchr', [$s, $needle]];
                $cases[] = ['strripos', [$s, $needle]];
                $cases[] = ['strpbrk', [$s, $needle]];
            }
            foreach ([['aeiou', 0, null], ['lo He', 0, null], ['lo He', 2, null], ['lo He', -4, null], ['lo He', 1, 3], ['lo He', 0, -2], ['', 0, null], ['é', 0, null]] as [$mask, $start, $length]) {
                $cases[] = ['strspn', [$s, $mask, $start, $length]];
                $cases[] = ['strcspn', [$s, $mask, $start, $length]];
            }
        }
        $cases[] = ['strrchr', ['a/b/c', 47]];
        $cases[] = ['strrchr', ["a\0b", '']];
        foreach ([0, 3, -3, -1, 20, -20] as $offset) {
            $cases[] = ['strrpos', ['hello hello', 'll', $offset]];
            $cases[] = ['strripos', ['HeLLo hello', 'Ll', $offset]];
        }


        // changing strings
        $cases[] = ['strtr', ['Hi all', 'ai', 'eo']];
        $cases[] = ['strtr', ['Hi all', 'ai', 'e']];
        $cases[] = ['strtr', ['café', 'é', 'e']];
        $cases[] = ['strtr', ['Hi all, I said', ['Hi' => 'Hello', 'Hello' => 'x', 'a' => 'A', '' => 'E']]];
        $cases[] = ['strtr', ['abc', ['ab' => 'x', 'abc' => 'y', 1 => 'one']]];
        foreach ([[1, 2], [1, null], [0, 0], [-2, 1], [-10, 1], [10, 1], [2, -1], [2, -10], [1, 100]] as [$start, $length]) {
            $cases[] = ['substr_replace', ['Hello', 'XYZ', $start, $length]];
        }
        $cases[] = ['substr_replace', ['héllo', '-', 1, 2]];
        foreach ([['Hello', 'll', 2, null, false], ['Hello', 'LL', 2, 2, true], ['Hello', 'LL', 2, 2, false], ['Hello', 'lo', -2, null, false], ['Hello', 'H', 0, 1, false], ['Hello', 'xyz', 10, null, false], ['Hello', 'lo', 3, -1, false], ['abc', 'b', 1, 5, false], ['abcde', 'bc', 1, 2, false]] as $args) {
            $cases[] = ['substr_compare', $args];
        }
        foreach ([['The quick brown fox sat over the lazy dog', 15, "\n", true], ['A very long woooooooooooord.', 8, "\n", true], ['A very long woooooooooooord.', 8, "\n", false], ['A very long woooooooooooooooooord. and something', 8, "<br>", false], ["Line one\nLine two is longer", 6], ['short', 10], ['', 5], ['abc def', 0, "\n", false], ['  lots   of   spaces  ', 4], ['exactly five', 5, "-", true]] as $args) {
            $cases[] = ['wordwrap', $args];
        }
        $cases[] = ['wordwrap', ['abc def', 0, "\n", true]];
        $cases[] = ['str_rot13', ['Hello, World! 123']];
        foreach (["Hello fri3nd, you're looking good today!", "-it's a-b- test", "'quoted' --dash-- a'b", 'naïve café', '', 'one two  three'] as $s) {
            $cases[] = ['str_word_count', [$s]];
            $cases[] = ['str_word_count', [$s, 1]];
            $cases[] = ['str_word_count', [$s, 2]];
            $cases[] = ['str_word_count', [$s, 1, '0..9']];
            $cases[] = ['str_word_count', [$s, 1, "3'-"]];
        }
        $cases[] = ['str_word_count', ['a b', 3]];
        foreach ([65, 97, 256 + 66, -1, 0, 128, '66', 3.9] as $v) {
            $cases[] = ['chr', [$v]];
        }
        foreach (['A', 'abc', '', 'é', "\n", 5] as $v) {
            $cases[] = ['ord', [$v]];
            $cases[] = ['bin2hex', [$v]];
        }
        foreach (['48656c6c6f', 'c3a9', '4', 'zz', '', 'ff'] as $v) {
            $cases[] = ['hex2bin', [$v]];
        }
        foreach ([['abcdefg', 3, '|'], ['abcd', 2], ["ab\ncd\nef", 3, '.'], ['héllo', 2, '-'], ['', 2, '-'], ['abc', 0]] as $args) {
            $cases[] = ['chunk_split', $args];
        }
        foreach (["It's \"quoted\" \\ and \0 null", 'plain'] as $s) {
            $cases[] = ['addslashes', [$s]];
            $cases[] = ['stripslashes', [addslashes($s)]];
        }
        $cases[] = ['stripslashes', ['a\\\\b\\c\\']];
        $cases[] = ['quotemeta', ['1 + 1 = 2? [yes] (no) $5 ^ a.b \\ *']];


        // HTML, similarity, phonetics
        foreach (['&lt;p&gt;Caf&eacute; &amp;amp; &quot;tea&quot; &#039;x&#039; &#39; &apos; &#x41;&#66; &hearts; &nbsp;|&bogus; &#0; &#xD800;', 'plain', '&amp;lt;'] as $s) {
            foreach ([null, ENT_NOQUOTES, ENT_COMPAT, ENT_QUOTES | ENT_HTML5, ENT_HTML5] as $flags) {
                $cases[] = ['htmlspecialchars_decode', $flags === null ? [$s] : [$s, $flags]];
                if ($flags === null || ($flags & ENT_HTML5) === 0) {
                    /** ENT_HTML5's entity table is not in the browser (only HTML 4.01's): html_entity_decode() says so. */
                    $cases[] = ['html_entity_decode', $flags === null ? [$s] : [$s, $flags]];
                }
            }
        }
        foreach (['<a href="x">Café & "tea" \'x\' © ♥ €</a>', '&amp; &copy; &bogus; &#39;'] as $s) {
            $cases[] = ['htmlentities', [$s]];
            $cases[] = ['htmlentities', [$s, ENT_NOQUOTES]];
            $cases[] = ['htmlentities', [$s, ENT_QUOTES]];
            $cases[] = ['htmlentities', [$s, ENT_QUOTES, 'UTF-8', false]];
        }
        foreach (['<p>Hello <b>World</b></p>', 'a < b and c > d', 'x<br/>y', '<a href="x>y">link</a>', "<a title='>'>t</a>", '<!-- comment -->text<!-- open', '<?php echo 1; ?>after', 'unclosed <b', '1 <2 3', '<p onclick="a<b">x</p>', '<<b>>x', '<!DOCTYPE html><p>d</p>', 'text<', "<scr\0ipt>x"] as $s) {
            $cases[] = ['strip_tags', [$s]];
        }
        foreach ([['kitten', 'sitting'], ['', 'abc'], ['abc', ''], ['same', 'same'], ['café', 'cafe'], ['flaw', 'lawn']] as [$a, $b]) {
            $cases[] = ['levenshtein', [$a, $b]];
            $cases[] = ['levenshtein', [$a, $b, 2, 3, 4]];
            $cases[] = ['similar_text', [$a, $b]];
        }
        $cases[] = ['similar_text', ['World', 'Word']];
        $cases[] = ['similar_text', ['Hello World', 'Hallo Welt']];
        foreach (['Robert', 'Rupert', 'Tymczak', 'Pfister', 'Ashcraft', 'Lloyd', '', 'a1b2', '123', 'O\'Hara', 'Euler', 'Gauss', 'Hilbert', 'Knuth', 'Lukasiewicz', 'éclair'] as $s) {
            $cases[] = ['soundex', [$s]];
            $cases[] = ['metaphone', [$s]];
        }
        foreach (['Thompson', 'knight', 'Schmidt', 'wright', 'Xavier', 'character', 'gnome', 'phone', 'thumb', 'science', 'judge', 'Ghost', 'laugh', 'which', 'Chris', 'sugar', 'ocean', 'caesar'] as $s) {
            $cases[] = ['metaphone', [$s]];
            $cases[] = ['metaphone', [$s, 3]];
        }
        $cases[] = ['vsprintf', ['%s is %d years, %05.1f%%', ['Ann', 30, 12.345]]];
        $cases[] = ['vsprintf', ['%2$s %1$s', ['a' => 'x', 'b' => 'y']]];
        $cases[] = ['vsprintf', ['%s', 'no array']];


        // ctype
        foreach (['abc', 'ABC', 'aBc1', '123', '', ' ', "\t\n", 'a b', '!?.', 'é', 'AbCdEf09', "\x7f", 53, 300, -3, 'ff', null, 1.5] as $v) {
            foreach (['ctype_alnum', 'ctype_alpha', 'ctype_cntrl', 'ctype_digit', 'ctype_graph', 'ctype_lower', 'ctype_print', 'ctype_punct', 'ctype_space', 'ctype_upper', 'ctype_xdigit'] as $fn) {
                $cases[] = [$fn, [$v]];
            }
        }

        // math
        foreach ([0, 0.5, -0.5, 1, '0.25', true] as $v) {
            foreach (['acos', 'asin', 'atan', ...(in_array($v, [1, true], true) ? [] : ['atanh']), 'cos', 'cosh', 'sin', 'sinh', 'tan', 'tanh', 'exp', 'expm1', 'log1p', 'asinh', 'deg2rad', 'rad2deg'] as $fn) {
                $cases[] = [$fn, [$v]];
            }
        }
        foreach ([1, 2.5, 10, 100, '1000'] as $v) {
            $cases[] = ['log', [$v]];
            $cases[] = ['log', [$v, 2]];
            $cases[] = ['log', [$v, 10]];
            $cases[] = ['log10', [$v]];
            $cases[] = ['acosh', [$v]];
        }
        $cases[] = ['log', [8, 0]];
        $cases[] = ['log', [8, -2]];
        $cases[] = ['atan2', [1, 2]];
        $cases[] = ['atan2', [-1, -2]];
        $cases[] = ['hypot', [3, 4]];
        $cases[] = ['pi', []];
        foreach ([1.5, 0, -2, '3'] as $v) {
            $cases[] = ['is_finite', [$v]];
            $cases[] = ['is_infinite', [$v]];
            $cases[] = ['is_nan', [$v]];
        }
        foreach ([0, 1, 10, 255, -1, 3.9, '42', 9007199254740991] as $v) {
            $cases[] = ['decbin', [$v]];
            $cases[] = ['dechex', [$v]];
            $cases[] = ['decoct', [$v]];
        }
        foreach (['0', '1010', '11111111', '102', 'b101', '', '1111111111111111111111111111111111111111111111111111111'] as $v) {
            $cases[] = ['bindec', [$v]];
        }
        foreach (['ff', 'FF', '1a2B', 'xyz', '0x1A', ''] as $v) {
            $cases[] = ['hexdec', [$v]];
        }
        foreach (['777', '17', '8', ''] as $v) {
            $cases[] = ['octdec', [$v]];
        }
        foreach ([['255', 10, 16], ['ff', 16, 2], ['zz', 36, 10], ['1010', 2, 36], ['12a', 10, 16], ['-15', 10, 2], ['', 10, 2]] as $args) {
            $cases[] = ['base_convert', $args];
        }

        // URLs
        foreach (['Hello, World!', 'café ☕ 😀', '', 'a+b/c=d?e&f g~h*i\'j(k)l', "line\nbreak"] as $s) {
            $cases[] = ['base64_encode', [$s]];
            $cases[] = ['rawurlencode', [$s]];
            $cases[] = ['urlencode', [$s]];
            $cases[] = ['rawurldecode', [rawurlencode($s)]];
            $cases[] = ['urldecode', [urlencode($s)]];
        }
        foreach (['SGVsbG8=', 'Y2Fmw6kg4piVIPCfmIA=', '', 'not base64!', 'SGVsbG8'] as $s) {
            $cases[] = ['base64_decode', [$s]];
        }
        $cases[] = ['urldecode', ['a%2Bb+c%zz%']];
        $cases[] = ['rawurldecode', ['a%2Bb+c%zz%']];
        $cases[] = ['http_build_query', [['a' => 1, 'b' => 'x y', 'c' => ['d' => 2, 'e' => [3, 4]], 'f' => null, 'g' => true, 'h' => false, 5 => 'five']]];
        $cases[] = ['http_build_query', [[1, 2], 'n_']];
        $cases[] = ['http_build_query', [['a' => 'x y', 'b' => '~'], '', '&amp;', PHP_QUERY_RFC3986]];
        $cases[] = ['http_build_query', [['a' => 1.5, 'b' => ['x' => null], 'c' => []]]];
        foreach (['https://user:pass@example.com:8080/path/to?x=1&y=2#frag', '//example.com/p', '/just/a/path?q', 'mailto:a@b.c', 'example.com', 'http://[::1]:80/', '?q=1', '#f', 'http:///broken', ''] as $url) {
            $cases[] = ['parse_url', [$url]];
            $cases[] = ['parse_url', [$url, PHP_URL_HOST]];
            $cases[] = ['parse_url', [$url, PHP_URL_QUERY]];
        }


        // arrays (callbacks given by name: closures do not cross the wire)
        $a = ['a' => 'green', 'B' => 'brown', 'c' => 'blue', 0 => 'red', 'x' => '1'];
        $b = ['a' => 'GREEN', 'b' => 'yellow', 0 => 'Red', 'c' => 'blue', 1 => 1];
        foreach (['array_diff_assoc', 'array_intersect_assoc'] as $fn) {
            $cases[] = [$fn, [$a, $b]];
            $cases[] = [$fn, [$a, $b, ['x' => 1]]];
            $cases[] = [$fn, [[1, 2, 3], [1, 3, 2]]];
        }
        foreach (['array_diff_ukey', 'array_intersect_ukey', 'array_udiff', 'array_uintersect', 'array_udiff_assoc', 'array_diff_uassoc', 'array_intersect_uassoc'] as $fn) {
            $cases[] = [$fn, [$a, $b, 'strcasecmp']];
            $cases[] = [$fn, [$a, $b, 'strcmp']];
        }
        $cases[] = ['array_udiff_uassoc', [$a, $b, 'strcasecmp', 'strcasecmp']];
        $cases[] = ['array_uintersect_uassoc', [$a, $b, 'strcasecmp', 'strcmp']];
        $cases[] = ['array_merge_recursive', [['a' => [1], 'b' => 2, 5 => 'x'], ['a' => [3], 'b' => 4, 5 => 'y'], ['a' => 'z', 'c' => ['d' => 1]]]];
        $cases[] = ['array_merge_recursive', [[1, 2], [3]]];
        $cases[] = ['array_merge_recursive', [['k' => ['x' => 1]], ['k' => ['x' => 2, 'y' => 3]]]];
        $cases[] = ['array_replace', [[1, 2, 3], [1 => 'b'], [3 => 'd', 'k' => 'v']]];
        $cases[] = ['array_replace', [['a' => 1, 'b' => 2], ['b' => 3, 'c' => 4]]];
        $cases[] = ['array_replace_recursive', [['citrus' => ['orange'], 'berries' => ['blackberry', 'raspberry']], ['citrus' => ['pineapple'], 'berries' => ['blueberry']]]];
        $cases[] = ['array_replace_recursive', [['a' => ['x' => 1, 'y' => 2], 'b' => 1], ['a' => 'flat', 'b' => ['deep' => 1]], ['c' => 3]]];
        $cases[] = ['array_change_key_case', [['FirSt' => 1, 'SecOnd' => 4, 5 => 'n', 'Été' => 2]]];
        $cases[] = ['array_change_key_case', [['FirSt' => 1, 'SecOnd' => 4, 'first' => 9], CASE_UPPER]];
        $cases[] = ['array_change_key_case', [['a', 'b']]];
        $cases[] = ['array_count_values', [[1, 'hello', 1, 'world', 'hello', '1', 1.5, true, null, '01']]];
        $cases[] = ['array_count_values', [['x' => 'a', 'y' => 'b', 'z' => 'a']]];
        foreach ([[1, 2], [], 'x'] as $v) {
            $cases[] = ['sizeof', [$v]];
        }
        foreach ([['img12.png', 'img10.png'], ['img2', 'img10'], ['a', 'A'], ['x01', 'x1'], ['1.5', '1.10'], ['', 'a'], ['abc', 'abc'], ['  a', 'a'], ['Img12', 'img10'], ['0.01', '0.1'], ['a0', 'a00'], ['9', '10']] as [$x, $y]) {
            $cases[] = ['strnatcmp', [$x, $y]];
            $cases[] = ['strnatcasecmp', [$x, $y]];
        }


        /**
         * mbstring: every character, at a word's start and in a word. Those the browser's Unicode
         * knows: PHP's mbstring is on a later Unicode than 16.0 (Node's, and intl's here), which
         * added characters, gave some their other case (U+A7D3's upper case U+A7D2) and made U+0295
         * caseless.
         */
        foreach ([[0x80, 0xD7FF], [0xE000, 0xFFFF], [0x10000, 0x1FFFF], [0x20000, 0x20FFF]] as [$from, $to]) {
            for ($c = $from; $c <= $to; $c += 1024) {
                $known = array_filter(range($c, min($c + 1023, $to)), fn ($c) => IntlChar::charAge($c)[0] > 0 && $c !== 0x295
                    && array_all(mb_str_split(mb_convert_case(mb_chr($c, 'UTF-8'), MB_CASE_TITLE).mb_strtolower(mb_chr($c, 'UTF-8'))), fn ($m) => IntlChar::charAge(mb_ord($m))[0] > 0));
                $chars = array_map(fn ($c) => mb_chr($c, 'UTF-8'), $known);
                $cases[] = ['mb_convert_case', [implode(' ', $chars), MB_CASE_TITLE]];
                $cases[] = ['mb_convert_case', [implode(' ', array_map(fn ($ch) => 'A'.$ch.'Z', $chars)), MB_CASE_TITLE]];
                $cases[] = ['mb_strwidth', [implode('', $chars)]];
            }
        }
        foreach (['hello world', "o'neil mcdonald-smith", 'ΟΔΟΣ ΟΔΟΣ.', 'σΑΣ ΣΑΣ', 'ǆemal ǈubljana', 'straße ﬃ ŉ', 'hello.world 3rd_x', 'ÉCOLE élève', 'İstanbul', ''] as $s) {
            foreach ([MB_CASE_UPPER, MB_CASE_LOWER, MB_CASE_TITLE] as $mode) {
                $cases[] = ['mb_convert_case', [$s, $mode]];
            }
            $cases[] = ['mb_ucfirst', [$s]];
            $cases[] = ['mb_lcfirst', [$s]];
            $cases[] = ['mb_str_split', [$s]];
            $cases[] = ['mb_str_split', [$s, 3]];
        }
        $cases[] = ['mb_str_split', ['abc', 0]];
        foreach ([['héllo', 8, '*', STR_PAD_RIGHT], ['héllo', 8, 'é-', STR_PAD_LEFT], ['héllo', 9, 'ab', STR_PAD_BOTH], ['héllo', 3], ['', 2, '日'], ['x', 3, ''], ['x', 3, ' ', 5]] as $args) {
            $cases[] = ['mb_str_pad', $args];
        }
        foreach (['日本語のテキスト', 'naïve café', 'abc'] as $s) {
            foreach (['テ', 'é', 'c', 'x', ''] as $n) {
                foreach ([0, 2, -2, 20] as $offset) {
                    $cases[] = ['mb_strpos', [$s, $n, $offset]];
                    $cases[] = ['mb_strrpos', [$s, $n, $offset]];
                }
                $cases[] = ['mb_stripos', [mb_strtoupper($s), $n]];
            }
            $cases[] = ['mb_strwidth', [$s]];
            foreach ([[0, 5, ''], [0, 5, '...'], [2, 4, '…'], [-3, 10, '>'], [0, 100, '...'], [9, 2]] as $args) {
                $cases[] = ['mb_strimwidth', [$s, ...$args]];
            }
        }
        $cases[] = ['mb_substr_count', ['日本日本日', '日本']];
        $cases[] = ['mb_substr_count', ['aaaa', 'aa']];
        $cases[] = ['mb_substr_count', ['a', '']];


        // Laravel's Str
        $texts = ['This is my name', 'hannah', 'Laravel Framework', 'naïve café  ÉCOLE', '日本語のテキスト', '', 'a-b_c d', "  padded\t\n", 'fooBarBaz', 'Foo Bar', 'foo_bar-baz qux', 'ǆemal ΟΔΟΣ', 'x'];
        foreach ($texts as $s) {
            foreach (['after', 'afterLast', 'before', 'beforeLast'] as $fn) {
                foreach (['a', 'é', 'Framework', ' ', '', 'xyz'] as $search) {
                    $cases[] = ["Str::$fn", [$s, $search]];
                }
            }
            $cases[] = ['Str::between', [$s, 'a', 'e']];
            $cases[] = ['Str::between', [$s, '', 'e']];
            foreach (['camel', 'kebab', 'snake', 'studly', 'pascal', 'lower', 'upper', 'title', 'headline', 'ucsplit', 'length', 'reverse', 'squish', 'ucfirst', 'lcfirst', 'wordCount', 'slug', 'ascii', 'trim', 'ltrim', 'rtrim', 'isAscii'] as $fn) {
                $cases[] = ["Str::$fn", [$s]];
            }
            $cases[] = ['Str::snake', [$s, '__']];
            $cases[] = ['Str::studly', [$s, true]];
            $cases[] = ['Str::slug', [$s, '_']];
            $cases[] = ['Str::slug', [$s, '-', null]];
            foreach ([['a', false], ['A', true], [['xyz', 'name'], false], [['É'], true], [[], false], ['', false]] as [$needles, $ignoreCase]) {
                $cases[] = ['Str::contains', [$s, $needles, $ignoreCase]];
                $cases[] = ['Str::doesntContain', [$s, $needles, $ignoreCase]];
            }
            $cases[] = ['Str::containsAll', [$s, ['a', 'e']]];
            $cases[] = ['Str::containsAll', [$s, []]];
            foreach (['T', 'This', 'h', ['x', 'L'], '', null, 5] as $needle) {
                $cases[] = ['Str::startsWith', [$s, $needle]];
                $cases[] = ['Str::endsWith', [$s, $needle]];
                $cases[] = ['Str::doesntStartWith', [$s, $needle]];
                $cases[] = ['Str::doesntEndWith', [$s, $needle]];
            }
            foreach ([[5, '...', false], [5, '...', true], [8, '>', false], [100, '...', false], [3, '', true]] as $args) {
                $cases[] = ['Str::limit', [$s, ...$args]];
            }
            foreach ([1, 2, 3, 0] as $n) {
                $cases[] = ['Str::words', [$s, $n]];
                $cases[] = ['Str::words', [$s, $n, '>>>']];
            }
            foreach ([['*', 2, null], ['*', -4, null], ['#', 1, 3], ['*', 50, null], ['', 0, null], ['日', -3, 2]] as [$char, $index, $length]) {
                $cases[] = ['Str::mask', [$s, $char, $index, $length]];
            }
            foreach ([10, 3] as $length) {
                $cases[] = ['Str::padLeft', [$s, $length, '-']];
                $cases[] = ['Str::padRight', [$s, $length]];
                $cases[] = ['Str::padBoth', [$s, $length, 'ab']];
            }
            $cases[] = ['Str::substr', [$s, 2, 3]];
            $cases[] = ['Str::substr', [$s, -3]];
            $cases[] = ['Str::position', [$s, 'a']];
            $cases[] = ['Str::position', [$s, 'a', 2]];
            $cases[] = ['Str::finish', [$s, '/']];
            $cases[] = ['Str::start', [$s, '/']];
            $cases[] = ['Str::wrap', [$s, '"']];
            $cases[] = ['Str::wrap', [$s, '<', '>']];
            $cases[] = ['Str::unwrap', ['"'.$s.'"', '"']];
            $cases[] = ['Str::chopStart', [$s, ['Th', 'ha']]];
            $cases[] = ['Str::chopEnd', [$s, 'ne']];
            $cases[] = ['Str::replace', ['a', 'A', $s]];
            $cases[] = ['Str::replace', [['a', 'é'], ['4', 'e'], $s, false]];
            $cases[] = ['Str::replace', ['É', '!', $s, false]];
            $cases[] = ['Str::replaceFirst', ['a', '_', $s]];
            $cases[] = ['Str::replaceLast', ['a', '_', $s]];
            $cases[] = ['Str::replaceFirst', ['', '_', $s]];
            $cases[] = ['Str::repeat', [$s, 2]];
        }
        foreach (['///path//', "a//\n", 'x', ''] as $s) {
            $cases[] = ['Str::finish', [$s, '/']];
            $cases[] = ['Str::start', [$s, '/']];
            $cases[] = ['Str::deduplicate', [$s, '/']];
            $cases[] = ['Str::deduplicate', [$s, ['/', "\n"]]];
        }
        $cases[] = ['Str::deduplicate', ['The   Laravel    Framework']];
        $cases[] = ['Str::deduplicate', ['abababab', 'ab']];
        foreach ([['foo*', 'foobar'], ['*.php', 'a.php'], ['*.php', 'a.phpx'], ['FOO*', 'foobar', true], [['x', 'b*'], 'bar'], ['*', ''], ['a.c', 'abc'], ['a*c', "a\nc"], ['É*', 'école', true], ['[a]', '[a]'], ['foo', "foo\n"]] as $args) {
            $cases[] = ['Str::is', $args];
        }
        foreach (['Hello World @ Ünïcode 日本', 'Ærøskøbing Straße ﬁ', 'Привет, мир! Ωμέγα', '  --already-a-slug--  ', 'user@example.com', 'x_y-z'] as $s) {
            $cases[] = ['Str::slug', [$s]];
            $cases[] = ['Str::slug', [$s, '_']];
            $cases[] = ['Str::slug', [$s, '-', 'en', ['@' => 'at', '&' => 'and']]];
            $cases[] = ['Str::ascii', [$s]];
        }
        foreach (["\u{200B}\u{00A0} text \u{3000}\u{FEFF}", "\u{1D159}x\u{E0020}", "  \t\n  "] as $s) {
            $cases[] = ['Str::trim', [$s]];
            $cases[] = ['Str::ltrim', [$s]];
            $cases[] = ['Str::rtrim', [$s]];
            $cases[] = ['Str::squish', [$s."a \u{3164} b"]];
        }
        $cases[] = ['Str::trim', ['xxhixx', 'x']];
        $cases[] = ['Str::limit', ['<p>The quick brown fox</p> jumps', 12, '...', true]];
        $cases[] = ['Str::limit', ['日本語のテキストです', 7]];
        $cases[] = ['Str::headline', ['EmailNotificationSent']];
        $cases[] = ['Str::headline', ['email_notification-sent now']];
        $cases[] = ['Str::wordCount', ["Hello, world! It's 3 o'clock", 'o0..9']];


        // PCRE: the patterns where JavaScript's regular expressions mean something else
        $patterns = [
            '/\d+/', '/(\w+)@(\w+)\.com/', '/(?<user>\w+)@(?P<host>[\w.]+)/', "/(?'first'a)(b)?(c)?/", '/^abc$/', '/^abc$/D', '/^\w+$/m',
            '/a.c/', '/a.c/s', '/\s+/', '/\s+/u', '/\w+/u', '/\bcafé\b/u', '/\bcaf/', '/é/', '/./', '/./u', '/^.$/', '/^.$/u',
            '/\Aab/', '/ab\z/', '/ab\Z/', '/a++b/', '/(?>a+)b/', '/a*+a/', '/[[:alpha:]]+/', '/[[:^digit:]]+/', '/[^\s\d]+/', '/[\S]+/',
            '/\x{e9}/u', '/\x41/', '/\p{Lu}\p{Ll}+/u', '/\p{Greek}+/u', '/\pL+/u', '/\QA.B\E+/', '/(a)(?:b)(c)\2/', '/(a)\g{-1}/', '/(?<x>a)\k<x>/',
            '/ a b # comment
               c /x', '/a+?/U', '/a+/U', '/x{,2}/', '/x{2,3}/', '/{/', '/a{/', '/(a|ab)(c|bcd)(d*)/', '/x*/', '/x*|b/', '/(?i)abc/', '/ABC/i', '/ÉCOLE/iu', '/école/i',
            '/\h+/', '/\R/', '/[a-f]+/i', '/\//', '#/path/#', '{a}', '/[\]\-a]+/', '/\bis\b/', '/(?=a)a/', '/(?<=a)b/', '/(?<!a)b/', '/a(?#comment)b/',
            '/(\d)(\d)?(\d)?/', '/\d+(?=€)/u', '/[^a]/u', '/\//', '/a\/b/',
        ];
        $subjects = ['abc', 'ABC', "abc\n", "x\nabc\ny", 'a1b22c333', 'user@example.com, other@site.com', 'aab abb', 'café école', 'ÉCOLE', 'xxbxx', "a\r\nb", '', 'aaab', 'αβγ Δέλτα', '/path/to', 'a{b', 'abcbcd', '12€ 3', 'This is it', '1', '12'];
        foreach ($patterns as $pattern) {
            foreach ($subjects as $subject) {
                $cases[] = ['preg_match', [$pattern, $subject]];
            }
            foreach (['aab abb', 'café école', "x\nabc\ny", 'a1b22c333'] as $subject) {
                $cases[] = ['preg_match', [$pattern, $subject, PREG_OFFSET_CAPTURE]];
                $cases[] = ['preg_match', [$pattern, $subject, PREG_UNMATCHED_AS_NULL]];
                $cases[] = ['preg_match', [$pattern, $subject, 0, 3]];
                $cases[] = ['preg_match_all', [$pattern, $subject]];
                $cases[] = ['preg_match_all', [$pattern, $subject, PREG_SET_ORDER]];
                $cases[] = ['preg_match_all', [$pattern, $subject, PREG_SET_ORDER | PREG_OFFSET_CAPTURE]];
                $cases[] = ['preg_replace', [$pattern, '[$0|\1|${2}|\\\\0|\$1]', $subject]];
                $cases[] = ['preg_replace', [$pattern, '-', $subject, 1]];
                /** Without /u a match can be half of a character: PHP's json_encode() fails on it, the browser has U+FFFD (README's limitations). */
                if (str_contains(substr($pattern, strrpos($pattern, $pattern[0]) ?: 0), 'u') || mb_check_encoding($subject, 'ASCII')) {
                    $cases[] = ['preg_replace_callback', [$pattern, 'json_encode', $subject]];
                }
                $cases[] = ['preg_split', [$pattern, $subject]];
                $cases[] = ['preg_split', [$pattern, $subject, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE]];
                $cases[] = ['preg_split', [$pattern, $subject, 2, PREG_SPLIT_OFFSET_CAPTURE]];
            }
            $cases[] = ['preg_grep', [$pattern, ['a' => 'abc', 'b' => '123', 5 => 'café', 6 => '']]];
            $cases[] = ['preg_grep', [$pattern, ['abc', '123', 'café'], PREG_GREP_INVERT]];
        }
        $cases[] = ['preg_replace', [['/a/', '/b/'], ['b', 'c'], 'ab']];
        $cases[] = ['preg_replace', [['/a/', '/b/'], 'x', ['k' => 'ab', 'l' => 'cd']]];
        $cases[] = ['preg_replace', ['/(a)/', '$11-${1}1-\\11', 'a']];
        $cases[] = ['preg_split', ['//', 'abc']];
        $cases[] = ['preg_split', ['//', 'abc', -1, PREG_SPLIT_NO_EMPTY]];
        $cases[] = ['preg_split', ['//u', 'αβ']];
        $cases[] = ['preg_split', ['/(-)|(\+)/', 'a-b+c', -1, PREG_SPLIT_DELIM_CAPTURE]];
        $cases[] = ['preg_match', ['/b/u', 'αβb', 0, 3]];
        $cases[] = ['preg_match', ['/b/', 'ab', 0, -1]];
        $cases[] = ['preg_match', ['/b/', 'ab', 0, 5]];
        foreach (['Hello.World? (1+1=2) [a] {b} $5 ^x | a-b #c /d \\e <f> !g :h =i', "nul\0byte", ''] as $s) {
            $cases[] = ['preg_quote', [$s]];
            $cases[] = ['preg_quote', [$s, '/']];
            $cases[] = ['preg_quote', [$s, '#']];
        }
        foreach (['/abc', '/a/k', 'abc', '/(a/', '/a)/', '/[a/', '/a{3,1}/', '/\\', '/*a/', '/(?<n>a)(?<n>b)/', '/\ga/', '/\y/'] as $bad) {
            $cases[] = ['preg_match', [$bad, 'abc']];
            $cases[] = ['preg_replace', [$bad, 'x', 'abc']];
            $cases[] = ['preg_split', [$bad, 'abc']];
        }

        return $cases;
    }
}
