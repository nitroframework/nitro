<?php

namespace Nitro\Fusion\Rendering;

use RuntimeException;

/**
 * A component renders exactly one root element; Fusion marks it with the component's id, name
 * and state.
 */
final class RootElement
{
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /**
     * @param  array<string, string>  $attributes  added to the root's start tag (values escaped here)
     */
    public static function mark(string $html, array $attributes, string $component): string
    {
        self::assertSingleRoot($html, $component);

        if (! preg_match('/<([a-zA-Z][a-zA-Z0-9\-]*)/', $html, $match, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException("Fusion component [{$component}] must render a single root element.");
        }

        $insert = '';

        foreach ($attributes as $name => $value) {
            $insert .= ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
        }

        $at = $match[1][1] + strlen($match[1][0]);

        return substr($html, 0, $at).$insert.substr($html, $at);
    }

    /** One element at the top level, with only whitespace and comments around it. */
    public static function assertSingleRoot(string $html, string $component): void
    {
        $depth = 0;
        $roots = 0;
        $offset = 0;
        $length = strlen($html);

        while ($offset < $length) {
            if (! preg_match('/<!--.*?-->|<(\/?)([a-zA-Z][a-zA-Z0-9\-:]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $html, $match, PREG_OFFSET_CAPTURE, $offset)) {
                $tail = substr($html, $offset);

                if ($depth === 0 && trim($tail) !== '') {
                    throw self::notSingle($component);
                }

                break;
            }

            $between = substr($html, $offset, $match[0][1] - $offset);

            if ($depth === 0 && trim($between) !== '') {
                throw self::notSingle($component);
            }

            $offset = $match[0][1] + strlen($match[0][0]);

            if (str_starts_with($match[0][0], '<!--')) {
                continue;
            }

            $tag = strtolower($match[2][0]);
            $closing = $match[1][0] === '/';
            $selfClosing = str_ends_with(rtrim($match[3][0]), '/') || in_array($tag, self::VOID, true);

            if ($closing) {
                $depth--;

                continue;
            }

            if ($depth === 0 && ++$roots > 1) {
                throw self::notSingle($component);
            }

            if (in_array($tag, ['script', 'style', 'textarea'], true)) {
                $end = stripos($html, "</{$tag}", $offset);
                $offset = $end === false ? $length : $end;
                $depth++;

                continue;
            }

            if (! $selfClosing) {
                $depth++;
            }
        }

        if ($roots !== 1) {
            throw self::notSingle($component);
        }
    }

    private static function notSingle(string $component): RuntimeException
    {
        return new RuntimeException("Fusion component [{$component}] must render a single root element (wrap its view in one element).");
    }
}
