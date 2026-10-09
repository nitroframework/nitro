<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\Layout;
use Nitro\Attributes\Locked;
use Nitro\Attributes\Navigate;
use Nitro\Attributes\Title;
use Nitro\Component;

/**
 * A client page with route parameters (/chapters/{number}/{part?}, number digits only): a visit
 * renders it in the browser without a request, each parameter in its property of the same name;
 * a full load fills them on the server. $edition is #[Locked], so no route may fill it.
 */
#[Layout('components.layouts.headed')]
#[Title('Chapter')]
#[Navigate('client')]
class Chapter extends Component
{
    public int $number = 1;

    public ?string $part = null;

    #[Locked]
    public string $edition = 'first';

    public bool $read = false;

    public function markRead(): void
    {
        $this->read = true;
    }
}
