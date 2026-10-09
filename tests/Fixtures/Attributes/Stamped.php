<?php

namespace Nitro\Tests\Fixtures\Attributes;

use Attribute;
use Nitro\Component;
use Nitro\FeatureAttribute;
use Nitro\Tests\Fixtures\Components\Store;

/** On the class: every hook that runs wherever it is, logged; it hears every call and every change. */
#[Attribute(Attribute::TARGET_CLASS)]
class Stamped extends FeatureAttribute
{
    public function boot(Component $component): void
    {
        Store::$log[] = 'stamped boot '.var_export($this->target(), true);
    }

    public function mounting(Component $component): void
    {
        Store::$log[] = 'stamped mounting';
    }

    public function hydrate(Component $component): void
    {
        Store::$log[] = 'stamped hydrate';
    }

    public function updated(Component $component, array $changed): bool
    {
        Store::$log[] = 'stamped updated '.implode(',', $changed);

        return true;
    }

    public function calling(Component $component, string $method, array $arguments): void
    {
        Store::$log[] = "stamped calling {$method}";
    }

    public function dehydrate(Component $component): void
    {
        Store::$log[] = 'stamped dehydrate';
    }
}
