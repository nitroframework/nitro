<?php

namespace Nitro\Fusion;

use Closure;

/**
 * A page property loaded after the page is shown (Fusion::defer()).
 */
final class Deferred
{
    public function __construct(public readonly Closure $value)
    {
    }
}
