<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\Description;
use Nitro\Attributes\Layout;
use Nitro\Attributes\Title;
use Nitro\Component;

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
