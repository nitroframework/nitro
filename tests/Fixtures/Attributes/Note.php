<?php

namespace Nitro\Tests\Fixtures\Attributes;

use Nitro\Attributes\Server;
use Nitro\Attributes\Validate;
use Nitro\Component;

/** A component with attributes that work as features, on its class, a property and a #[Server] method (FeatureAttributeTest). */
#[Stamped]
class Note extends Component
{
    /** Squished before it is validated: "  ab   c " fits, as "ab c". */
    #[Squish]
    #[Validate('max:5')]
    public string $title = '';

    public string $body = '';

    #[Server]
    #[Audited(as: 'audit')]
    public function publish(int $copies): string
    {
        return "{$this->title} x{$copies}";
    }

    #[Server]
    public function draft(): void {}
}
