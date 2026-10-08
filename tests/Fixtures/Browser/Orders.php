<?php

namespace Nitro\Fusion\Tests\Fixtures\Browser;

/** Browser methods using #[Browser] enums, as a component's would (BrowserEnumTest). */
class Orders
{
    public array $log = [];

    public function label(string $value): string
    {
        return OrderStatus::from($value)->label();
    }

    public function tryLabel(mixed $value): ?string
    {
        return OrderStatus::tryFrom($value)?->label();
    }

    public function options(): array
    {
        return array_map(fn (OrderStatus $status) => [$status->value, $status->label()], OrderStatus::cases());
    }

    public function compare(): array
    {
        return [
            OrderStatus::Paid === OrderStatus::Paid,
            OrderStatus::Paid == OrderStatus::Draft,
            OrderStatus::Paid == OrderStatus::Paid,
            OrderStatus::from('paid') === OrderStatus::Paid,
            in_array(OrderStatus::Paid, OrderStatus::open(), true),
            in_array(OrderStatus::Shipped, OrderStatus::open(), true),
            OrderStatus::Paid == 'paid',
            OrderStatus::DEFAULT === OrderStatus::Draft,
        ];
    }

    public function route(string $value): string
    {
        return match (OrderStatus::from($value)) {
            OrderStatus::Draft => 'edit',
            OrderStatus::Paid, OrderStatus::Shipped => 'track',
            default => 'archive',
        };
    }

    public function walk(string $value): array
    {
        $status = OrderStatus::from($value);
        $steps = [$status->value];

        while (! $status->isFinal()) {
            $status = $status->next();
            $steps[] = $status->badge();
        }

        $this->log = $steps;

        return $steps;
    }

    public function json(): string
    {
        return json_encode(['status' => OrderStatus::Paid, 'priority' => Priority::High, 'all' => OrderStatus::cases()]);
    }

    public function priority(mixed $value): string
    {
        return Priority::from($value)->name;
    }

    public function suits(): array
    {
        return array_map(fn (Suit $suit) => $suit->name.' '.$suit->color(), Suit::cases());
    }

    public function returnsCase(): OrderStatus
    {
        return OrderStatus::DEFAULT->next();
    }

    public function echoes(): string
    {
        return e(OrderStatus::Shipped).'/'.e(Priority::Low);
    }

    public function concatenates(): string
    {
        return 'Status: '.OrderStatus::Paid;
    }

    public function valueOfPure(): mixed
    {
        return Suit::Hearts->value;
    }
}
