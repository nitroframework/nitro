<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\Cached;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Attributes\Url;
use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Component;

/**
 * #[Cached] (/catalog): mount()'s state kept by its #[Url] sort; search() what it did ($found
 * and its return) by its argument and $sort only; both under the "catalog" tag.
 * The counters say how often each really ran.
 */
#[View('fusion.components.catalog')]
class Catalog extends Component
{
    public static int $mounts = 0;

    public static int $searches = 0;

    #[Url]
    public string $sort = 'name';

    public array $items = [];

    public array $found = [];

    #[Cached(60, tags: ['catalog'])]
    public function mount(): void
    {
        self::$mounts++;
        $this->items = ['by '.$this->sort, 'mount '.self::$mounts];
    }

    #[Server]
    #[Cached(60, tags: ['catalog'], by: ['sort'])]
    public function search(string $query): array
    {
        self::$searches++;
        $this->found = [$query, self::$searches];

        return $this->found;
    }
}
