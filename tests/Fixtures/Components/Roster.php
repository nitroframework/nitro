<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\Computed;
use Nitro\Fusion\Attributes\Locked;
use Nitro\Fusion\Attributes\Url;
use Nitro\Fusion\Component;
use Nitro\Fusion\Concerns\WithPagination;
use Nitro\Fusion\Concerns\WithSorting;

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
