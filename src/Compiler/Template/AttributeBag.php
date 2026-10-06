<?php

namespace Nitro\Fusion\Compiler\Template;

use Nitro\Fusion\Compiler\CompileException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * A Blade component's $attributes in its inlined view (BladeInliner), worked out as Laravel's
 * ComponentAttributeBag works it out: {{ $attributes }} with ->merge([...]), ->class([...]),
 * ->only([...]) and ->except([...]) prints the attributes; ->get() and ->has() read one. Each
 * attribute is left out when its value is null or false (@fusionAttr).
 *
 * @internal
 */
final class AttributeBag
{
    /**
     * {{ $attributes... }} in a tag: the attributes it prints, worked out from the bag (as
     * ComponentAttributeBag), and ->get() / ->has() elsewhere.
     *
     * @param  array<string, string>  $codes  attribute => the variable holding its value ($__fa0)
     * @param  array<string, string>  $fusion  fusion: attribute => as written
     * @param  array<string, string>  $bag  attribute => PHP
     */
    public static function render(string $view, array $codes, array $fusion, array $bag, int $frame, string $path): string
    {
        $entries = [];

        foreach (array_keys($bag) as $attribute) {
            $entries[$attribute] = isset($fusion[$attribute]) ? ['fusion' => $fusion[$attribute]] : ['code' => $codes[$attribute]];
        }

        $view = preg_replace_callback('/\{\{\s*(\$attributes\b(?:[^{}]|\{[^{}]*\})*?)\s*\}\}/', function (array $m) use ($entries, $frame, $path, $view) {
            $bag = self::evaluate($m[1], $entries, $path, $view, $m[0]);

            return self::print($bag, $frame);
        }, $view);

        return preg_replace_callback('/\$attributes\s*->\s*(get|has)\s*\(((?:[^()]|\([^()]*\))*)\)/', function (array $found) use ($entries, $path, $view) {
            $m = array_column($found, 0);
            $line = 1 + substr_count($view, "\n", 0, $found[0][1]);
            $args = PhpSource::arguments($m[2], '$attributes->'.$m[1].'()', $path, $line);
            $key = $args[0] ?? null;

            if (! $key instanceof Scalar\String_) {
                throw new CompileException("\$attributes->{$m[1]}() takes the attribute's name as a string", $path, $line);
            }

            $entry = $entries[$key->value] ?? null;

            if ($m[1] === 'has') {
                return $entry === null ? 'false' : 'true';
            }

            if ($entry === null) {
                return isset($args[1]) ? PhpSource::print($args[1]) : 'null';
            }

            return $entry['code'] ?? var_export(html_entity_decode(trim($entry['fusion']), ENT_QUOTES), true);
        }, $view, flags: PREG_OFFSET_CAPTURE);
    }

    /**
     * $attributes and its methods, run here on the attributes' names (their values are code).
     *
     * @param  array<string, array{code?: string, fusion?: string}>  $entries
     * @return array<string, array{code?: string, fusion?: string}>
     */
    private static function evaluate(string $php, array $entries, string $path, string $view, string $echo): array
    {
        $line = 1 + substr_count($view, "\n", 0, (int) strpos($view, $echo));
        $calls = [];

        for ($expr = PhpSource::expression($php, $path, $line); $expr instanceof Expr\MethodCall; $expr = $expr->var) {
            array_unshift($calls, $expr);
        }

        if (! $expr instanceof Expr\Variable || $expr->name !== 'attributes') {
            throw new CompileException("{{ {$php} }} is not a use of \$attributes the browser can render", $path, $line);
        }

        foreach ($calls as $call) {
            $method = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
            $args = array_map(fn ($arg) => $arg->value, $call->getArgs());

            $entries = match ($method) {
                'merge' => self::merge($entries, PhpSource::literalArray($args[0] ?? new Expr\Array_(), '$attributes->merge()', $path, $line)),
                'class' => self::merge($entries, ['class' => self::cssClasses($args[0] ?? null)]),
                'only' => array_intersect_key($entries, array_flip(self::names($args[0] ?? null, $path, $line))),
                'except' => array_diff_key($entries, array_flip(self::names($args[0] ?? null, $path, $line))),
                default => throw new CompileException("\$attributes->{$method}() is not supported in the browser (merge, class, only, except, get, has are)", $path, $line),
            };
        }

        return $entries;
    }

    /**
     * ComponentAttributeBag::merge(): defaults (escaped) first, the tag's attributes over them;
     * class and style are appended to their defaults.
     *
     * @param  array<string, array{code?: string, fusion?: string}>  $entries
     * @param  array<string, string>  $defaults  attribute => PHP
     * @return array<string, array{code?: string, fusion?: string}>
     */
    private static function merge(array $entries, array $defaults): array
    {
        $escaped = [];

        foreach ($defaults as $attribute => $code) {
            $escaped[$attribute] = "(is_null({$code}) || is_bool({$code}) ? ({$code}) : htmlspecialchars((string) ({$code}), ENT_QUOTES, 'UTF-8', true))";
        }

        $appendable = [];
        $others = [];

        foreach ($entries as $attribute => $entry) {
            if ($attribute === 'class' || $attribute === 'style') {
                $value = $entry['code'] ?? var_export(html_entity_decode(trim($entry['fusion']), ENT_QUOTES), true);
                $value = $attribute === 'style' ? "(str_ends_with((string) ({$value}), ';') ? ({$value}) : ({$value}).';')" : $value;
                $default = $escaped[$attribute] ?? "''";
                $appendable[$attribute] = ['code' => "implode(' ', array_unique(array_filter([{$default}, {$value}])))"];
            } else {
                $others[$attribute] = $entry;
            }
        }

        return array_merge(array_map(fn (string $code) => ['code' => $code], $escaped), $appendable, $others);
    }

    /** Arr::toCssClasses() of a literal list, as PHP: [$class => $when, 'always'] => "always when-true". */
    private static function cssClasses(?Expr $list): string
    {
        $items = $list instanceof Expr\Array_ ? $list->items : ($list === null ? [] : [new Node\ArrayItem($list)]);
        $parts = [];

        foreach ($items as $item) {
            if ($item === null) {
                continue;
            }

            $parts[] = $item->key === null || $item->key instanceof Scalar\Int_
                ? PhpSource::print($item->value)
                : '('.PhpSource::print($item->value).' ? '.PhpSource::print($item->key).' : null)';
        }

        return "implode(' ', array_filter([".implode(', ', $parts).'], fn ($c) => $c !== null))';
    }

    /** @return list<string> names from ->only() / ->except(): a string or a literal list of them */
    private static function names(?Expr $arg, string $path, int $line): array
    {
        $items = $arg instanceof Expr\Array_ ? array_map(fn ($i) => $i?->value, $arg->items) : [$arg];
        $names = [];

        foreach ($items as $item) {
            if (! $item instanceof Scalar\String_) {
                throw new CompileException('->only() and ->except() take attribute names as strings', $path, $line);
            }

            $names[] = $item->value;
        }

        return $names;
    }

    /**
     * The attributes as the bag prints them (ComponentAttributeBag::__toString): a null or false
     * value leaves the attribute out, true writes its name (x-data and wire: an empty value).
     *
     * @param  array<string, array{code?: string, fusion?: string}>  $entries
     */
    private static function print(array $entries, int $frame): string
    {
        $out = '';

        foreach ($entries as $attribute => $entry) {
            if (isset($entry['fusion'])) {
                /** Written where the tag is: its handler reads the variables there. */
                $out .= " @fusionFrame({$frame}) {$entry['fusion']} @endfusionFrame";

                continue;
            }

            $out .= ' @fusionAttr('.var_export($attribute, true).', '.$entry['code'].')';
        }

        return $out;
    }
}
