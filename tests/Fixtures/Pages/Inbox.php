<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\Navigate;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;

/**
 * A markup-first page (#[Navigate('markup')]): a visit renders it at once with its defaults, the
 * placeholders showing, and its data fills it in. Its mount() is slow so the wait can be seen;
 * the unread count is deferred, loaded after the data.
 */
#[Navigate('markup')]
class Inbox extends Component
{
    public array $messages = [];

    public ?int $unread = null;

    public string $draft = '';

    public int $sent = 0;

    public function mount(): void
    {
        usleep(400_000);
        $this->messages = ['Hello', 'Welcome'];
        $this->defer('unread', fn () => 3);
    }

    #[Server]
    public function send(): void
    {
        $this->sent++;
    }
}
