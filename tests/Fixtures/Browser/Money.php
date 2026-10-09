<?php

namespace Nitro\Tests\Fixtures\Browser;

use Nitro\Attributes\Browser;

/** A #[Browser] class: money in pence, formatted the same on both sides. */
#[Browser]
final class Money
{
    public const CURRENCY = '£';

    public const RATES = ['standard' => 20, 'reduced' => 5, 'zero' => 0];

    private const THOUSANDS = ',';

    public static function format(int $pence, string $symbol = self::CURRENCY): string
    {
        $sign = $pence < 0 ? '-' : '';

        return $sign.$symbol.self::digits(intdiv(abs($pence), 100)).'.'.str_pad((string) (abs($pence) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function vat(int $pence, string $rate = 'standard'): int
    {
        return (int) round($pence * (static::RATES[$rate] ?? 0) / 100);
    }

    /** @param  list<array{price: int, quantity: int}>  $lines */
    public static function total(array $lines): int
    {
        return array_sum(array_map(fn (array $line) => $line['price'] * $line['quantity'], $lines));
    }

    private static function digits(int $whole): string
    {
        return number_format($whole, 0, '.', self::THOUSANDS);
    }

    /** Not static: refused in the browser. */
    public function instance(): string
    {
        return 'no';
    }
}
