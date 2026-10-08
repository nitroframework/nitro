<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\Computed;
use Nitro\Fusion\Attributes\Locked;
use Nitro\Fusion\Attributes\On;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Attributes\Url;
use Nitro\Fusion\Attributes\Validate;
use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\Fixtures\Browser\Money;
use Nitro\Fusion\Tests\Fixtures\Browser\OrderStatus;

/**
 * The stress fixture: as much of Fusion as one page can hold, used together. Nested arrays written
 * deep, #[Computed] filtering and sorting with closures, #[Url], an enum property, #[Browser]
 * classes, children with props and events, a #[Modelable] child bound to a nested path, nested
 * #[Validate] rules, a #[Server] method, @teleport (the browser tests' "stress", TemplateParityTest).
 */
class Stress extends Component
{
    public array $orders = [
        ['id' => 1, 'customer' => 'Ada <b>', 'status' => 'paid', 'lines' => [['name' => 'Rasmalai', 'price' => 450, 'qty' => 2], ['name' => 'Pistachio & Rose', 'price' => 1299, 'qty' => 1]]],
        ['id' => 2, 'customer' => 'Grace', 'status' => 'draft', 'lines' => []],
        ['id' => 3, 'customer' => 'Linus', 'status' => 'shipped', 'lines' => [['name' => 'Jalebi', 'price' => 99, 'qty' => 120]]],
    ];

    #[Url]
    public string $filter = '';

    #[Url(as: 'by')]
    public string $sort = 'id';

    public OrderStatus $focus = OrderStatus::Paid;

    public ?int $selected = null;

    #[Validate(['draft.customer' => 'required|min:2', 'draft.lines.*.qty' => 'integer|min:1|max:99'])]
    public array $draft = ['customer' => '', 'lines' => [['name' => 'Ladoo', 'price' => 150, 'qty' => 1]]];

    public array $log = [];

    /** The ids are the server's. */
    #[Locked]
    public int $nextId = 4;

    #[Computed]
    public function visible(): array
    {
        $rows = array_values(array_filter($this->orders, fn (array $order) => $this->filter === '' || str_contains(strtolower($order['customer']), strtolower($this->filter))));

        usort($rows, fn (array $a, array $b) => match ($this->sort) {
            'total' => $this->totalOf($b) <=> $this->totalOf($a),
            'customer' => strcmp($a['customer'], $b['customer']),
            default => $a['id'] <=> $b['id'],
        });

        return $rows;
    }

    #[Computed]
    public function grandTotal(): string
    {
        return Money::format(array_sum(array_map(fn (array $order) => $this->totalOf($order), $this->orders)));
    }

    public function totalOf(array $order): int
    {
        return array_sum(array_map(fn (array $line) => $line['price'] * $line['qty'], $order['lines']));
    }

    public function bump(int $id, int $line): void
    {
        foreach ($this->orders as $i => $order) {
            if ($order['id'] === $id) {
                $this->orders[$i]['lines'][$line]['qty']++;
                $this->log[] = "bump {$id}.{$line}";
            }
        }
    }

    /** Moves an order up the list, swapping it with the one before. */
    public function up(int $id): void
    {
        $at = array_search($id, array_column($this->orders, 'id'), true);

        if ($at !== false && $at > 0) {
            [$this->orders[$at - 1], $this->orders[$at]] = [$this->orders[$at], $this->orders[$at - 1]];
            $this->log[] = "up {$id}";
        }
    }

    #[On('row-picked')]
    public function pick(int $id): void
    {
        $this->selected = $this->selected === $id ? null : $id;
        $this->log[] = "pick {$id}";
    }

    public function advance(): void
    {
        $this->focus = $this->focus->next();
    }

    public function addLine(): void
    {
        $this->draft['lines'][] = ['name' => 'Barfi', 'price' => 200, 'qty' => 1];
    }

    public function removeLine(int $i): void
    {
        array_splice($this->draft['lines'], $i, 1);
    }

    #[Server]
    public function save(): int
    {
        /** Slow enough for fusion:loading.delay.100ms to show, not .delay.2s. */
        usleep(300_000);
        $this->validate();
        $id = $this->nextId++;
        $this->orders[] = ['id' => $id, 'customer' => $this->draft['customer'], 'status' => 'draft', 'lines' => $this->draft['lines']];
        $this->draft = ['customer' => '', 'lines' => []];
        $this->log[] = "saved {$id}";

        return $id;
    }
}
