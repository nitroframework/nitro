<?php

namespace Nitro\Fusion\Concerns;

/**
 * Sortable rows, in the browser:
 *
 *   <th><button fusion:click="sortBy('name')">Name {{ $this->sortIcon('name') }}</button></th>
 *
 * with the rows passed through $this->sorted($rows) where the component works them out.
 * Until a column is chosen the rows keep the order they came in.
 */
trait WithSorting
{
    /** The field the rows are sorted by ('': as they came), and which way. */
    public string $sortField = '';

    public string $sortDirection = 'asc';

    /** Sort by $field; asked again for the same field, the other way round. */
    public function sortBy(string $field): void
    {
        $this->sortDirection = $this->sortField === $field && $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->sortField = $field;
    }

    /** $rows by the chosen field; rows that compare equal keep their order. */
    public function sorted(array $rows): array
    {
        if ($this->sortField === '') {
            return $rows;
        }

        $field = $this->sortField;
        $direction = $this->sortDirection === 'asc' ? 1 : -1;
        usort($rows, fn ($a, $b) => ($a[$field] <=> $b[$field]) * $direction);

        return $rows;
    }

    /** ▲ or ▼ beside the heading of the column the rows are sorted by. */
    public function sortIcon(string $field): string
    {
        return $this->sortField !== $field ? '' : ($this->sortDirection === 'asc' ? '▲' : '▼');
    }
}
