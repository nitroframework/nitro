<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\On;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\Fixtures\Browser\Basket;
use Nitro\Fusion\Tests\Fixtures\Browser\OrderStatus;

/**
 * The inlining fixture: Blade components (<x-...>) and @include in a Fusion view, rendered in the
 * browser as Blade renders them. And #[Browser] classes: Money in the view, Basket in a method.
 */
class Showcase extends Component
{
    public array $cakes = [['name' => 'Rasmalai'], ['name' => 'Pistachio & Rose']];

    public string $tone = 'gold';

    public bool $featured = false;

    public string $note = '';

    public int $clicks = 0;

    public function click(): void
    {
        $this->clicks++;
    }

    public function feature(): void
    {
        $this->featured = ! $this->featured;
        $this->tone = $this->featured ? 'red' : 'gold';
    }

    /** A cake at £12.50 a click. */
    public function basket(): string
    {
        return Basket::summary([['price' => 1250, 'quantity' => $this->clicks]]);
    }

    /** An enum property: a case in PHP and in the browser, its value in the state and the <select>. */
    public OrderStatus $status = OrderStatus::Draft;

    public string $seen = '';

    public function advance(): void
    {
        $this->status = $this->status->next();
    }

    /** What fusion:tell's directive dispatched (the application's own fusion: attribute). */
    public string $told = '';

    #[On('told')]
    public function heard(string $word, array $meta): void
    {
        $this->told = $word.' from '.$meta['by'];
    }

    #[Server]
    public function confirm(): void
    {
        $this->seen = 'server saw '.$this->status->name.($this->status === OrderStatus::Paid ? ' (paid)' : '');
    }
}
