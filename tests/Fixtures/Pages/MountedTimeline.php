<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Nitro\Fusion\Attributes\Locked;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\Fixtures\Components\Store;

/**
 * Timeline's merge props from mount() rather than a controller (Route::fusion()): $this->scroll(),
 * $this->merge(), $this->deepMerge(); and $this->wants(), skipping work a partial reload does not
 * need. Each closure logs that it ran (Store::$log).
 */
class MountedTimeline extends Component
{
    #[Locked]
    public array $posts = [];

    #[Locked]
    public array $tags = [];

    #[Locked]
    public array $settings = [];

    #[Locked]
    public int $count = 0;

    public int $touched = 0;

    public function mount(): void
    {
        $posts = array_map(fn (int $n) => ['id' => $n, 'title' => "Post {$n}"], range(1, 23));
        $page = Paginator::resolveCurrentPage();

        $this->scroll('posts', function () use ($posts, $page) {
            Store::$log[] = 'posts';

            return new LengthAwarePaginator(array_slice($posts, ($page - 1) * 10, 10), 23, 10, $page);
        });

        $this->merge('tags', function () {
            Store::$log[] = 'tags';

            return [['id' => 1, 'name' => 'php'], ['id' => 2, 'name' => 'js']];
        })->matchOn('id');

        $this->deepMerge('settings', fn () => request()->hasHeader('X-Fusion-Partial')
            ? ['theme' => ['color' => 'teal']]
            : ['theme' => ['color' => 'red', 'size' => 'large']]);

        if ($this->wants('count')) {
            Store::$log[] = 'count';
            $this->count = 23;
        }
    }

    #[Server]
    public function touch(): void
    {
        $this->touched++;
    }
}
