<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;

/**
 * The lifecycle: the server hooks (boot, mount, hydrate, dehydrate) as Livewire has them; the
 * browser's (mounted, destroyed) as Viewi has them; $this->js() with $refs (fusion:ref).
 */
class Journal extends Component
{
    public string $note = 'first entry';

    public bool $ready = false;

    public array $saved = [];

    public function boot(): void
    {
        Store::$log[] = 'boot';
    }

    public function mount(): void
    {
        Store::$log[] = 'mount';
    }

    public function hydrate(): void
    {
        Store::$log[] = 'hydrate';
    }

    public function dehydrate(): void
    {
        Store::$log[] = 'dehydrate';
    }

    /** In the browser, once the component is live: its field gets the focus. */
    public function mounted(): void
    {
        $this->ready = true;
        $this->js('$refs.note.focus(); $refs.note.select()');
    }

    /** In the browser, when it leaves the page. */
    public function destroyed(): void
    {
        $this->dispatch('journal-gone', note: $this->note);
    }

    #[Server]
    public function save(): void
    {
        $this->saved[] = $this->note;
        $this->js('window.savedJournal = $fusion.note + " / " + $refs.status.textContent + " / " + $el.tagName');
    }
}
