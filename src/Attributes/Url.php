<?php

namespace Nitro\Fusion\Attributes;

use Attribute;

/**
 * A property kept in the query string, as Livewire's #[Url]: the page reads it from the URL when
 * the component is created, and the browser writes it back as it changes, so a search, a sort
 * or a page survives a reload and can be shared.
 *
 *   #[Url] public string $search = '';               ?search=...
 *   #[Url(as: 'q')] public string $search = '';      ?q=...
 *   #[Url(history: true)] public int $page = 1;      each change is a history entry (back goes back)
 *   #[Url(keep: true)] public string $sort = 'name'; in the URL even at its default
 *   #[Url(except: 1)] public int $page = 1;          left out when it is 1
 *
 * A value equal to the property's declared default is left out of the URL (unless keep), or the
 * value given as except. A value from the URL that does not fit the property's type is ignored.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Url
{
    public function __construct(
        public readonly ?string $as = null,
        public readonly bool $history = false,
        public readonly bool $keep = false,
        public readonly mixed $except = new NoValue,
    ) {
    }

    public function hasExcept(): bool
    {
        return ! $this->except instanceof NoValue;
    }
}
