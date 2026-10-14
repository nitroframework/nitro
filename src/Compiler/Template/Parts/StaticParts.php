<?php

namespace Nitro\Compiler\Template\Parts;

use Nitro\Support\Protocol;

/**
 * Clone mode (config nitro.rendering = 'clone', the default): the largest static parts of a
 * view's tree are marked (s: 1), so the browser copies them instead of creating them element by
 * element. Nitro's, an optimisation only: the DOM is the same either way. In create mode
 * (nitro.rendering = 'create') nothing is marked, and the browser creates every element.
 *
 * A part is static when nothing in it depends on data or needs the runtime: an element whose
 * attributes are all plain text (no {{ }}, no @class / @checked, no nitro: attribute: those are
 * read or bound by the runtime), whose children are plain text, comments and static elements, and
 * which is not a form control, <script> or <template> (their state and behaviour are not what a
 * copy of the element carries). Only the outermost static element of a part is marked; what is
 * inside it is copied with it.
 *
 * The component's root element is never marked (the runtime renders it on its own path, keeping
 * its runtime attributes); the parts inside it are, and so are those in a child tag's slots.
 *
 * In the browser (runtime/view/render.js, cloneStatic): the first time a marked part renders it
 * is made as create mode makes it, then kept; every later render is a copy of that one.
 *
 * @internal
 */
final class StaticParts
{
    /** Elements never copied: form controls (their value and checkedness), <script> (a copy does not run), <template>. */
    private const NEVER = ['input', 'textarea', 'select', 'option', 'script', 'template'];

    /**
     * The tree with its static parts marked.
     *
     * @param  array<string, mixed>  $tree  the root node ({t: "r"})
     * @return array<string, mixed>
     */
    public static function mark(array $tree): array
    {
        /** The component's root element: rendered on its own path; the parts inside it are marked. */
        foreach ($tree['h'] ?? [] as $i => $root) {
            $tree['h'][$i] = self::inside($root);
        }

        return $tree;
    }

    /** @param  array<string, mixed>  $node */
    private static function walk(array $node): array
    {
        if (($node['t'] ?? '') === 't' && self::isStatic($node)) {
            $node['s'] = 1;

            return $node;
        }

        return self::inside($node);
    }

    /** The node with the parts under it (its children, a child tag's slots) marked. */
    private static function inside(array $node): array
    {
        foreach ($node['h'] ?? [] as $i => $child) {
            $node['h'][$i] = self::walk($child);
        }

        foreach ($node['slots'] ?? [] as $name => $slot) {
            foreach ($slot['h'] ?? [] as $i => $child) {
                $node['slots'][$name]['h'][$i] = self::walk($child);
            }
        }

        return $node;
    }

    /** @param  array<string, mixed>  $node */
    private static function isStatic(array $node): bool
    {
        return match ($node['t'] ?? '') {
            'x' => ! isset($node['e']),
            'm' => true,
            't' => ! in_array(strtolower((string) $node['c']), self::NEVER, true)
                && self::staticAttributes($node['a'] ?? [])
                && array_reduce($node['h'] ?? [], fn (bool $all, array $child) => $all && self::isStatic($child), true),
            default => false,
        };
    }

    /** @param  list<array<string, mixed>>  $attributes */
    private static function staticAttributes(array $attributes): bool
    {
        foreach ($attributes as $attribute) {
            /** d: a Blade component's caller's nitro: attribute (its handler, its model), rendered with the caller. */
            if (isset($attribute['d']) || isset($attribute['on']) || isset($attribute['when']) || str_starts_with((string) $attribute['c'], Protocol::namespace())) {
                return false;
            }

            foreach ($attribute['h'] ?? [] as $part) {
                if (isset($part['e'])) {
                    return false;
                }
            }
        }

        return true;
    }
}
