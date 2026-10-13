<?php

namespace Nitro\Compiler\Template;

use Nitro\Compiler\CompileException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * A Blade component's $attributes in its inlined view (BladeInliner), worked out as Laravel's
 * ComponentAttributeBag works it out: {{ $attributes }} with ->merge([...]), ->class([...]),
 * ->only([...]) and ->except([...]) prints the attributes; ->get() and ->has() read one. Each
 * attribute is left out when its value is null or false (@nitroAttr).
 *
 * @internal
 */
final class AttributeBag
{
    /**
     * {{ $attributes... }} in a tag: the attributes it prints, worked out from the bag (as
     * ComponentAttributeBag), and ->get() / ->has() elsewhere.
     *
     * The component's $attributes, or a named slot's ($footer->attributes: <x-slot:footer class="x">,
     * one bag the same way).
     *
     * @param  array<string, string>  $codes  attribute => the variable holding its value ($__fa0)
     * @param  array<string, string>  $nitro  nitro: attribute => as written
     * @param  array<string, string>  $bag  attribute => PHP
     * @param  string|null  $slot  a named slot's bag: its name; null for the component's
     */
    public static function render(string $view, array $codes, array $nitro, array $bag, string $path, ?string $slot = null): string
    {
        $entries = [];

        foreach (array_keys($bag) as $attribute) {
            $entries[$attribute] = isset($nitro[$attribute]) ? ['nitro' => $nitro[$attribute]] : ['code' => $codes[$attribute]];
        }

        $variable = $slot === null ? '\$attributes\b' : '\$'.preg_quote($slot, '/').'\s*->\s*attributes\b';

        $view = preg_replace_callback('/\{\{\s*('.$variable.'(?:[^{}]|\{[^{}]*\})*?)\s*\}\}/', function (array $match) use ($entries, $path, $view, $variable, $slot) {
            /** {{ $attributes->get('x') }} echoes one attribute's value: the pass below reads it. */
            if (preg_match('/^'.$variable.'\s*->\s*(get|has)\s*\(/', $match[1]) === 1) {
                return $match[0];
            }

            $bag = self::evaluate($match[1], $entries, $path, $view, $match[0], $slot);

            return self::print($bag);
        }, $view);

        return preg_replace_callback('/'.$variable.'\s*->\s*(get|has)\s*\(((?:[^()]|\([^()]*\))*)\)/', function (array $found) use ($entries, $path, $view) {
            $match = array_column($found, 0);
            $line = 1 + substr_count($view, "\n", 0, (int) $found[0][1]);
            $args = PhpSource::arguments($match[2], '$attributes->'.$match[1].'()', $path, $line);
            $key = $args[0] ?? null;

            if (! $key instanceof Scalar\String_) {
                throw new CompileException("\$attributes->{$match[1]}() takes the attribute's name as a string", $path, $line);
            }

            $entry = $entries[$key->value] ?? null;

            if ($match[1] === 'has') {
                return $entry === null ? 'false' : 'true';
            }

            if ($entry === null) {
                return isset($args[1]) ? PhpSource::print($args[1]) : 'null';
            }

            return $entry['code'] ?? self::nitroValue($entry['nitro']);
        }, $view, flags: PREG_OFFSET_CAPTURE);
    }

    /**
     * A nitro: attribute's value, as the bag gives it from the attribute as written
     * (nitro:model="form.email" gives 'form.email'; nitro:lazy alone gives true), as PHP.
     */
    private static function nitroValue(string $written): string
    {
        if (preg_match('/=\s*(?:"([^"]*)"|\'([^\']*)\')\s*$/', $written, $value) !== 1) {
            return 'true';
        }

        return var_export($value[2] ?? $value[1], true);
    }

    /**
     * $attributes and its methods, run here on the attributes' names (their values are code).
     *
     * @param  array<string, array{code?: string, nitro?: string}>  $entries
     * @return array<string, array{code?: string, nitro?: string}>
     */
    private static function evaluate(string $php, array $entries, string $path, string $view, string $echo, ?string $slot = null): array
    {
        $line = 1 + substr_count($view, "\n", 0, (int) strpos($view, $echo));
        $calls = [];

        for ($expr = PhpSource::expression($php, $path, $line); $expr instanceof Expr\MethodCall; $expr = $expr->var) {
            array_unshift($calls, $expr);
        }

        $bag = $slot === null
            ? $expr instanceof Expr\Variable && $expr->name === 'attributes'
            : $expr instanceof Expr\PropertyFetch && $expr->var instanceof Expr\Variable && $expr->var->name === $slot && $expr->name instanceof Node\Identifier && $expr->name->toString() === 'attributes';

        if (! $bag) {
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
     * @param  array<string, array{code?: string, nitro?: string}>  $entries
     * @param  array<string, string>  $defaults  attribute => PHP
     * @return array<string, array{code?: string, nitro?: string}>
     */
    private static function merge(array $entries, array $defaults): array
    {
        $escaped = [];

        foreach ($defaults as $attribute => $code) {
            /** The value taken once by an arrow function: copied into each check, it would be compiled once per check. */
            $escaped[$attribute] = "(fn (\$__value) => is_null(\$__value) || is_bool(\$__value) ? \$__value : htmlspecialchars((string) \$__value, ENT_QUOTES, 'UTF-8', true))({$code})";
        }

        $appendable = [];
        $others = [];

        foreach ($entries as $attribute => $entry) {
            if ($attribute === 'class' || $attribute === 'style') {
                $value = $entry['code'] ?? var_export(html_entity_decode(trim($entry['nitro']), ENT_QUOTES), true);
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
     * @param  array<string, array{code?: string, nitro?: string}>  $entries
     */
    private static function print(array $entries): string
    {
        $out = '';

        foreach ($entries as $attribute => $entry) {
            if (isset($entry['nitro'])) {
                /** The caller's, compiled where the tag is: its handler reads the variables there (ComponentCalls). */
                $out .= " @nitroCallAttribute({$attribute})";

                continue;
            }

            $out .= ' @nitroAttr('.var_export($attribute, true).', '.$entry['code'].')';
        }

        return $out;
    }
}
