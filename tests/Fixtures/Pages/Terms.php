<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\Description;
use Nitro\Attributes\Layout;
use Nitro\Attributes\Navigate;
use Nitro\Attributes\Title;
use Nitro\Component;

/**
 * A client page (#[Navigate('client')]): text and state of its own, nothing from the server. A
 * visit renders it in the browser without a request, its title and head worked out at build time.
 */
#[Layout('components.layouts.headed')]
#[Title('Terms')]
#[Description('The terms of the Nitro test app')]
#[Navigate('client')]
class Terms extends Component
{
    public bool $agreed = false;

    public function agree(): void
    {
        $this->agreed = true;
    }
}
