<?php

namespace Nitro\Fusion\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Symfony\Component\HttpFoundation\Response render(string $component, array $props = [])
 * @method static void share(string|array $key, mixed $value = null)
 * @method static mixed shared(?string $key = null, mixed $default = null)
 * @method static string mount(string $name, array $props = [], array $slots = [])
 * @method static mixed config(string $key, mixed $default = null)
 *
 * @see \Nitro\Fusion\Fusion
 */
class Fusion extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \Nitro\Fusion\Fusion::class;
    }
}
