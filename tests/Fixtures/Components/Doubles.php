<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

/** A trait's methods compile with the component that uses it. */
trait Doubles
{
    public function doubled(): int
    {
        return $this->count * 2;
    }
}
