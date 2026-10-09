<?php

namespace Nitro\Tests\Fixtures\Attributes;

use Attribute;
use Nitro\Component;
use Nitro\FeatureAttribute;
use Nitro\Tests\Fixtures\Components\Store;

/** On a property: the browser's value, trimmed and its spaces collapsed, when the browser changed it. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Squish extends FeatureAttribute
{
    public function updated(Component $component, array $changed): bool
    {
        $property = (string) $this->target();
        $component->{$property} = preg_replace('/\s+/', ' ', trim($component->{$property}));
        Store::$log[] = "squish {$property}";

        return true;
    }
}
