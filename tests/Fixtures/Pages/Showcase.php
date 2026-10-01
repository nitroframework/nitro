<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Component;

/** The inlining fixture: Blade components (<x-...>) and @include in a Fusion view, rendered in the browser as Blade renders them. */
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
}
