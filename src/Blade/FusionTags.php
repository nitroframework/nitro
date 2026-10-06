<?php

namespace Nitro\Fusion\Blade;

use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Compiler\Template\ChildTag;

/**
 * Blade precompiler: <fusion:counter :start="5" /> becomes a call that renders the component,
 * in any view (a page, a layout, or another component's view, where it is a child).
 *
 * A tag with content passes it as slots (Livewire's, Viewi's): what is between the tags is the
 * default slot ($slot in the child's view), <fusion:slot name="footer">...</fusion:slot> a named
 * one ($slots['footer']). The content renders here, in this view, with this view's variables.
 *
 * Children are numbered by ChildTag, as the browser compiler numbers them, so a child has the
 * same key on both sides.
 *
 * In a Blade view that is not a component's, a tag is an island: fusion:hydrate.visible (.idle,
 * .interaction, .media="...") starts it later, fusion:static never.
 *
 * Text that is not markup is left as it is: a tag written in PHP (an @php block, <?php ?>, an
 * echo, a string in them), in a Blade comment or in @verbatim is not a component.
 */
final class FusionTags
{
    private const FUSION = 'app(\Nitro\Fusion\Fusion::class)';

    /** Where a <fusion: tag is not markup: Blade comments, @verbatim, @php blocks, <?php ?>, echoes. */
    private const NOT_MARKUP = '/\{\{--.*?--\}\}|@verbatim\b.*?@endverbatim\b|@php\b(?!\s*\().*?@endphp\b|<\?php\b.*?(?:\?>|\z)|\{!!.*?!!\}|\{\{.*?\}\}/s';

    public static function compile(string $source): string
    {
        $out = '';
        $cursor = 0;
        $number = 0;
        preg_match_all(self::NOT_MARKUP, $source, $skip, PREG_OFFSET_CAPTURE);
        $skip = array_map(fn (array $match) => [$match[1], $match[1] + strlen($match[0])], $skip[0]);

        while (preg_match('/<\/?fusion:/i', $source, $match, PREG_OFFSET_CAPTURE, $cursor)) {
            $at = $match[0][1];

            foreach ($skip as [$start, $end]) {
                if ($at >= $start && $at < $end) {
                    $out .= substr($source, $cursor, $end - $cursor);
                    $cursor = $end;

                    continue 2;
                }
            }

            $out .= substr($source, $cursor, $at - $cursor);

            if (preg_match('/\G<\/fusion:slot\s*>/i', $source, $end, 0, $at)) {
                $out .= '{!! '.self::FUSION.'->endSlot() !!}';
                $cursor = $at + strlen($end[0]);

                continue;
            }

            if (preg_match('/\G<\/fusion:([a-z0-9][a-z0-9\-.]*)\s*>/i', $source, $end, 0, $at)) {
                $out .= '{!! '.self::FUSION.'->endTag('.var_export(strtolower($end[1]), true).') !!}';
                $cursor = $at + strlen($end[0]);

                continue;
            }

            if (preg_match(substr(ChildTag::SLOT_PATTERN, 0, 1).'\G'.substr(ChildTag::SLOT_PATTERN, 1), $source, $slot, 0, $at)) {
                $out .= '{!! '.self::FUSION.'->startSlot('.var_export($slot[1] !== '' ? $slot[1] : $slot[2], true).') !!}';
                $cursor = $at + strlen($slot[0]);

                continue;
            }

            if (preg_match('/\G<fusion:slot\b/i', $source, $_, 0, $at)) {
                throw new CompileException('<fusion:slot> needs a name: <fusion:slot name="footer">');
            }

            $tag = ChildTag::parse($source, $at, $number++);
            $props = [];

            foreach ($tag->props as $name => $expression) {
                $props[] = var_export($name, true).' => '.$expression;
            }

            $arguments = var_export($tag->name, true).', ['.implode(', ', $props).'], '.($tag->key ?? 'null').', '.$tag->number
                .($tag->hydrate === null ? '' : ', hydrate: '.var_export($tag->hydrate, true));

            $out .= $tag->selfClosing
                ? '{!! '.self::FUSION.'->tag('.$arguments.') !!}'
                : '{!! '.self::FUSION.'->startTag('.$arguments.') !!}';
            $cursor = $at + $tag->length;
        }

        return $out.substr($source, $cursor);
    }
}
