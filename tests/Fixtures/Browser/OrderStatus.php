<?php

namespace Nitro\Tests\Fixtures\Browser;

use Nitro\Attributes\Browser;

/** A #[Browser] backed enum: its cases, methods on a case, a static method and a constant naming a case. */
#[Browser]
enum OrderStatus: string
{
    case Draft = 'draft';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';

    public const DEFAULT = self::Draft;

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Paid => 'Paid',
            self::Shipped => 'On its way',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Shipped, self::Cancelled], true);
    }

    /** The case after this one, the last staying where it is. */
    public function next(): self
    {
        return match ($this) {
            self::Draft => self::Paid,
            self::Paid => self::Shipped,
            default => $this,
        };
    }

    public function badge(): string
    {
        return $this->label().($this->isFinal() ? ' ✓' : '').' ('.$this->value.', '.$this->name.')';
    }

    /** @return list<self> */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => ! $status->isFinal()));
    }

    /** Not reached by any browser code: never compiled. */
    public function audit(): string
    {
        return now()->toDateString();
    }
}
