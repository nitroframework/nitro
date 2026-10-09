<?php

namespace Nitro\Tests\Fuzz;

/**
 * Random PHP for the differential fuzzer: a method body over four variables ($a, $b, $c, $d) and
 * the property $this->p, written with what Nitro compiles for the browser (operators, casts,
 * array writes, loops, switch, match, closures, the functions of Catalog::FUNCTIONS).
 *
 * The same seed gives the same program. Programs avoid the documented limits (integers of 1e14
 * and more, integral floats such as 2.0 and -0.0, appending after unset() on a list) and anything
 * unbounded: loops have literal bounds, sizes (str_repeat, array_fill, range) are literals.
 */
final class Generator
{
    private const VARS = ['$a', '$b', '$c', '$d'];

    private const STRINGS = ['', '0', 'a', 'abc', '5', '1.5', ' 7', '05', 'A b', '-3', '1e2', 'x;y', '0.0', 'é', 'ünï', 'a,b,c', "it's", '<b>'];

    private const KEYS = ['0', '1', '2', "'k'", "'x'", "'2'", "'05'", '-1', 'true', "''", "'1.5'", 'null'];

    private const FORMATS = ["'%s'", "'%d'", "'%05.2f'", "'%x'", "'%b'", "'%-5s|'", "'%u'", "'%.1e'", "'%%%s'", "'%\'*6s'", "'%+d %s'"];

    /**
     * Functions by their arguments: s string, n number, a array, k key, x anything, F a sprintf
     * format, f1/f2 a closure of one or two parameters, L:a|b one of these literals; ? optional.
     */
    private const FUNCTIONS = [
        'strlen' => ['s'], 'mb_strlen' => ['s'], 'strtolower' => ['s'], 'strtoupper' => ['s'], 'mb_strtoupper' => ['s'],
        'mb_strtolower' => ['s'], 'ucfirst' => ['s'], 'lcfirst' => ['s'], 'ucwords' => ['s'],
        'str_contains' => ['s', 's'], 'str_starts_with' => ['s', 's'], 'str_ends_with' => ['s', 's'],
        'trim' => ['s', '?s'], 'ltrim' => ['s'], 'rtrim' => ['s', '?s'],
        'str_repeat' => ['s', 'L:0|1|2|3'], 'str_pad' => ['s', 'L:0|3|6', '?s', '?L:STR_PAD_LEFT|STR_PAD_BOTH|STR_PAD_RIGHT'],
        'str_replace' => ['x', 'x', 'x'], 'str_ireplace' => ['s', 's', 's'],
        'substr' => ['s', 'n', '?n'], 'mb_substr' => ['s', 'n', '?n'], 'strpos' => ['s', 's', '?n'], 'stripos' => ['s', 's'],
        'strrpos' => ['s', 's'], 'substr_count' => ['s', 's'], 'strrev' => ['s'], 'str_split' => ['s', '?L:1|2|3'], 'nl2br' => ['s'],
        'implode' => ['s', 'a'], 'explode' => ["L:','|';'|' '|'a'", 's', '?n'], 'sprintf' => ['F', 'x', 'x'],
        'number_format' => ['n', '?L:0|1|2', "?L:'.'|','", "?L:','|''|' '"], 'htmlspecialchars' => ['s'], 'strval' => ['x'],
        'abs' => ['n'], 'round' => ['n', '?L:0|1|2|-1'], 'floor' => ['n'], 'ceil' => ['n'], 'intdiv' => ['n', 'n'], 'fmod' => ['n', 'n'],
        'sqrt' => ['n'], 'pow' => ['n', 'L:0|1|2|3|-1|0.5'], 'max' => ['x', '?x'], 'min' => ['x', '?x'],
        'intval' => ['x'], 'floatval' => ['x'], 'boolval' => ['x'],
        'is_numeric' => ['x'], 'is_string' => ['x'], 'is_array' => ['x'], 'is_bool' => ['x'], 'is_null' => ['x'],
        'count' => ['a'], 'array_is_list' => ['a'], 'array_keys' => ['a'], 'array_values' => ['a'], 'array_key_exists' => ['k', 'a'],
        'array_key_first' => ['a'], 'array_key_last' => ['a'], 'in_array' => ['x', 'a', '?L:true|false'], 'array_search' => ['x', 'a', '?L:true'],
        'array_merge' => ['a', 'a'], 'array_filter' => ['a', '?f1'], 'array_map' => ['f1', 'a'], 'array_reduce' => ['a', 'f2', '?x'],
        'array_slice' => ['a', 'n', '?n', '?L:true|false'], 'array_reverse' => ['a', '?L:true'],
        'array_sum' => ['a'], 'array_product' => ['a'], 'array_unique' => ['a'], 'array_column' => ['a', 'k'], 'array_combine' => ['a', 'a'],
        'array_flip' => ['a'], 'array_fill' => ['L:0|1|5', 'L:0|1|3', 'x'], 'array_pad' => ['a', 'L:-4|0|4', 'x'], 'array_chunk' => ['a', 'L:1|2', '?L:true'],
        'array_diff' => ['a', 'a'], 'array_intersect' => ['a', 'a'], 'array_diff_key' => ['a', 'a'], 'array_intersect_key' => ['a', 'a'],
        'array_fill_keys' => ['a', 'x'], 'range' => ["L:0|1|5|'a'", "L:3|0|'e'", '?L:1|2'], 'json_encode' => ['x'], 'json_decode' => ['s', 'L:true'],
    ];

    /** @var list<string> what an expression can read: the variables, $this->p, the loop variables in scope */
    private array $readable = [];

    private int $loops = 0;

    public function __construct(int $seed)
    {
        mt_srand($seed);
    }

    /**
     * @return array{init: list<string>, body: list<string>, return: string}
     */
    public function program(): array
    {
        $this->readable = [...self::VARS, '$this->p'];
        $this->loops = 0;

        $init = array_map(fn (string $var) => "{$var} = {$this->literal(2)};", self::VARS);
        $body = [];

        for ($i = mt_rand(2, 7); $i > 0; $i--) {
            $body[] = $this->statement(2);
        }

        return ['init' => $init, 'body' => $body, 'return' => 'return ['.implode(', ', self::VARS).'];'];
    }

    // ------------------------------------------------------------ statements

    private function statement(int $depth): string
    {
        $kind = $this->weighted([
            'assign' => 28, 'keyed' => 16, 'compound' => 10, 'property' => 6, 'byref' => 10, 'incdec' => 4, 'swap' => 3,
            'if' => $depth > 0 ? 8 : 0, 'foreach' => $depth > 0 ? 7 : 0, 'for' => $depth > 0 ? 4 : 0, 'switch' => $depth > 0 ? 3 : 0,
            'exit' => $this->loops > 0 ? 3 : 0,
        ]);
        $var = $this->pick(self::VARS);

        return match ($kind) {
            'assign' => "{$var} = {$this->expr(3)};",
            'keyed' => $this->pick([
                "{$var}[{$this->key()}] = {$this->expr(2)};",
                "{$var}[] = {$this->expr(2)};",
                "{$var}[{$this->key()}][{$this->key()}] = {$this->expr(2)};",
                "{$var}[{$this->key()}][] = {$this->expr(2)};",
                "{$var}[{$this->pick($this->readable)}] = {$this->expr(2)};",
            ]),
            'compound' => $this->pick([$var, "{$var}[{$this->key()}]"]).' '.$this->pick(['+=', '-=', '*=', '.=', '??=', '%=', '/=']).' '.$this->expr(2).';',
            'property' => $this->pick(['$this->p', "\$this->p[{$this->key()}]", '$this->p[]']).' = '.$this->expr(2).';',
            'byref' => $this->pick([
                $this->pick(['sort', 'rsort', 'asort', 'arsort', 'ksort', 'krsort'])."({$var});",
                $this->pick(['usort', 'uasort', 'uksort'])."({$var}, {$this->closure(2)});",
                "array_push({$var}, {$this->expr(2)});",
                "array_unshift({$var}, {$this->expr(2)});",
                "{$this->pick(self::VARS)} = array_pop({$var});",
                "{$this->pick(self::VARS)} = array_shift({$var});",
                "{$this->pick(self::VARS)} = array_splice({$var}, {$this->number()}".($this->chance(50) ? ", {$this->number()}" : '').');',
            ]),
            'incdec' => $this->pick(["{$var}++;", "{$var}--;", "++{$var};", "--{$var};"]),
            'swap' => $this->pick([
                "[{$var}, {$this->pick(self::VARS)}] = [{$this->expr(1)}, {$this->expr(1)}];",
                "[{$var}, {$this->pick(self::VARS)}] = {$this->expr(2)};",
                "['k' => {$var}] = {$this->expr(2)};",
            ]),
            'if' => "if ({$this->expr(2)}) {\n{$this->block($depth - 1)}}".($this->chance(50) ? " else {\n{$this->block($depth - 1)}}" : ''),
            'foreach' => $this->foreach($depth),
            'for' => $this->for($depth),
            'switch' => $this->switch($depth),
            'exit' => "if ({$this->expr(1)}) {\n".$this->pick(['break', 'continue'])."; }",
        };
    }

    private function block(int $depth): string
    {
        $out = '';

        for ($i = mt_rand(1, 3); $i > 0; $i--) {
            $out .= $this->statement($depth)."\n";
        }

        return $out;
    }

    /** A switch: break and continue in its cases would be its own (PHP warns), so none are generated there. */
    private function switch(int $depth): string
    {
        $loops = $this->loops;
        $this->loops = 0;
        $out = "switch ({$this->expr(2)}) {\ncase {$this->literal(0)}:\n{$this->block($depth - 1)}break;\ncase {$this->literal(0)}:\n{$this->block($depth - 1)}"
            .($this->chance(50) ? "break;\n" : '')."default:\n{$this->block($depth - 1)}}";
        $this->loops = $loops;

        return $out;
    }

    private function foreach(int $depth): string
    {
        $n = ++$this->loops;
        $saved = $this->readable;
        $subject = $this->chance(70) ? $this->pick([...self::VARS, '$this->p']) : $this->arrayLiteral(1);
        $this->readable = [...$this->readable, "\$k{$n}", "\$v{$n}"];
        $out = "foreach ({$subject} as \$k{$n} => \$v{$n}) {\n{$this->block($depth - 1)}}";
        $this->readable = $saved;
        $this->loops--;

        return $out;
    }

    private function for(int $depth): string
    {
        $n = ++$this->loops;
        $saved = $this->readable;
        $this->readable = [...$this->readable, "\$i{$n}"];
        $out = "for (\$i{$n} = 0; \$i{$n} < ".mt_rand(0, 4)."; \$i{$n}++) {\n{$this->block($depth - 1)}}";
        $this->readable = $saved;
        $this->loops--;

        return $out;
    }

    // ------------------------------------------------------------ expressions

    private function expr(int $depth): string
    {
        if ($depth <= 0) {
            return $this->chance(60) ? $this->pick($this->readable) : $this->literal(0);
        }

        $d = $depth - 1;

        return match ($this->weighted(['var' => 15, 'literal' => 10, 'binary' => 20, 'unary' => 5, 'cast' => 6, 'ternary' => 4,
            'index' => 10, 'call' => 22, 'isset' => 4, 'interpolation' => 4, 'match' => 2])) {
            'var' => $this->pick($this->readable),
            'literal' => $this->literal(1),
            'binary' => '('.$this->expr($d).' '.$this->pick(['+', '-', '*', '/', '%', '.', '==', '===', '!=', '!==', '<', '>', '<=', '>=', '<=>', '&&', '||', '??', 'xor']).' '.$this->expr($d).')',
            'unary' => '('.$this->pick(['!', '-', '+']).' '.$this->expr($d).')',
            'cast' => '(('.$this->pick(['int', 'float', 'string', 'bool', 'array']).') '.$this->expr($d).')',
            'ternary' => $this->chance(70) ? "({$this->expr($d)} ? {$this->expr($d)} : {$this->expr($d)})" : "({$this->expr($d)} ?: {$this->expr($d)})",
            'index' => $this->pick($this->readable)."[{$this->key()}]".($this->chance(50) ? " ?? {$this->literal(0)}" : ''),
            'call' => $this->call($d),
            'isset' => $this->pick([
                'isset('.$this->pick($this->readable)."[{$this->key()}])",
                'empty('.$this->expr($d).')',
                'isset('.$this->pick($this->readable).')',
            ]),
            'interpolation' => '"'.$this->pick(['', 'x', '-']).'{'.$this->pick($this->readable).($this->chance(30) ? "[{$this->key()}]" : '').'}'.$this->pick(['', '!', ' y']).'"',
            'match' => "match ({$this->expr($d)}) {\n{$this->literal(0)}, {$this->literal(0)} => {$this->expr($d)},\n{$this->literal(0)} => {$this->expr($d)},\n"
                .($this->chance(80) ? "default => {$this->expr($d)},\n" : '').'}',
        };
    }

    private function call(int $depth): string
    {
        $name = $this->pick(array_keys(self::FUNCTIONS));
        $args = [];

        foreach (self::FUNCTIONS[$name] as $spec) {
            if ($spec[0] === '?') {
                if ($this->chance(50)) {
                    break;
                }

                $spec = substr($spec, 1);
            }

            $args[] = $this->argument($spec, $depth);
        }

        return $name.'('.implode(', ', $args).')';
    }

    private function argument(string $spec, int $depth): string
    {
        if (str_starts_with($spec, 'L:')) {
            return $this->pick(explode('|', substr($spec, 2)));
        }

        /** A value of the wrong type now and then: the error must be the same. */
        if (! in_array($spec, ['F', 'f1', 'f2'], true) && $this->chance(15)) {
            return $this->expr($depth);
        }

        return match ($spec) {
            's' => $this->chance(65) ? var_export($this->pick(self::STRINGS), true) : $this->expr($depth),
            'n' => $this->chance(65) ? $this->number() : $this->expr($depth),
            'a' => $this->chance(45) ? $this->arrayLiteral(1) : $this->pick($this->readable),
            'k' => $this->key(),
            'F' => $this->pick(self::FORMATS),
            'f1' => $this->closure($depth, 1),
            'f2' => $this->closure($depth, 2),
            default => $this->expr($depth),
        };
    }

    private function closure(int $depth, int $parameters = 2): string
    {
        $saved = $this->readable;
        $this->readable = [...$this->readable, '$x', ...($parameters === 2 ? ['$y'] : [])];

        $body = $parameters === 2 && $this->chance(50)
            ? $this->pick(['$x <=> $y', '$y <=> $x', '$x . $y', '$x + $y', '(string) $x <=> (string) $y'])
            : $this->expr(max(1, $depth));
        $this->readable = $saved;

        return 'fn ($x'.($parameters === 2 ? ', $y' : '').') => '.$body;
    }

    // ------------------------------------------------------------ literals

    private function literal(int $depth): string
    {
        return match ($this->weighted(['int' => 3, 'float' => 1, 'string' => 3, 'constant' => 1, 'array' => $depth > 0 ? 2 : 0])) {
            'int' => $this->pick(['0', '1', '2', '3', '-1', '5', '10', '-7', '100', '7']),
            'float' => $this->pick(['0.5', '1.5', '-2.5', '0.1', '3.14159', '2.75', '1e-3']),
            'string' => var_export($this->pick(self::STRINGS), true),
            'constant' => $this->pick(['null', 'true', 'false']),
            'array' => $this->arrayLiteral($depth - 1),
        };
    }

    private function arrayLiteral(int $depth): string
    {
        $items = [];
        $map = $this->chance(50);

        for ($i = mt_rand(0, 4); $i > 0; $i--) {
            $value = $this->literal($depth);
            $items[] = $map && $this->chance(80) ? "{$this->key()} => {$value}" : $value;
        }

        return '['.implode(', ', $items).']';
    }

    private function key(): string
    {
        return $this->pick(self::KEYS);
    }

    private function number(): string
    {
        return $this->pick(['0', '1', '2', '3', '-1', '-2', '5', '1.5', '-0.5', '10']);
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
