<?php

namespace Nitro\Fusion\Attributes;

use Attribute;

/**
 * Runs the method when an event is dispatched ($this->dispatch('saved')), in the browser or,
 * for a #[Server] method, on the server.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class On
{
    public function __construct(public readonly string $event)
    {
    }
}
