<?php

namespace Nitro\Tests\Fixtures\Invalid;

use Nitro\Attributes\Navigate;
use Nitro\Component;

/**
 * A client page with mount(), which only the server runs (outside the discovered directories: it would fail the build).
 */
#[Navigate('client')]
class ClientWithMount extends Component
{
    public string $name = '';

    public function mount(): void
    {
        $this->name = 'Ada';
    }
}
