<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\Html;
use Nitro\Attributes\Server;
use Nitro\Component;

/** #[Html]: a body made on the server from the state, as rendered Markdown or highlighted code are. */
class Excerpt extends Component
{
    /** How often body() ran in this request. */
    public static int $made = 0;

    public string $text = 'Hello <world>';

    #[Html]
    public function body(): string
    {
        self::$made++;

        return '<p class="body">'.e($this->text).'</p>';
    }

    #[Server]
    public function shout(): void
    {
        $this->text = strtoupper($this->text);
    }

    #[Server]
    public function ping(): string
    {
        return 'pong';
    }
}
