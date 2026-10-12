<?php

namespace Nitro\Tests\Fixtures\Features;

use Nitro\Component;
use Nitro\Feature;
use Nitro\Tests\Fixtures\Components\Store;
use Throwable;

/** An application's feature that handles a service being down, wherever a #[Server] method meets it. */
class Catcher extends Feature
{
    public function exception(Component $component, string $method, Throwable $exception): bool
    {
        Store::$log[] = 'caught '.class_basename($component).'::'.$method.': '.$exception->getMessage();

        return str_contains($exception->getMessage(), 'down');
    }
}
