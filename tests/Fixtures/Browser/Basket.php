<?php

namespace Nitro\Tests\Fixtures\Browser;

use Nitro\Attributes\Browser;

/** A #[Browser] class calling another one. */
#[Browser]
final class Basket
{
    public const FREE_DELIVERY_FROM = 3000;

    /** @param  list<array{price: int, quantity: int}>  $lines */
    public static function summary(array $lines): string
    {
        $total = Money::total($lines);
        $delivery = self::delivery($total);

        return Money::format($total + $delivery).($delivery === 0 ? ' (free delivery)' : ' (incl. '.Money::format($delivery).' delivery)');
    }

    public static function delivery(int $total): int
    {
        return $total === 0 || $total >= self::FREE_DELIVERY_FROM ? 0 : 450;
    }
}
