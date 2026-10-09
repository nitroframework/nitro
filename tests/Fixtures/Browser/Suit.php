<?php

namespace Nitro\Tests\Fixtures\Browser;

use Nitro\Attributes\Browser;

/** A #[Browser] pure enum: cases with names, no values. */
#[Browser]
enum Suit
{
    case Hearts;
    case Spades;

    public function color(): string
    {
        return $this === self::Hearts ? 'Red' : 'Black';
    }
}
