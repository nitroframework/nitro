<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\Description;
use Nitro\Fusion\Attributes\Layout;
use Nitro\Fusion\Attributes\Title;
use Nitro\Fusion\Component;

/**
 * A page whose <head> comes from its attributes, and kept out of search engines.
 */
#[Layout('components.layouts.headed')]
#[Title('Notice')]
#[Description('A private notice')]
class Notice extends Component
{
    public function mount(): void
    {
        $this->head()->noindex();
    }
}
