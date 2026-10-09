<?php

namespace Nitro\Support;

/**
 * HTML's rules for elements, as the HTML standard has them, for what reads markup: the template
 * compiler (Compiler\Template) and the server's renderer (Rendering\RootElement).
 */
final class Html
{
    /** Void elements: no content, no end tag. */
    public const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /** Raw text: no tags inside, no character references decoded (an echo stays escaped). */
    public const RAW_TEXT = ['script', 'style'];

    /** Text only, character references decoded. */
    public const RCDATA = ['textarea', 'title'];

    /** The HTML parser drops a newline right after their start tag. */
    public const LEADING_NEWLINE = ['pre', 'textarea', 'listing'];

    /** Elements whose content is text, not markup (raw text and RCDATA). */
    public static function holdsText(string $tag): bool
    {
        return in_array(strtolower($tag), [...self::RAW_TEXT, ...self::RCDATA], true);
    }
}
