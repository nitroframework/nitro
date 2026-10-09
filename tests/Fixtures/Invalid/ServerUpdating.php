<?php

namespace Nitro\Tests\Fixtures\Invalid;

use Nitro\Attributes\Server;
use Nitro\Component;

/** #[Server] on an updating hook: it runs before the change, which cannot wait for a request. */
class ServerUpdating extends Component
{
    public string $email = '';

    #[Server]
    public function updatingEmail(string $value): void
    {
    }
}
