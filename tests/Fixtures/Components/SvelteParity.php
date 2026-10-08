<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Component;

/** The parity component (Parity) with its Svelte view. */
#[View(svelte: 'svelte-parity')]
class SvelteParity extends Component
{
    use Parity;
}
