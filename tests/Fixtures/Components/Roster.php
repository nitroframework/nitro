<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\Computed;
use Nitro\Attributes\Locked;
use Nitro\Attributes\Url;
use Nitro\Component;
use Nitro\Concerns\WithPagination;
use Nitro\Concerns\WithSorting;

/**
 * A table the Livewire way: the concerns do the sorting and paging, updated() resets the page.
 */
class Roster extends Component
{
    use WithPagination, WithSorting;

    #[Locked]
    public array $people = [
        ['name' => 'Eva'], ['name' => 'Bea'], ['name' => 'Gus'], ['name' => 'Ada'],
        ['name' => 'Fay'], ['name' => 'Cal'], ['name' => 'Dan'],
    ];

    #[Url(as: 'q')]
    public string $search = '';

    #[Computed]
    public function rows(): array
    {
        $matches = array_filter($this->people, fn ($person) => $this->search === '' || str_contains(strtolower($person['name']), $this->search));

        return $this->paginate($this->sorted($matches), 3);
    }

    public function updated(string $property): void
    {
        if ($property === 'search') {
            $this->resetPage();
        }
    }
}
