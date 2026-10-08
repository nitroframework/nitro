<?php

namespace Nitro\Fusion\Tests\Fixtures\Browser;

/** Browser methods using the #[Browser] classes, as a component's would (BrowserClassParityTest). */
class Checkout
{
    public array $lines = [['price' => 1250, 'quantity' => 2], ['price' => 399, 'quantity' => 1]];

    public string $rate = 'standard';

    public string $label = '';

    public function describe(): string
    {
        $this->label = Basket::summary($this->lines);

        return $this->label;
    }

    public function withVat(): string
    {
        $total = Money::total($this->lines);

        return Money::format($total + Money::vat($total, $this->rate), '€');
    }

    public function constants(): array
    {
        return [Money::CURRENCY, Money::RATES, Basket::FREE_DELIVERY_FROM, count(Money::RATES)];
    }

    public function formatted(int $pence): string
    {
        return Money::format($pence);
    }
}
