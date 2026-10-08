<?php

namespace Nitro\Fusion\Tests\Fixtures\Invalid;

use Nitro\Fusion\Attributes\Title;
use Nitro\Fusion\Component;

/**
 * A component with a page's #[Title] (outside the discovered directories: it would fail the build).
 */
#[Title('Not a page')]
class TitledComponent extends Component
{
}
