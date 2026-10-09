<?php

namespace Nitro\Tests\Fixtures\Parity;

/**
 * Methods exercising the PHP the transpiler accepts. Each runs in PHP and, compiled, in Node;
 * the resulting state and return value must be the same (MethodParityTest).
 */
class Playground
{
    public const LIMIT = 3;

    public const LABELS = ['low' => 'Low', 'high' => 'High'];

    public int $count = 0;

    public string $name = 'Ada';

    public array $items = [3, 1, 2];

    public array $form = ['email' => '', 'tags' => []];

    public array $matrix = [[1, 2], [3, 4]];

    public ?array $selected = null;

    public mixed $value = null;

    public array $log = [];

    public function increment(int $by = 1): int
    {
        $this->count += $by;

        return $this->count;
    }

    public function counters(): array
    {
        $a = $this->count++;
        $b = ++$this->count;
        $c = $this->count--;
        $d = --$this->count;
        $i = 5;
        $j = $i++ + ++$i;

        return [$a, $b, $c, $d, $i, $j, $this->count];
    }

    public function arithmetic(): array
    {
        return [7 / 2, 6 / 3, 7 % 3, -7 % 3, 2 ** 10, 2 ** -1, '5' + 3, '1.5' + 1, 10 - '2', 0.1 + 0.2, intdiv(7, 2), 7 <=> 3, 'a' <=> 'b'];
    }

    public function strings(): array
    {
        $name = $this->name;
        $n = 3;

        return [
            "Hello {$name}!",
            "n={$n}, twice: ".($n * 2),
            'x' . 1.5 . true . null . false,
            strtoupper($name).'|'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            sprintf('%s has %d items (%.1f%%)', $name, count($this->items), 12.345),
            number_format(1234.5, 2),
            ucwords('hello world'),
            implode(', ', array_map(fn ($x) => $x * 10, $this->items)),
            trim('  padded  ').'|',
            str_contains($name, 'd') ? 'has d' : 'no d',
            substr('abcdef', -2),
            json_encode(['a' => 1, 'b' => [1, 2]]),
        ];
    }

    public function arraysAreValues(): array
    {
        $copy = $this->items;
        $copy[] = 4;
        $copy[0] = 'changed';
        $nested = $this->matrix;
        $nested[1][0] = 'X';

        return [$copy, $this->items, $nested, $this->matrix];
    }

    public function nestedWrites(): void
    {
        $this->form['email'] = 'ada@example.com';
        $this->form['tags'][] = 'php';
        $this->form['tags'][] = 'js';
        $this->form['meta']['created']['by'] = $this->name;
        $this->matrix[0][1] *= 10;
        $this->matrix[1][] = 5;
        unset($this->form['tags'][0]);
        $this->items['key'] = 'value';
    }

    public function compoundOnPaths(): array
    {
        $totals = ['a' => 1];
        $totals['a'] += 5;
        $totals['b'] ??= 10;
        $totals['b'] ??= 20;
        $totals['s'] = 'x';
        $totals['s'] .= 'y';
        $totals['n']['deep'] = 1;
        $totals['n']['deep']++;
        $i = 0;
        $list = [10, 20, 30];
        $list[$i++] += 1;

        return [$totals, $list, $i];
    }

    public function stringOffsets(): array
    {
        $s = 'abc';
        $s[1] = 'X';
        $s[-1] = 'Z';
        $s[-9] = 'Q';
        $padded = '';
        $padded[3] = 'x';
        $t = 'ab';
        $rows = ['ab'];
        $rows[0][1] = 'X';

        return [
            $s, $padded, $rows, $t['1'], $t[-1], $t[5] ?? 'none', $t['1x'] ?? 'D', $t['x'] ?? 'D',
            isset($t['1']), isset($t['1.0']), isset($t[' 1']), isset($t['1x']), isset($t[-3]),
        ];
    }

    public function stringOffsetError(int $case): string
    {
        $s = 'abc';

        if ($case === 1) {
            $s[] = 'x';
        } elseif ($case === 2) {
            $s[1] = '';
        } elseif ($case === 3) {
            $s['x'] = 'y';
        } elseif ($case === 4) {
            $s[1][0] = 'x';
        } elseif ($case === 5) {
            $s = $s['x'];
        } else {
            $numbers = [5];
            $numbers[0]['k'] = 1;
        }

        return $s;
    }

    public function looseConversions(): array
    {
        $list = [1];
        $text = "v: {$list}";
        $joined = $list;
        $joined .= '!';
        $yes = true;
        $yes++;
        $no = false;
        $no--;
        $count = 0;
        $number = 5;

        foreach ($number as $item) {
            $count++;
        }

        $false = false;
        $false[] = 1;
        $settings = ['theme' => null];
        $settings['theme'] ??= 'dark';
        $settings['size']['width'] ??= 10;

        return [$text, $joined, $yes, $no, $count, $false, $settings];
    }

    public function evaluationOrder(): array
    {
        $a = 1;
        $a += ($a = 5);
        $list = [1, 2];
        $list[0] += ($list = [10, 20])[1];
        $tie = 7;
        $yes = true;

        return [$a, $list, min($tie, $yes), min($yes, $tie), max($tie, $yes), max($yes, $tie), min($tie, $yes, 3), min([$yes, $tie])];
    }

    public function sortsAsPhpSorts(): array
    {
        $small = [5, 3, 1, 4, 2];
        $large = range(1, 40);
        $keys = array_flip(range(1, 20));
        $mixed = ['10', 9, '9a', 'abc', 1.5, true, null, '', '1e1', 'b'];
        usort($small, fn ($x, $y) => $x + $y);
        usort($large, fn ($x, $y) => ($x * 7 + $y * 3) % 5 - 2);
        uksort($keys, fn ($x, $y) => $x > $y);
        sort($mixed);

        return [$small, $large, array_keys($keys), $mixed];
    }

    public function columns(): array
    {
        $rows = ['a', 5, ['k' => 1, 'i' => 'x'], ['k' => null, 'i' => 1.5], ['k' => 2]];

        return [array_column($rows, null), array_column($rows, null, 'i'), array_column($rows, 'k'), array_column($rows, 'k', 'i')];
    }

    public function ranges(): array
    {
        return [range('0', '3'), range('a', 'e', 2), range(5, 'e'), range('1.5', '3'), range(1, 3, 0.5), range('a', 'c', 1.5), range(3, 1), range('A', 'z', 10)];
    }

    public function functionError(int $case): mixed
    {
        $none = null;
        $text = 'abc';
        $number = 5;

        return match ($case) {
            1 => sort($none),
            2 => array_push($none, 1),
            3 => strlen([]),
            4 => str_repeat('a', '2x'),
            5 => abs(''),
            6 => implode(',', 'x'),
            7 => array_key_exists([], []),
            8 => str_replace(-1, [1], 'x'),
            9 => str_pad('a', 3, ''),
            10 => range(1, 3, 0),
            11 => $text['x'] += 1,
            12 => $number['k'] .= 'x',
            13 => 'x;y' / $none,
            default => array_sum(null),
        };
    }

    public function nullKeys(): array
    {
        $list = [5];
        $key = null;
        $list[$key] = 1;
        $list[] = 2;
        $nested = [];
        $nested[$key]['x'] = 3;
        $nested['y'][$key] = 4;
        $gone = 1;
        unset($gone);
        $unset = [5];
        $unset[$gone] = 6;
        $literal = [$gone => 7, 8];

        return [$list, $nested, $unset, $literal];
    }

    public function loops(): array
    {
        $out = [];

        foreach ($this->items as $i => $item) {
            if ($item === 1) {
                continue;
            }
            $out[] = "{$i}:{$item}";
        }

        for ($i = 0, $j = 10; $i < 5; $i++, $j--) {
            if ($i === 3) {
                break;
            }
            $out[] = $i + $j;
        }

        $n = 0;
        while ($n < 3) {
            $n++;
        }

        do {
            $n += 10;
        } while ($n < 25);

        foreach ($this->matrix as $row) {
            foreach ($row as $cell) {
                if ($cell === 2) {
                    continue 2;
                }
                if ($cell === 4) {
                    break 2;
                }
                $out[] = $cell;
            }
        }

        $out[] = $n;
        $out[] = $item; // foreach variables live on after the loop

        return $out;
    }

    public function switches(mixed $v): string
    {
        switch ($v) {
            case 0:
                $r = 'zero';
                break;
            case 'a':
            case 'b':
                $r = 'letter';
                break;
            case 5:
                $r = 'five';
            case 6:
                $r = ($r ?? '').'+six';
                break;
            default:
                $r = 'other';
        }

        return $r;
    }

    public function matches(mixed $v): string
    {
        return match ($v) {
            1, 2 => 'small',
            '1' => 'string one',
            null => 'null',
            default => 'other',
        };
    }

    public function strictMatch(mixed $v): string
    {
        return match ($v) {
            1 => 'one',
        };
    }

    public function closures(): array
    {
        $factor = 2;
        $double = function ($x) use ($factor) {
            return $x * $factor;
        };
        $triple = fn ($x) => $x * $factor * 1.5;
        $factor = 100; // captured by value: the closures still use 2

        $sorted = $this->items;
        usort($sorted, fn ($a, $b) => $b <=> $a);

        $byName = [['n' => 'b'], ['n' => 'a'], ['n' => 'c']];
        usort($byName, function ($x, $y) {
            return $x['n'] <=> $y['n'];
        });

        return [
            array_map($double, $this->items),
            array_map($triple, $this->items),
            array_values(array_filter($this->items, fn ($x) => $x > 1)),
            array_filter(['a' => 1, 'b' => 2, 'c' => 3], fn ($k) => $k !== 'b', ARRAY_FILTER_USE_KEY),
            array_reduce($this->items, fn ($carry, $x) => $carry + $x, 0),
            $sorted,
            array_column($byName, 'n'),
        ];
    }

    public function byReference(): array
    {
        $list = [5, 3, 9];
        sort($list);
        $popped = array_pop($list);
        array_push($list, 7, 8);
        $shifted = array_shift($list);
        array_unshift($list, 0);
        $assoc = ['b' => 2, 'a' => 1];
        ksort($assoc);
        $removed = array_splice($list, 1, 2);
        rsort($this->items);

        return [$list, $popped, $shifted, $assoc, $removed, $this->items];
    }

    public function existence(): array
    {
        $a = ['x' => ['y' => null, 'z' => 0], 'list' => []];

        return [
            isset($a['x']),
            isset($a['x']['y']),
            isset($a['x']['z']),
            isset($a['missing']['deep']),
            isset($this->form['email']),
            isset($this->selected['id']),
            empty($a['x']['z']),
            empty($a['list']),
            empty($a['nope']),
            $a['missing'] ?? 'default',
            $a['x']['y'] ?? 'null falls through',
            $this->selected['id'] ?? 'no selection',
            $undefined ?? 'undefined falls through',
        ];
    }

    /** [$a, $b] = [$b, $a] and the rest of destructuring: the value first, then each target in order. */
    public function destructuring(): array
    {
        $a = 1;
        $b = 2;
        [$a, $b] = [$b, $a];

        $slots = ['x', 'y', 'z'];
        [$slots[0], $slots[2]] = [$slots[2], $slots[0]];
        [$this->items[0], $this->items[1]] = [$this->items[1], $this->items[0]];

        ['name' => $name, 'age' => $age] = ['age' => 36, 'name' => 'Ada'];
        list(, $second, , $fourth) = [1, 2, 3, 4];
        [[$p, $q], [, $r]] = $this->matrix;
        [$none, $nothing] = null;
        $whole = ([$first] = ['one', 'two']);

        foreach ([[1, 'a'], [2, 'b']] as $pair) {
            [$number, $letter] = $pair;
            $this->log[] = $letter.$number;
        }

        return [$a, $b, $slots, $name, $age, $second, $fourth, $p, $q, $r, $none, $nothing, $whole, $first];
    }

    public function conditionsAndCasts(): array
    {
        $out = [];

        foreach ([0, '0', '', [], 'a', 1.5, null, ' '] as $v) {
            $out[] = $v ? 'truthy' : 'falsy';
        }

        return [
            $out,
            (int) '12abc',
            (float) '1.5e3',
            (string) 1.0,
            (bool) '0',
            (array) 'x',
            $this->count ?: 'fallback',
            $this->name ?: 'fallback',
            ! $this->items,
            $this->count > 0 && $this->name !== '',
            $this->count > 0 || $this->name !== '',
            0 == 'a',
            '1' == '01',
            null == false,
            [1, 2] === [1, 2],
            [1, 2] === [1 => 2, 0 => 1],
        ];
    }

    public function constants(): array
    {
        return [self::LIMIT, static::LABELS, self::LABELS['high'], PHP_EOL, count(self::LABELS)];
    }

    public function callsOtherMethods(): array
    {
        $this->increment(5);
        $this->increment();

        return [$this->label(), $this->count];
    }

    public function label(): string
    {
        return $this->name.' #'.$this->count;
    }

    public function typed(int $id, string $name, ?float $ratio = null, bool $flag = false, array $list = []): array
    {
        return [$id, $name, $ratio, $flag, $list];
    }

    public function divideByZero(): float
    {
        return 1 / 0;
    }

    public function arrayToString(): string
    {
        return 'Value: '.$this->missingKeyLength();
    }

    public function missingKeyLength(): int
    {
        return strlen($this->form['nope'] ?? '');
    }

    public function keysAndOrder(): array
    {
        $a = [3 => 'c', 1 => 'a', 2 => 'b'];
        $a['x'] = 'y';
        $a[] = 'next';
        $b = ['5' => 'five', '05' => 'zero-five'];
        $merged = array_merge($a, [10 => 'ten']);
        $plus = $a + [3 => 'ignored', 99 => 'added'];

        return [$a, array_keys($a), $b, $merged, $plus, array_slice($a, 1, 2), array_slice($a, 1, 2, true)];
    }

    public function spreadAndLiterals(): array
    {
        $base = [1, 2];
        $named = ['a' => 1];

        return [[...$base, 3], [...$base, ...$named, 'b' => 2], [0 => 'x', 'y', 5 => 'z', 'w'], max(...$base)];
    }

    public function interpolationEdgeCases(): array
    {
        $items = ['a' => ['b' => 'deep']];
        $obj = ['name' => 'Ada'];

        return ["Val: {$items['a']['b']}", "Count: {$this->count}", "Float: ".(0.1 + 0.2), "Arr: $obj[name]"];
    }

    /** ->id on what is not an object: Laravel makes PHP's warning an ErrorException; on null it is null. */
    public function propertyOfNonObject(int $case): mixed
    {
        $values = [1 => [1, 2], 2 => ['id' => 5], 3 => 'text', 4 => 5, 5 => null];

        return $values[$case]?->id;
    }
}
