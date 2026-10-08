<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Component;

/** A child with mount(): only the server can create it (on a page load, a visit or a #[Server] call). */
class Badge extends Component
{
    public string $text = '';

    public function mount(string $label): void
    {
        $this->text = strtoupper($label);
    }
}
