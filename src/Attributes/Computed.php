<?php

namespace Nitro\Fusion\Attributes;

use Attribute;

/**
 * A value worked out from the component's state, read as a property: `$this->total` in the view
 * or in a method calls total() once and keeps the result until the state changes (in the
 * browser) or for the rest of the render (on the server). It runs in the browser like any other
 * method, so it has no parameters and is not #[Server]; it is not part of the state.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Computed
{
}
