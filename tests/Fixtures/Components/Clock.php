<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\Computed;
use Nitro\Attributes\Server;
use Nitro\Component;

/**
 * nitro:poll (a browser method and a #[Server] one), nitro:init, nitro:confirm and #[Computed].
 */
class Clock extends Component
{
    public int $ticks = 0;

    public int $synced = 0;

    public bool $polling = true;

    public bool $ready = false;

    public string $shout = '';

    public function tick(): void
    {
        $this->ticks++;
    }

    public function start(): void
    {
        $this->ready = true;
    }

    /** Read as $this->label in the view and in shoutLabel(). */
    #[Computed]
    public function label(): string
    {
        return $this->ticks.' ticks';
    }

    public function shoutLabel(): void
    {
        $this->shout = strtoupper($this->label);
    }

    #[Server]
    public function sync(): void
    {
        $this->synced++;
    }
}
