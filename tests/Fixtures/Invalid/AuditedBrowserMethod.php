<?php

namespace Nitro\Fusion\Tests\Fixtures\Invalid;

use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\Fixtures\Attributes\Audited;

/** An attribute that runs around #[Server] calls, on a browser method: it would never run. */
class AuditedBrowserMethod extends Component
{
    #[Audited]
    public function save(): void {}
}
