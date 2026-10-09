<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\View;
use Nitro\Component;

/** The parity component (Parity) with its Svelte view. */
#[View(svelte: 'svelte-parity')]
class SvelteParity extends Component
{
    use Parity;
}
