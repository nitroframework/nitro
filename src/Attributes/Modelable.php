<?php

namespace Nitro\Fusion\Attributes;

use Attribute;

/**
 * The property a parent binds with fusion:model on this component's tag, as Livewire's
 * #[Modelable]: it starts as the parent's value, follows it, and writes back to it.
 *
 *   class SearchBox extends Component { #[Modelable] public string $value = ''; }
 *   <fusion:search-box fusion:model="query" />
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Modelable
{
}
