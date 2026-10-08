<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\Description;
use Nitro\Fusion\Attributes\Layout;
use Nitro\Fusion\Attributes\Navigate;
use Nitro\Fusion\Attributes\Title;
use Nitro\Fusion\Component;

/**
 * A client page (#[Navigate('client')]): text and state of its own, nothing from the server. A
 * visit renders it in the browser without a request, its title and head worked out at build time.
 */
#[Layout('components.layouts.headed')]
#[Title('Terms')]
#[Description('The terms of the Fusion test app')]
#[Navigate('client')]
class Terms extends Component
{
    public bool $agreed = false;

    public function agree(): void
    {
        $this->agreed = true;
    }
}
