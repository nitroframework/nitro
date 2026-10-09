<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\View;
use Nitro\Component;

/** The events component (Talk) with its Svelte view. */
#[View(svelte: 'svelte-talk')]
class SvelteTalk extends Component
{
    use Talk;
}
