<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\LazyLoad;
use Nitro\Component;

/**
 * A child without mount(): its props are its public properties, so the browser can create it.
 * In a chunk of its own (#[LazyLoad]): the browser loads it for the first todo it makes.
 */
#[LazyLoad('todos')]
class TodoItem extends Component
{
    public array $todo = [];

    public bool $editing = false;

    public function remove(): void
    {
        $this->dispatch('todo-removed', $this->todo['id']);
    }

    public function toggleEdit(): void
    {
        $this->editing = ! $this->editing;
    }
}
