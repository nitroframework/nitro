<?php

namespace Nitro\Blade;

use Nitro\Compiler\CompileException;
use Nitro\Compiler\Template\ChildTag;

/**
 * Blade precompiler: <nitro:counter :start="5" /> becomes a call that renders the component,
 * in any view (a page, a layout, or another component's view, where it is a child).
 *
 * A tag with content passes it as slots (Livewire's, Viewi's): what is between the tags is the
 * default slot ($slot in the child's view), <nitro:slot name="footer">...</nitro:slot> a named
 * one ($slots['footer']). The content renders here, in this view, with this view's variables.
 *
 * Children are numbered by ChildTag, as the browser compiler numbers them, so a child has the
 * same key on both sides.
 *
 * In a Blade view that is not a component's, a tag is an island: nitro:hydrate.visible (.idle,
 * .interaction, .media="...") starts it later, nitro:static never.
 *
 * Text that is not markup is left as it is: a tag written in PHP (an @php block, <?php ?>, an
 * echo, a string in them), in a Blade comment or in @verbatim is not a component.
 */
final class NitroTags
{
    /** What the compiled view calls: a tag with content and its slots, a tag without. */
    private const TAGS = 'app(\Nitro\Rendering\Tags::class)';

    private const PLACEMENT = 'app(\Nitro\Rendering\Placement::class)';

    /** Where a <nitro: tag is not markup: Blade comments, @verbatim, @php blocks, <?php ?>, echoes. */
    private const NOT_MARKUP = '/\{\{--.*?--\}\}|@verbatim\b.*?@endverbatim\b|@php\b(?!\s*\().*?@endphp\b|<\?php\b.*?(?:\?>|\z)|\{!!.*?!!\}|\{\{.*?\}\}/s';

    public static function compile(string $source): string
    {
        $out = '';
        $cursor = 0;
        $number = 0;
        preg_match_all(self::NOT_MARKUP, $source, $skip, PREG_OFFSET_CAPTURE);
        $skip = array_map(fn (array $match) => [$match[1], $match[1] + strlen($match[0])], $skip[0]);

        while (preg_match('/<\/?nitro:/i', $source, $match, PREG_OFFSET_CAPTURE, $cursor)) {
            $at = $match[0][1];

            foreach ($skip as [$start, $end]) {
                if ($at >= $start && $at < $end) {
                    $out .= substr($source, $cursor, $end - $cursor);
                    $cursor = $end;

                    continue 2;
                }
            }

            $out .= substr($source, $cursor, $at - $cursor);

            if (preg_match('/\G<\/nitro:slot\s*>/i', $source, $end, 0, $at)) {
                $out .= '{!! '.self::TAGS.'->endSlot() !!}';
                $cursor = $at + strlen($end[0]);

                continue;
            }

            if (preg_match('/\G<\/nitro:([a-z0-9][a-z0-9\-.]*)\s*>/i', $source, $end, 0, $at)) {
                $out .= '{!! '.self::TAGS.'->end('.var_export(strtolower($end[1]), true).') !!}';
                $cursor = $at + strlen($end[0]);

                continue;
            }

            if (preg_match(substr(ChildTag::SLOT_PATTERN, 0, 1).'\G'.substr(ChildTag::SLOT_PATTERN, 1), $source, $slot, 0, $at)) {
                $out .= '{!! '.self::TAGS.'->startSlot('.var_export($slot[1] !== '' ? $slot[1] : $slot[2], true).') !!}';
                $cursor = $at + strlen($slot[0]);

                continue;
            }

            if (preg_match('/\G<nitro:slot\b/i', $source, $_, 0, $at)) {
                throw new CompileException('<nitro:slot> needs a name: <nitro:slot name="footer">');
            }

            $tag = ChildTag::parse($source, $at, $number++);
            $props = [];

            foreach ($tag->props as $name => $expression) {
                $props[] = var_export($name, true).' => '.$expression;
            }

            $arguments = var_export($tag->name, true).', ['.implode(', ', $props).'], '.($tag->key ?? 'null').', '.$tag->number
                .($tag->hydrate === null ? '' : ', hydrate: '.var_export($tag->hydrate, true));

            $out .= $tag->selfClosing
                ? '{!! '.self::PLACEMENT.'->tag('.$arguments.') !!}'
                : '{!! '.self::TAGS.'->start('.$arguments.') !!}';
            $cursor = $at + $tag->length;
        }

        return $out.substr($source, $cursor);
    }
}
