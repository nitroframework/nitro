<?php

namespace Nitro\Tests\Fixtures\Invalid;

use Nitro\Attributes\Cached;
use Nitro\Component;

/** #[Cached] on a browser method: it runs where there is no cache to keep its result in. */
class CachedBrowserMethod extends Component
{
    #[Cached]
    public function total(): int
    {
        return 1;
    }
}
