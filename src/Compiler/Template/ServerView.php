<?php

namespace Nitro\Compiler\Template;

/**
 * A compiled view as the server runs it (View\ServerViews caches it): its node tree, the tree the
 * browser gets, and its expressions' PHP twins, by the same index; and so for each Blade
 * component's view it calls (ComponentViews), by id.
 *
 *   [
 *       'tree' => ['t' => 'r', 'h' => [...]],
 *       'expressions' => function (array $__data): array {
 *           extract($__data, EXTR_SKIP);
 *
 *           return [
 *               fn ($item = null, $loop = null) => e($item),
 *               null,
 *           ];
 *       },
 *       'calls' => ['v1a2b...' => ['tree' => [...], 'expressions' => function (array $__data): array {...}]],
 *   ]
 *
 * The renderer calls 'expressions' once per render, bound to what the view's $this is (the
 * component; a Blade component's view: its inputs), with the view's data; each twin takes the
 * template's variables in scope where it is ($item, $loop, ...), by position (those not set yet
 * where it is called: null). Null: the browser's only (a handler).
 *
 * @internal
 */
final class ServerView
{
    /** The view's PHP: an array expression, for a file that returns it. */
    public static function source(TemplateCompiler $view, ComponentViews $views): string
    {
        $called = [];
        $pending = $view->calls();

        while ($pending !== []) {
            $id = array_shift($pending);

            if (isset($called[$id])) {
                continue;
            }

            $called[$id] = self::view($views->tree($id), $views->phpExpressions($id), '        ');
            array_push($pending, ...$views->callsOf($id));
        }

        ksort($called);
        $calls = '';

        foreach ($called as $id => $code) {
            $calls .= '        '.var_export($id, true).' => '.$code.",\n";
        }

        return "[\n".self::fields($view->tree(), $view->phpExpressions(), '    ')
            ."    'calls' => [\n".$calls."    ],\n]";
    }

    /** @param  list<array{locals: list<string>, php: string}|null>  $twins */
    private static function view(array $tree, array $twins, string $indent): string
    {
        return "[\n".self::fields($tree, $twins, $indent.'    ').$indent.']';
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  list<array{locals: list<string>, php: string}|null>  $twins
     */
    private static function fields(array $tree, array $twins, string $indent): string
    {
        $expressions = '';

        foreach ($twins as $index => $twin) {
            $code = $twin === null ? 'null' : 'fn ('.implode(', ', array_map(fn (string $local) => '$'.$local.' = null', $twin['locals'])).') => '.$twin['php'];
            $expressions .= "{$indent}        /* {$index} */ {$code},\n";
        }

        return "{$indent}'tree' => ".var_export($tree, true).",\n"
            ."{$indent}'expressions' => function (array \$__data): array {\n"
            ."{$indent}    extract(\$__data, EXTR_SKIP);\n\n"
            ."{$indent}    return [\n{$expressions}{$indent}    ];\n"
            ."{$indent}},\n";
    }
}
