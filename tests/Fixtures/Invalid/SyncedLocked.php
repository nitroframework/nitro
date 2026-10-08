<?php

namespace Nitro\Fusion\Tests\Fixtures\Invalid;

use Nitro\Fusion\Attributes\Locked;
use Nitro\Fusion\Attributes\Sync;
use Nitro\Fusion\Store;

/** #[Sync] on a #[Locked] property: only the server sets it. */
class SyncedLocked extends Store
{
    #[Locked]
    #[Sync]
    public string $owner = '';
}
