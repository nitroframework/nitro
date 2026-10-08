<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\On;
use Nitro\Fusion\Attributes\Server;

/**
 * Events between components whatever their views: BladeTalk, VueTalk, ReactTalk and SolidTalk use
 * it, and the browser tests' "events between engines" scenario has each one send, every way, and
 * the others hear it. Each keeps what it heard, as "event by sender".
 */
trait Talk
{
    /** Who it is: the page passes it. */
    public string $engine = '';

    /** The one it whispers to (->to()): the next on the page. */
    public string $next = '';

    /** @var list<string> */
    public array $heard = [];

    /** From a browser method: dispatched in the browser. */
    public function shout(): void
    {
        $this->dispatch('shouted', from: $this->engine);
    }

    /** From a #[Server] method: dispatched with its answer. */
    #[Server]
    public function announce(): void
    {
        $this->dispatch('announced', from: $this->engine);
    }

    /** To one component only. */
    public function whisper(): void
    {
        $this->dispatch('whispered', from: $this->engine)->to($this->next);
    }

    #[On('shouted')]
    public function heardShout(string $from): void
    {
        $this->heard[] = "shouted by {$from}";
    }

    #[On('announced')]
    public function heardAnnouncement(string $from): void
    {
        $this->heard[] = "announced by {$from}";
    }

    #[On('whispered')]
    public function heardWhisper(string $from): void
    {
        $this->heard[] = "whispered by {$from}";
    }
}
