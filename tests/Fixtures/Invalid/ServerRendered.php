<?php

namespace Nitro\Fusion\Tests\Fixtures\Invalid;

use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;

/** #[Server] on rendered(): a request after every render. */
class ServerRendered extends Component
{
    #[Server]
    public function rendered(): void
    {
    }
}
