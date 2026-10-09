<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\Computed;
use Nitro\Attributes\Server;
use Nitro\Attributes\Uploads;
use Nitro\Attributes\Validate;
use Nitro\Upload;

/**
 * One component, four views: BladeParity, VueParity, ReactParity and SolidParity use it, and
 * their views share one markup, so the browser tests' "parity" scenario runs the same actions
 * on each and expects the same results.
 */
trait Parity
{
    public int $count = 1;

    #[Validate('required|min:3')]
    public string $title = '';

    public array $form = ['email' => '', 'tags' => ['a']];

    public string $said = '';

    /** A file, and a list of them: uploaded at once, by nitro:model or the view's upload(). */
    public ?Upload $photo = null;

    #[Uploads]
    public array $photos = [];

    #[Computed]
    public function doubled(): int
    {
        return $this->count * 2;
    }

    public function increment(int $by = 1): void
    {
        $this->count += $by;
    }

    public function addTag(string $tag): void
    {
        $this->form['tags'][] = $tag;
    }

    #[Server]
    public function save(): string
    {
        /** Longer than a call takes to be slow (nitro:loading.delay, save.slow). */
        usleep(400_000);
        $this->validate();

        return $this->said = "saved {$this->title} at {$this->count} for {$this->form['email']}";
    }
}
