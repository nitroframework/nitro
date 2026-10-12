<?php

namespace Nitro\Tests\Fixtures\Features;

use Closure;
use Nitro\Component;
use Nitro\Feature;
use Nitro\Tests\Fixtures\Components\Store;

/** An application's feature around #[Server] methods: logs around each, and answers a draft's save itself, without the method. */
class Around extends Feature
{
    public function call(Component $component, string $method, array $arguments, Closure $run): mixed
    {
        if ($method === 'save' && ($component->note ?? null) === 'draft') {
            Store::$log[] = 'around: answered';

            return 'kept as a draft';
        }

        Store::$log[] = "around: before {$method}";
        $result = $run();
        Store::$log[] = "around: after {$method}";

        return $result;
    }
}
