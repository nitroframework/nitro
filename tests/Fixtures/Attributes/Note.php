<?php

namespace Nitro\Fusion\Tests\Fixtures\Attributes;

use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Attributes\Validate;
use Nitro\Fusion\Component;

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
