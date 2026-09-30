<?php

namespace Nitro\Fusion\Concerns;

use Nitro\Fusion\Attributes\Url;

/**
 * Pages of rows, in the browser:
 *
 *   #[Computed]
 *   public function rows(): array
 *   {
 *       return $this->paginate($this->students, 12);
 *   }
 *
 * and in the view, rows['items'] is the page, with rows['page'], rows['pages'], ... to show where
 * it is, and previousPage, nextPage and gotoPage(3) as handlers:
 *
 *   <button fusion:click="previousPage" @disabled($this->rows['page'] === 1)>Previous</button>
 *
 * A new filter should start from the first page: call resetPage() when it changes
 * (updated($property) is the place).
 *
 * The page is in the URL (?page=3, as Livewire's paginator keeps it), left out on the first.
 */
trait WithPagination
{
    #[Url]
    public int $page = 1;

    /**
     * One page of $rows, and where it is: items, page, pages, total, from and to (1-based, for
     * "11–20 of 45"). The page shown stays within the pages there are.
     *
     * @return array{items: array, page: int, pages: int, total: int, from: int, to: int}
     */
    public function paginate(array $rows, int $perPage = 15): array
    {
        $total = count($rows);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($this->page, $pages));

        return [
            'items' => array_slice(array_values($rows), ($page - 1) * $perPage, $perPage),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'from' => $total === 0 ? 0 : ($page - 1) * $perPage + 1,
            'to' => min($page * $perPage, $total),
        ];
    }

    public function gotoPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function previousPage(): void
    {
        $this->gotoPage($this->page - 1);
    }

    public function nextPage(): void
    {
        $this->gotoPage($this->page + 1);
    }

    public function resetPage(): void
    {
        $this->page = 1;
    }
}
