<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Component;

/** The events component (Talk) with its Svelte view. */
#[View(svelte: 'svelte-talk')]
class SvelteTalk extends Component
{
    use Talk;
}
