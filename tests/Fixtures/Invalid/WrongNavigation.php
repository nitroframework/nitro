<?php

namespace Nitro\Tests\Fixtures\Invalid;

use Nitro\Attributes\Navigate;
use Nitro\Component;

/**
 * #[Navigate] with a mode that does not exist (outside the discovered directories: it would fail the build).
 */
#[Navigate('fast')]
class WrongNavigation extends Component
{
}
