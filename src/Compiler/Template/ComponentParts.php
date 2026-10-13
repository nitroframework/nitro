<?php

namespace Nitro\Compiler\Template;

use Illuminate\Support\Str;
use Nitro\Compiler\CompileException;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * An anonymous Blade component's parts, as Blade makes them, for BladeInliner: the tag's content
 * as its slots, the view's @props, and the variables and attribute bag the view sees.
 */
final class ComponentParts
{
    public function __construct(private readonly ComponentTags $tags)
    {
    }

    /**
     * A component view's comments and @verbatim blocks set aside, so what reads the view ($slot,
     * $attributes, @props) does not read them: each becomes a placeholder holding its newlines
     * (the lines after it keep their numbers).
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function setAside(string $view): array
    {
        $kept = [];
        $view = (string) preg_replace_callback('/\{\{--.*?--\}\}|(?<!@)@verbatim\b.*?@endverbatim\b/s', function (array $match) use (&$kept) {
            $kept[] = $match[0];

            return "\x00KEPT".(count($kept) - 1).str_repeat("\n", substr_count($match[0], "\n"))."\x00";
        }, $view);

        return [$view, $kept];
    }

    /**
     * The view with what setAside() took out back in place.
     *
     * @param  list<string>  $kept
     */
    public static function putBack(string $view, array $kept): string
    {
        return (string) preg_replace_callback('/\x00KEPT(\d+)\n*\x00/', fn (array $match) => $kept[(int) $match[1]], $view);
    }

    /**
     * A named slot's attributes in the component's view ($footer->attributes, <x-slot:footer
     * class="x">): a bag of its own, printed as $attributes is (AttributeBag), empty for a slot
     * the tag does not give.
     *
     * @param  array<string, array{0: string, 1: int, 2?: string}>  $slots
     * @return array{0: string, 1: array<string, string>} the view, and the variables holding the bags' values
     */
    public function slotAttributes(string $view, array $slots, string $path): array
    {
        preg_match_all('/\$(\w+)\s*->\s*attributes\b/', $view, $used);
        $vars = [];

        foreach (array_unique($used[1]) as $slot) {
            [$attributes, $bound] = $this->tags->attributes($slots[$slot][2] ?? '');
            [, $held] = $this->componentVariables($attributes, $bound, null);
            $codes = [];

            foreach (array_keys($held) as $i => $attribute) {
                $vars["__sa_{$slot}_{$i}"] = $held[$attribute];
                $codes[$attribute] = "\$__sa_{$slot}_{$i}";
            }

            $view = AttributeBag::render($view, $codes, [], $held, $path, $slot);
        }

        return [$view, $vars];
    }

    /** A slot's content, trimmed as Blade trims it: whitespace only (not trim()'s NUL bytes). */
    public static function slotContent(string $content): string
    {
        return trim($content, " \t\n\r\x0B");
    }

    /**
     * The tag's content: its <x-slot> blocks (by name) and the rest (the default slot).
     *
     * @return array{0: array<string, array{0: string, 1: int, 2: string}>, 1: string} by slot: its content, its line, its tag's attributes
     */
    public function namedSlots(string $content, string $component, ?string $file, int $line): array
    {
        $slots = [];
        $rest = '';
        $at = 0;
        $depth = 0;

        while (preg_match('/<\s*\/?\s*x[-:]/', $content, $match, PREG_OFFSET_CAPTURE, $at)) {
            $found = $match[0][1];

            if ($depth === 0 && preg_match(ComponentTags::SLOT, $content, $tagMatch, 0, $found)) {
                if (($tagMatch['boundName'] ?? '') !== '') {
                    throw new CompileException('<x-slot :name> names its slot at run time: name it with a string', $file, $line);
                }

                $slotName = $this->tags->stripQuotes(($tagMatch['inlineName'] ?? '') ?: ($tagMatch['name'] ?? '')) ?: 'slot';

                if (str_contains($slotName, '-') && ($tagMatch['inlineName'] ?? '') !== '') {
                    $slotName = Str::camel($slotName);
                }

                $start = $found + strlen($tagMatch[0]);
                [$inner, $end] = $this->slotContents($content, $start, $file, $line);
                $slots[$slotName] = [self::slotContent($inner), $line + substr_count($content, "\n", 0, $start), $tagMatch['attributes'] ?? ''];
                $rest .= substr($content, $at, $found - $at);
                $at = $end;

                continue;
            }

            if (preg_match(ComponentTags::CLOSING, $content, $tagMatch, 0, $found)) {
                $depth--;
            } elseif (! preg_match(ComponentTags::SELF_CLOSING, $content, $tagMatch, 0, $found) && preg_match(ComponentTags::OPENING, $content, $tagMatch, 0, $found)) {
                $depth++;
            } else {
                $tagMatch = [$match[0][0]];
            }

            $rest .= substr($content, $at, $found - $at + strlen($tagMatch[0]));
            $at = $found + strlen($tagMatch[0]);
        }

        return [$slots, $rest.substr($content, $at)];
    }

    /** @return array{0: string, 1: int} a slot's content, and where the content goes on after its </x-slot> */
    private function slotContents(string $content, int $from, ?string $file, int $line): array
    {
        $depth = 0;
        $at = $from;

        while (preg_match('/<\s*\/?\s*x[-:]/', $content, $match, PREG_OFFSET_CAPTURE, $at)) {
            $found = $match[0][1];

            if (preg_match(ComponentTags::SLOT_CLOSING, $content, $tagMatch, 0, $found)) {
                if ($depth === 0) {
                    return [substr($content, $from, $found - $from), $found + strlen($tagMatch[0])];
                }

                $depth--;
            } elseif (preg_match(ComponentTags::SLOT, $content, $tagMatch, 0, $found)) {
                $depth++;
            } else {
                $tagMatch = [$match[0][0]];
            }

            $at = $found + strlen($tagMatch[0]);
        }

        throw new CompileException('<x-slot> is not closed', $file, $line);
    }

    /**
     * The component's @props (and the view without it: Blade's statement, and the newline after).
     *
     * @return array{0: array<string, ?string>|null, 1: string} prop => its default's code (null: none); null without @props
     */
    public function props(string $view, string $path): array
    {
        if (preg_match('/(?<!@)@aware\s*\(/', $view, $aware, PREG_OFFSET_CAPTURE)) {
            throw new CompileException('@aware reads a parent component at run time: pass the value as an attribute', $path, 1 + substr_count($view, "\n", 0, $aware[0][1]));
        }

        if (! preg_match('/(?<!@)@props\s*\(/', $view, $match, PREG_OFFSET_CAPTURE)) {
            return [null, $view];
        }

        $at = $match[0][1];
        $open = $at + strlen($match[0][0]) - 1;
        $line = 1 + substr_count($view, "\n", 0, $at);
        $close = PhpSource::closing($view, $open, $path, $line);
        $args = PhpSource::arguments(substr($view, $open + 1, $close - $open - 1), '@props', $path, $line);

        if (! ($args[0] ?? null) instanceof Expr\Array_) {
            throw new CompileException('@props takes an array: @props([\'type\' => \'info\', \'message\'])', $path, $line);
        }

        $props = [];

        foreach ($args[0]->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->key === null) {
                if (! $item->value instanceof Scalar\String_) {
                    throw new CompileException('@props names each prop with a string', $path, $line);
                }

                $props[$item->value->value] = null;
            } else {
                if (! $item->key instanceof Scalar\String_) {
                    throw new CompileException('@props names each prop with a string', $path, $line);
                }

                $props[$item->key->value] = PhpSource::print($item->value);
            }
        }

        $end = $close + 1;
        /** Blade's @props is a statement ("?>"): the newline after it goes. Its lines stay (the line numbers of what follows). */
        $removed = substr($view, $at, $end - $at);
        $replacement = str_repeat("\n", substr_count($removed, "\n"));

        if (($view[$end] ?? '') === "\n") {
            $end++;
            $replacement .= "\n";
        } elseif (substr($view, $end, 2) === "\r\n") {
            $end += 2;
            $replacement .= "\n";
        }

        /** The newlines kept for the line numbers are not output: a comment holds them. */
        return [$props, substr($view, 0, $at).($replacement === '' ? '' : '{{--'.$replacement.'--}}').substr($view, $end)];
    }

    /**
     * The component's variables and attribute bag, as Blade's AnonymousComponent and @props
     * make them: every attribute is a variable (its name in camelCase) and in $attributes; @props
     * takes its names (or their kebab-case) out of $attributes, with their defaults, and the
     * variables of the attributes left keep their camelCase names only.
     *
     * @param  array<string, string>  $attributes  name => PHP (Blade's)
     * @param  array<string, true>  $bound
     * @param  array<string, ?string>|null  $props
     * @return array{0: array<string, string>, 1: array<string, string>} variables (name => PHP), and the bag (attribute => PHP, as the bag holds it)
     */
    public function componentVariables(array $attributes, array $bound, ?array $props): array
    {
        $vars = [];

        foreach ($attributes as $attribute => $code) {
            /** nitro:click, x-on:click, ... are attributes only (no variable can be named so). */
            if (preg_match('/^[A-Za-z_]\w*$/', Str::camel($attribute))) {
                $vars[Str::camel($attribute)] = $code;
            }
        }

        /** The bag holds bound strings escaped (BladeCompiler::sanitizeComponentAttribute), static ones as written. */
        $held = [];

        foreach ($attributes as $attribute => $code) {
            /** The value taken once by an arrow function: copied into each check, it would be compiled once per check. */
            $held[$attribute] = isset($bound[$attribute]) ? "(fn (\$__value) => is_string(\$__value) ? htmlspecialchars(\$__value, ENT_QUOTES, 'UTF-8', true) : \$__value)({$code})" : $code;
        }

        if ($props === null) {
            return [$vars, $held];
        }

        $names = [];

        foreach (array_keys($props) as $prop) {
            $names[$prop] = true;
            $names[Str::kebab($prop)] = true;
        }

        $bag = array_filter($held, fn ($attribute) => ! isset($names[$attribute]), ARRAY_FILTER_USE_KEY);

        foreach ($props as $prop => $default) {
            $given = null;

            foreach ($attributes as $attribute => $code) {
                if ($attribute === $prop || $attribute === Str::kebab($prop)) {
                    $given = $code;

                    break;
                }
            }

            if ($given !== null) {
                $vars[$prop] = $default === null ? $given : "(({$given}) ?? ({$default}))";
            } elseif ($default !== null) {
                $vars[$prop] = $default;
            }
        }

        /** Variables named as an attribute left in the bag are unset (Blade's $__defined_vars loop). */
        foreach (array_keys($bag) as $attribute) {
            if (! isset($props[$attribute])) {
                unset($vars[$attribute]);
            }
        }

        return [$vars, $bag];
    }
}
