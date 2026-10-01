<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\Locked;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;

/**
 * Merge props (RenderController::timeline()): posts from a paginator that load more
 * ($loadMore), tags merged on their id, settings merged key by key.
 */
class Timeline extends Component
{
    /** Locked: what loads more reaches the server through the signed partial reloads. */
    #[Locked]
    public array $posts = [];

    #[Locked]
    public array $tags = [];

    #[Locked]
    public array $settings = [];

    public int $touched = 0;

    #[Server]
    public function touch(): void
    {
        $this->touched++;
    }
}
