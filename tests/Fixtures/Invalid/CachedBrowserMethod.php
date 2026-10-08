<?php

namespace Nitro\Fusion\Tests\Fixtures\Invalid;

use Nitro\Fusion\Attributes\Cached;
use Nitro\Fusion\Component;

/** #[Cached] on a browser method: it runs where there is no cache to keep its result in. */
class CachedBrowserMethod extends Component
{
    #[Cached]
    public function total(): int
    {
        return 1;
    }
}
