<?php

namespace App\Nitro\Pages;

use Illuminate\Support\Facades\Cache;
use Nitro\Attributes\Server;
use Nitro\Attributes\Title;
use Nitro\Component;

/** A Blade page: counts in the browser, saves on the server, shows what was saved in the layout's dock. */
#[Title('Counter')]
class Counter extends Component
{
    public int $count = 0;

    public function mount(): void
    {
        $this->count = (int) Cache::get('count', 0);
    }

    public function increment(): void
    {
        $this->count++;
    }

    #[Server]
    public function save(): void
    {
        Cache::forever('count', $this->count);
    }
}
