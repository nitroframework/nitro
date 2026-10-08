<?php

namespace Nitro\Fusion\Tests\Fuzz;

/**
 * Random Blade views for the template fuzzer, in the subset Fusion compiles: elements with static
 * and dynamic attributes (@class, @checked, @disabled), echoes ({{ }}, {!! !!}), @if/@elseif/@else,
 * @unless, @isset, @empty, @foreach (with $loop), @forelse, @for, @switch, @break/@continue, @json.
 * The view reads the properties $a, $b, $c (scalars), $items (a list) and $map (an array with keys),
 * and a random state for them. The same seed gives the same view and state.
 */
final class TemplateGenerator
{
    private const SCALARS = ["0", "1", "7", "-3", "1.5", "''", "'0'", "'abc'", "'<b>x</b>'", "'a & \"b\"'", "'it\\'s'", "'ünï'", 'null', 'true', 'false', "' 7'"];

    private const TEXT = ['Hi', 'a &amp; b', '&lt;tag&gt;', '“q”', 'x', '0', ' ', 'ünï ✓'];

    private const KEYS = ["'k'", "'x'", '0', '1', "'n'"];

    /** @var list<string> what an expression can read here */
    private array $readable = [];

    private int $loops = 0;

    public function __construct(int $seed)
    {
        mt_srand($seed);
    }

    /** @return array{view: string, state: array<string, string>} the view, and each property's value as PHP code */
    public function program(): array
    {
        $this->readable = ['$a', '$b', '$c', '$items', '$map'];
        $this->loops = 0;

        $state = [
            'a' => $this->pick(self::SCALARS),
            'b' => $this->pick(self::SCALARS),
            'c' => $this->pick(self::SCALARS),
            'items' => $this->list(),
            'map' => $this->map(),
        ];

        /** @ right after a letter or digit is text to Blade (an email address): every directive gets a space before it. */
        $view = (string) preg_replace('/(?<=\w)@(?=[a-z])/', ' @', $this->nodes(3));

        return ['view' => '<div class="root">'.$view.'</div>', 'state' => $state];
    }

    // ------------------------------------------------------------ markup

    private function nodes(int $depth): string
    {
        $out = '';

        for ($i = mt_rand(1, 4); $i > 0; $i--) {
            $out .= $this->node($depth).$this->pick(['', '', "\n", ' ', "\n    "]);
        }

        return $out;
    }

    private function node(int $depth): string
    {
        $kind = $this->weighted([
            'text' => 12, 'echo' => 18, 'raw' => 3, 'json' => 2,
            'element' => $depth > 0 ? 18 : 6, 'void' => 4,
            'if' => $depth > 0 ? 8 : 0, 'unless' => $depth > 0 ? 2 : 0, 'isset' => $depth > 0 ? 2 : 0, 'empty' => $depth > 0 ? 2 : 0,
            'foreach' => $depth > 0 ? 7 : 0, 'forelse' => $depth > 0 ? 3 : 0, 'for' => $depth > 0 ? 3 : 0, 'switch' => $depth > 0 ? 2 : 0,
            'exit' => $this->loops > 0 ? 3 : 0,
        ]);
        $d = $depth - 1;

        return match ($kind) {
            'text' => $this->pick(self::TEXT),
            'echo' => '{{ '.$this->expr(2).' }}',
            'raw' => '{!! '.$this->expr(1).' !!}',
            'json' => '@json('.$this->pick($this->readable).')',
            'element' => $this->element($d),
            'void' => $this->pick([
                '<br>',
                '<input type="checkbox" @checked('.$this->expr(1).')>',
                '<input value="{{ '.$this->expr(1).' }}">',
                '<hr class="{{ '.$this->expr(1).' }}">',
            ]),
            'if' => '@if ('.$this->expr(2).')'.$this->nodes($d)
                .($this->chance(40) ? '@elseif ('.$this->expr(2).')'.$this->nodes($d) : '')
                .($this->chance(50) ? '@else '.$this->nodes($d) : '').'@endif',
            'unless' => '@unless ('.$this->expr(2).')'.$this->nodes($d).'@endunless',
            'isset' => '@isset($map['.$this->pick(self::KEYS).'])'.$this->nodes($d).'@endisset',
            'empty' => '@empty('.$this->pick(['$items', '$map', '$a']).')'.$this->nodes($d).'@endempty',
            'foreach' => $this->loop('foreach', $d),
            'forelse' => $this->loop('forelse', $d),
            'for' => $this->loop('for', $d),
            'switch' => '@switch('.$this->pick(['$a', '$b', '$c']).")\n    @case(".$this->pick(self::SCALARS).')'.$this->nodes(0)."@break\n    @case("
                .$this->pick(self::SCALARS).')'.$this->nodes(0)."@break\n    @default".$this->nodes(0).'@endswitch',
            'exit' => $this->pick(['@if ('.$this->expr(1).') @break @endif', '@if ('.$this->expr(1).') @continue @endif', '@break('.$this->expr(1).')', '@continue('.$this->expr(1).')']),
        };
    }

    private function element(int $depth): string
    {
        $tag = $this->pick(['div', 'span', 'p', 'b', 'em', 'section', 'ul']);
        $attributes = '';

        for ($i = mt_rand(0, 2); $i > 0; $i--) {
            $attributes .= ' '.$this->pick([
                'class="static"',
                'class="x {{ '.$this->expr(1).' }}"',
                '@class([\'p\' => '.$this->expr(1).', \'q\', \'r\' => '.$this->expr(1).'])',
                'title="{{ '.$this->expr(2).' }}"',
                'data-n="{{ '.$this->expr(1).' }}"',
                'id="i{{ '.$this->expr(1).' }}"',
            ]);
        }

        $inner = $tag === 'ul' ? '<li>'.$this->nodes($depth).'</li>' : $this->nodes($depth);

        return "<{$tag}{$attributes}>{$inner}</{$tag}>";
    }

    private function loop(string $kind, int $depth): string
    {
        $n = ++$this->loops;
        $saved = $this->readable;
        $source = $this->pick(['$items', '$map', '$items', '['.implode(', ', array_map(fn () => $this->pick(self::SCALARS), range(1, mt_rand(0, 3)))).']']);

        if ($kind === 'for') {
            $this->readable[] = "\$i{$n}";
            $out = "@for (\$i{$n} = 0; \$i{$n} < ".mt_rand(0, 3)."; \$i{$n}++)".$this->nodes($depth).'@endfor';
        } else {
            $this->readable = [...$this->readable, "\$k{$n}", "\$v{$n}", '$loop->index', '$loop->iteration', '$loop->first', '$loop->last', '$loop->count', '$loop->remaining', '$loop->odd', '$loop->depth'];
            $out = $kind === 'foreach'
                ? "@foreach ({$source} as \$k{$n} => \$v{$n})".$this->nodes($depth).'@endforeach'
                : "@forelse ({$source} as \$k{$n} => \$v{$n})".$this->nodes($depth).'@empty '.$this->nodes(0).'@endforelse';
        }

        $this->readable = $saved;
        $this->loops--;

        return $out;
    }

    // ------------------------------------------------------------ expressions (read only)

    private function expr(int $depth): string
    {
        if ($depth <= 0) {
            return $this->chance(65) ? $this->scalarRead() : $this->pick(self::SCALARS);
        }

        $d = $depth - 1;

        return match ($this->weighted(['read' => 20, 'literal' => 8, 'binary' => 18, 'not' => 4, 'ternary' => 6, 'call' => 20, 'isset' => 5, 'cast' => 4])) {
            'read' => $this->scalarRead(),
            'literal' => $this->pick(self::SCALARS),
            'binary' => '('.$this->expr($d).' '.$this->pick(['+', '-', '*', '.', '==', '===', '!=', '<', '>', '<=', '<=>', '&&', '||', '??']).' '.$this->expr($d).')',
            'not' => '!'.$this->expr($d),
            'ternary' => '('.$this->expr($d).' ? '.$this->expr($d).' : '.$this->expr($d).')',
            'call' => $this->pick([
                'strlen('.$this->expr($d).')', 'strtoupper('.$this->expr($d).')', 'ucfirst('.$this->expr($d).')', 'trim('.$this->expr($d).')',
                'count($items)', 'count($map)', 'implode(\', \', $items)', 'in_array('.$this->expr($d).', $items)',
                'str_repeat('.$this->expr($d).', '.mt_rand(0, 2).')', 'substr('.$this->expr($d).', '.mt_rand(-2, 2).')',
                'number_format('.$this->pick(['$a', '1.5', '1234.5', '-3']).', '.mt_rand(0, 2).')', 'json_encode('.$this->pick($this->readable).')',
                'array_key_first($map)', 'max('.$this->expr($d).', '.$this->expr($d).')', 'abs('.$this->pick(['-3', '1.5', '$a']).')',
                'str_contains('.$this->expr($d).', \'a\')', 'e('.$this->expr($d).')',
            ]),
            'isset' => $this->pick(['isset($map['.$this->pick(self::KEYS).'])', 'empty('.$this->pick($this->readable).')', 'isset($a)']),
            'cast' => '(('.$this->pick(['int', 'string', 'bool']).') '.$this->expr($d).')',
        };
    }

    /** A read that is usually a scalar: a property, a loop variable, a key of $map or $items. */
    private function scalarRead(): string
    {
        $scalars = array_values(array_filter($this->readable, fn ($r) => ! in_array($r, ['$items', '$map'], true)));

        return $this->chance(75) ? $this->pick($scalars) : $this->pick(['$map', '$items']).'['.$this->pick(self::KEYS).'] ?? '.$this->pick(self::SCALARS);
    }

    // ------------------------------------------------------------ state

    private function list(): string
    {
        $items = [];

        for ($i = mt_rand(0, 4); $i > 0; $i--) {
            $items[] = $this->chance(85) ? $this->pick(self::SCALARS) : $this->map();
        }

        return '['.implode(', ', $items).']';
    }

    private function map(): string
    {
        $items = [];

        for ($i = mt_rand(0, 3); $i > 0; $i--) {
            $items[] = $this->pick(self::KEYS).' => '.$this->pick(self::SCALARS);
        }

        return '['.implode(', ', $items).']';
    }

    // ------------------------------------------------------------ chance

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

    private function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }

    /** @param  array<string, int>  $weights */
    private function weighted(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $name => $weight) {
            if (($roll -= $weight) <= 0) {
                return $name;
            }
        }

        return array_key_first($weights);
    }
}
