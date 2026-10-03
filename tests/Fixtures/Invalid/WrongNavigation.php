<?php

namespace Nitro\Fusion\Tests\Fixtures\Invalid;

use Nitro\Fusion\Attributes\Navigate;
use Nitro\Fusion\Component;

/**
 * #[Navigate] with a mode that does not exist (outside the discovered directories: it would fail the build).
 */
#[Navigate('fast')]
class WrongNavigation extends Component
{
}
