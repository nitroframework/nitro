<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\Server;
use Nitro\Component;

/** A child whose destroyed() runs on the server: it notes its leaving in the session. */
class LifecycleChild extends Component
{
    public string $label = 'child';

    #[Server]
    public function destroyed(): void
    {
        session()->push('lifecycle.gone', $this->label);
    }
}
