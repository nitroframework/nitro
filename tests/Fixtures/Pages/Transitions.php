<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Component;

/**
 * nitro:transition, as Vue's <Transition>: the built-in fade and scale, Vue's classes (a name),
 * and the rows of a list.
 */
class Transitions extends Component
{
    public bool $open = false;

    public bool $shown = false;

    public array $items = [1, 2, 3];

    /** .move: rows that slide to their places. */
    public array $movers = [1, 2, 3];

    /** .out-in and .in-out: one element replacing another. */
    public string $tab = 'a';

    public string $pane = 'x';

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

    public function reverseMovers(): void
    {
        $this->movers = array_reverse($this->movers);
    }

    public function dropMover(): void
    {
        array_shift($this->movers);
    }

    public function switchTab(): void
    {
        $this->tab = $this->tab === 'a' ? 'b' : 'a';
    }

    public function switchPane(): void
    {
        $this->pane = $this->pane === 'x' ? 'y' : 'x';
    }
}
