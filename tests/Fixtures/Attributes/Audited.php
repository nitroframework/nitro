<?php

namespace Nitro\Tests\Fixtures\Attributes;

use Attribute;
use Nitro\Component;
use Nitro\FeatureAttribute;
use Nitro\Tests\Fixtures\Components\Store;

/** On a #[Server] method: its calls logged, with the attribute's own argument. */
#[Attribute(Attribute::TARGET_METHOD)]
class Audited extends FeatureAttribute
{
    public function __construct(public string $as = 'audit') {}

    public function calling(Component $component, string $method, array $arguments): void
    {
        Store::$log[] = "{$this->as}: calling {$method}(".implode(',', $arguments).')';
    }

    public function called(Component $component, string $method, mixed $result): void
    {
        Store::$log[] = "{$this->as}: {$method} returned ".json_encode($result);
    }
}
