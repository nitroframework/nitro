<?php

namespace Nitro\Compiler\Template;

use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Compilers\ComponentTagCompiler;

/**
 * Blade's own reading of <x-...> tags, for BladeInliner: which component a tag names
 * (componentClass(): a class, or an anonymous component's view), and its attributes as the PHP
 * expressions Blade passes (getAttributesFromAttributeString(): 'text'.e($echo).'', a bound
 * expression, true for a bare attribute). The tag patterns are Blade's (compileOpeningTags,
 * compileSelfClosingTags, compileSlots), anchored where the inliner reads.
 */
final class ComponentTags extends ComponentTagCompiler
{
    private const ATTRIBUTES = '
        (?<attributes>
            (?:
                \s+
                (?:
                    (?: @(?:class)(\( (?: (?>[^()]+) | (?-1) )* \)) )
                    |
                    (?: @(?:style)(\( (?: (?>[^()]+) | (?-1) )* \)) )
                    |
                    (?: \{\{\s*\$attributes(?:[^}]+?)?\s*\}\} )
                    |
                    (?: (\:\$)(\w+) )
                    |
                    (?:
                        [\w\-:.@%]+
                        (
                            =
                            (?: "[^"]*" | \'[^\']*\' | [^\'"=<>]+ )
                        )?
                    )
                )
            )*
            \s*
        )';

    /** <x-name ...> (Blade's compileOpeningTags), at the offset. */
    public const OPENING = '/\G<\s*x[-\:]([\w\-\:\.]*)'.self::ATTRIBUTES.'(?<![\/=\-])>/x';

    /** <x-name ... /> (Blade's compileSelfClosingTags), at the offset. */
    public const SELF_CLOSING = '/\G<\s*x[-\:]([\w\-\:\.]*)\s*'.self::ATTRIBUTES.'\/>/x';

    /** </x-name> (Blade's compileClosingTags), at the offset. */
    public const CLOSING = '/\G<\/\s*x[-\:]([\w\-\:\.]*)\s*>/';

    /** <x-slot:name>, <x-slot name="name"> (Blade's compileSlots), at the offset. */
    public const SLOT = '/\G<\s*x[\-\:]slot(?:\:(?<inlineName>\w+(?:-\w+)*))?(?:\s+name=(?<name>("[^"]+"|\'[^\']+\'|[^\s>]+)))?(?:\s+\:name=(?<boundName>("[^"]+"|\'[^\']+\'|[^\s>]+)))?'.self::ATTRIBUTES.'(?<![\/=\-])>/x';

    public const SLOT_CLOSING = '/\G<\/\s*x[\-\:]slot[^>]*>/';

    public function __construct(BladeCompiler $blade)
    {
        parent::__construct($blade->getClassComponentAliases(), $blade->getClassComponentNamespaces(), $blade);
    }

    /**
     * A tag's attributes as Blade passes them to the component: name => PHP expression, and
     * which of them are bound (:name="$expression").
     *
     * @return array{0: array<string, string>, 1: array<string, true>}
     */
    public function attributes(string $attributeString): array
    {
        $this->boundAttributes = [];
        $attributes = $this->getAttributesFromAttributeString($attributeString);

        return [$attributes, $this->boundAttributes];
    }
}
