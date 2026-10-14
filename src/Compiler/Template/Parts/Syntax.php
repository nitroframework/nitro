<?php

namespace Nitro\Compiler\Template\Parts;

use Nitro\Compiler\Globals;

/**
 * What a Nitro view may contain, in one place: the Blade directives the template compiler
 * accepts (every other one is a compile error naming it).
 *
 * Blade: DIRECTIVES and its groups (Nitro's own: Globals::DIRECTIVES). HTML: the elements' rules
 * are Support\Html's, where an element can be is Nesting's. Nitro: BladeInliner's marker
 * directives (nitroScope, nitroSlot, ...), which only BladeInliner writes, and the cap on a
 * template loop's iterations.
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
        'class', 'style', 'checked', 'selected', 'disabled', 'readonly', 'required', 'csrf', 'method', 'json', 'lang', 'choice', 'use',
        ...Globals::DIRECTIVES,
        /** BladeInliner's, around an inlined @include and a Blade component's view and slots (not written in views). */
        'nitroScope', 'endnitroScope', 'nitroSlot', 'endnitroSlot', 'nitroAttr', 'nitroEach', 'endnitroEach', 'nitroTrim', 'endnitroTrim',
        /** BladeInliner's, for a Blade component's call (ComponentCalls) and its view. */
        'nitroCall', 'nitroCallAttribute',
    ];

    /** BladeInliner's directives that sit inside a tag (a component's attributes). */
    public const INLINED_TAG_DIRECTIVES = ['nitroAttr', 'nitroCallAttribute'];

    /** Directives that write an attribute of the tag they are in. */
    public const ATTRIBUTE_DIRECTIVES = ['class', 'style', 'checked', 'selected', 'disabled', 'readonly', 'required'];

    /** Directives Blade compiles to a statement ending in "?>": PHP drops a newline right after it. */
    public const KEEPS_NEWLINE = ['class', 'style', 'nitroScope', 'nitroSlot', 'endnitroSlot', 'nitroTrim', 'endnitroTrim'];

    public const CONDITIONALS = ['if', 'unless', 'isset', 'empty', 'error'];

    /** Largest loop a render runs (@for, @while): a template has no way to stop an endless one. */
    public const MAX_ITERATIONS = 100000;
}
