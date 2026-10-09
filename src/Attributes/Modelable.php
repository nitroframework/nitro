<?php

namespace Nitro\Attributes;

use Attribute;

/**
 * The property a parent binds with nitro:model on this component's tag, as Livewire's
 * #[Modelable]: it starts as the parent's value, follows it, and writes back to it.
 *
 *   class SearchBox extends Component { #[Modelable] public string $value = ''; }
 *   <nitro:search-box nitro:model="query" />
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Modelable
{
}
