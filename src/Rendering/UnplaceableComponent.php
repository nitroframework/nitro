<?php

namespace Nitro\Fusion\Rendering;

use InvalidArgumentException;

/**
 * <fusion:dynamic-component :is="..."> naming what cannot be placed: no component, a page, or not
 * a name at all. From the server's own data, a mistake to fix; from a state the browser sent (a
 * #[Server] call that renders the view), the request's fault (Fusion::childrenOf()).
 */
final class UnplaceableComponent extends InvalidArgumentException
{
}
