<?php

namespace Nitro\Fusion\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Nitro\Fusion\Once once(\Closure $value)
 * @method static \Nitro\Fusion\Once shareOnce(string $key, \Closure $value)
 * @method static void flash(string|array $key, mixed $value = null)
 * @method static mixed flashed(?string $key = null, mixed $default = null)
 * @method static void share(string|array $key, mixed $value = null)
 * @method static mixed shared(?string $key = null, mixed $default = null)
 * @method static \Nitro\Fusion\Store store(string $class)
 * @method static void expire(string ...$tags)
 * @method static \Nitro\Fusion\Browser\Browser browser()
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
