<?php

namespace Nitro\Fusion\Compiler\Template\Parts;

/**
 * What a Fusion view may contain, in one place: the Blade directives the template compiler
 * accepts (every other one is a compile error naming it), and the HTML rules it follows so the
 * tree it makes is the DOM the browser's parser would make from the server's HTML.
 *
 * Blade: DIRECTIVES and its groups. HTML: VOID, RAW_TEXT, RCDATA, LEADING_NEWLINE, as the HTML
 * standard has them. Fusion: BladeInliner's marker directives (fusionScope, fusionSlot, ...),
 * which only BladeInliner writes, and the cap on a template loop's iterations.
 *
 * @internal
 */
final class Syntax
{
    /** Directives compiled here. Every other Blade directive is refused. */
    public const DIRECTIVES = [
        'if', 'elseif', 'else', 'endif', 'unless', 'endunless', 'isset', 'endisset', 'empty', 'endempty',
        'foreach', 'endforeach', 'forelse', 'endforelse', 'for', 'endfor', 'while', 'endwhile',
        'break', 'continue', 'switch', 'case', 'default', 'endswitch', 'error', 'enderror',
        'class', 'style', 'checked', 'selected', 'disabled', 'readonly', 'required', 'csrf', 'method', 'json',
        'teleport', 'endteleport', 'deferred', 'placeholder', 'enddeferred',
        /** A view page's declaration (ViewPages): read when its class is made, nothing to render. */
        'page',
        /** BladeInliner's, around an inlined @include or <x-component> (not written in views). */
        'fusionScope', 'endfusionScope', 'fusionSlot', 'endfusionSlot', 'fusionFrame', 'endfusionFrame', 'fusionAttr',
    ];

    /** BladeInliner's directives that sit inside a tag (a component's attributes). */
    public const INLINED_TAG_DIRECTIVES = ['fusionAttr', 'fusionFrame', 'endfusionFrame'];

    /** Directives that write an attribute of the tag they are in. */
    public const ATTRIBUTE_DIRECTIVES = ['class', 'style', 'checked', 'selected', 'disabled', 'readonly', 'required'];

    /** Directives Blade compiles to a statement ending in "?>": PHP drops a newline right after it. */
    public const KEEPS_NEWLINE = ['class', 'style', 'fusionScope', 'fusionSlot', 'endfusionSlot'];

    public const CONDITIONALS = ['if', 'unless', 'isset', 'empty', 'error'];

    /** Elements without content or end tag. */
    public const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /** Raw text: no tags inside, no character references decoded (an echo stays escaped). */
    public const RAW_TEXT = ['script', 'style'];

    /** Text only, character references decoded. */
    public const RCDATA = ['textarea', 'title'];

    /** The HTML parser drops a newline right after their start tag. */
    public const LEADING_NEWLINE = ['pre', 'textarea', 'listing'];

    /** Largest loop a render runs (@for, @while): a template has no way to stop an endless one. */
    public const MAX_ITERATIONS = 100000;
}
