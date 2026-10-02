<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Component;

/**
 * fusion:transition, as Vue's <Transition>: the built-in fade and scale, Vue's classes (a name),
 * and the rows of a list.
 */
class Transitions extends Component
{
    public bool $open = false;

    public bool $shown = false;

    public array $items = [1, 2, 3];

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function toggleNamed(): void
    {
        $this->shown = ! $this->shown;
    }

    public function add(): void
    {
        $this->items[] = count($this->items) + 1;
    }

    public function removeFirst(): void
    {
        array_shift($this->items);
    }
}
