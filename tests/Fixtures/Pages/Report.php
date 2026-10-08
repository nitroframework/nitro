<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\Fixtures\Components\Store;

/**
 * Deferred properties ($this->defer()): shown after the page, with @deferred / @placeholder.
 */
class Report extends Component
{
    public string $title = '';

    public ?array $stats = null;

    public array $chart = [];

    public int $clicks = 0;

    public function mount(): void
    {
        $this->title = 'Sales';

        $this->defer('stats', function () {
            Store::$log[] = 'stats';

            return ['orders' => 12, 'total' => 340];
        });

        $this->defer('chart', fn () => [3, 5, 4]);
    }

    public function click(): void
    {
        $this->clicks++;
    }
}
