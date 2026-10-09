<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Component;

/** A child with mount(): only the server can create it (on a page load, a visit or a #[Server] call). */
class Badge extends Component
{
    public string $text = '';

    public function mount(string $label): void
    {
        $this->text = strtoupper($label);
    }
}
