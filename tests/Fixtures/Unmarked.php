<?php

namespace Nitro\Tests\Fixtures;

/** An enum without #[Browser]: the browser cannot use it. */
enum Unmarked: string
{
    case One = 'one';
}
