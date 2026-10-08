<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\On;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;

class TodoList extends Component
{
    public array $todos = [];

    public string $title = '';

    public string $filter = 'all';

    public int $nextId = 1;

    public function mount(array $todos = []): void
    {
        $this->todos = $todos;
        $this->nextId = $todos === [] ? 1 : max(array_column($todos, 'id')) + 1;
    }

    public function add(): void
    {
        $title = trim($this->title);

        if ($title === '') {
            return;
        }

        $this->todos[] = ['id' => $this->nextId++, 'title' => $title, 'done' => false];
        $this->title = '';
    }

    #[On('todo-toggled')]
    public function toggle(int $id): void
    {
        foreach ($this->todos as $i => $todo) {
            if ($todo['id'] === $id) {
                $this->todos[$i]['done'] = ! $todo['done'];
            }
        }
    }

    #[On('todo-removed')]
    public function remove(int $id): void
    {
        $this->todos = array_values(array_filter($this->todos, fn ($todo) => $todo['id'] !== $id));
    }

    public function visible(): array
    {
        return match ($this->filter) {
            'open' => array_values(array_filter($this->todos, fn ($todo) => ! $todo['done'])),
            'done' => array_values(array_filter($this->todos, fn ($todo) => $todo['done'])),
            default => $this->todos,
        };
    }

    public function remaining(): int
    {
        return count(array_filter($this->todos, fn ($todo) => ! $todo['done']));
    }

    #[Server]
    public function persist(): void
    {
        $this->validate([
            'todos' => 'required|array|min:1',
            'todos.*.title' => 'required|string|max:20',
        ]);

        Store::$saved['todos'] = $this->todos;
    }
}
