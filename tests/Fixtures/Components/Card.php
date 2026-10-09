<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\On;
use Nitro\Component;

/**
 * A child with slots: $slot (the default) where its body goes, $slots['footer'] when there is
 * one. It shows and hides its body itself; the body is its parent's (the parent's state and
 * handlers), rendered here.
 */
class Card extends Component
{
    public string $title = '';

    public bool $open = true;

    /** It listens too: a dispatch ->to() another component must not reach it. */
    public int $heard = 0;

    #[On('noted')]
    public function hear(): void
    {
        $this->heard++;
    }
}
