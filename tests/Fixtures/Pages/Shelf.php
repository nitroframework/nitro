<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\On;
use Nitro\Fusion\Component;

/**
 * A page passing slots to its children: the content is this component's (its state, its
 * handlers, its fields, a child of its own), rendered inside the cards.
 */
class Shelf extends Component
{
    public int $count = 1;

    public string $label = 'Books';

    public array $items = ['Atlas', 'Bible'];

    /** Bound to the search box's #[Modelable] value. */
    public string $query = 'maps';

    public array $notes = [];

    public function add(): void
    {
        $this->count++;
        $this->items[] = 'Item '.$this->count;
    }

    #[On('noted')]
    public function noted(string $note): void
    {
        $this->notes[] = $note;
    }
}
