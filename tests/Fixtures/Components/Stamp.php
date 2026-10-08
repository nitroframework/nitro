<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;

/**
 * A child with a #[Server] method and no mount(): made by the server for a page it renders or
 * visits, or made in the browser (a row added there), which then takes its state from the server.
 */
class Stamp extends Component
{
    public string $label = '';

    public string $by = '';

    public string $note = '';

    #[Server]
    public function stamp(): void
    {
        $this->by = "server:{$this->label}:{$this->note}";
    }
}
