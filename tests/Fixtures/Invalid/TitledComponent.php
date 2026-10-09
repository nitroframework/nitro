<?php

namespace Nitro\Tests\Fixtures\Invalid;

use Nitro\Attributes\Title;
use Nitro\Component;

/**
 * A component with a page's #[Title] (outside the discovered directories: it would fail the build).
 */
#[Title('Not a page')]
class TitledComponent extends Component
{
}
