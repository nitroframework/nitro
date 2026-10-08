<?php

namespace Nitro\Fusion\Tests\Fixtures\Invalid;

use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;

/** #[Server] on an updating hook: it runs before the change, which cannot wait for a request. */
class ServerUpdating extends Component
{
    public string $email = '';

    #[Server]
    public function updatingEmail(string $value): void
    {
    }
}
