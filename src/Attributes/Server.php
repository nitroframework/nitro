<?php

namespace Nitro\Fusion\Attributes;

use Attribute;

/**
 * A method that runs on the server. It is never compiled to JavaScript: the browser calls it
 * through Fusion's endpoint with the component's state, and receives the new state.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Server
{
}
