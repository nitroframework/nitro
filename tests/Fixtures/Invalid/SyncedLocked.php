<?php

namespace Nitro\Tests\Fixtures\Invalid;

use Nitro\Attributes\Locked;
use Nitro\Attributes\Sync;
use Nitro\Store;

/** #[Sync] on a #[Locked] property: only the server sets it. */
class SyncedLocked extends Store
{
    #[Locked]
    #[Sync]
    public string $owner = '';
}
