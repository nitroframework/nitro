<?php

namespace Nitro\Tests\Fixtures\Invalid;

use Nitro\Attributes\Server;
use Nitro\Component;

/** #[Server] on rendered(): a request after every render. */
class ServerRendered extends Component
{
    #[Server]
    public function rendered(): void
    {
    }
}
