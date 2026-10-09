<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\Locked;
use Nitro\Attributes\Server;
use Nitro\Attributes\Uploads;
use Nitro\Component;
use Nitro\Upload;

/** The uploads fixture: one photo (?Upload) and several (array), validated and stored on the server. */
class Gallery extends Component
{
    public ?Upload $photo = null;

    #[Uploads]
    public array $photos = [];

    public string $caption = '';

    /** Where save() put the photo, and the names of the photos it stored. */
    #[Locked]
    public array $stored = [];

    #[Server]
    public function save(): void
    {
        $this->validate(['photo' => 'required|image|max:512', 'photos.*' => 'file']);

        $this->stored = [
            'photo' => $this->photo->storeAs('gallery', 'cover.'.$this->photo->getClientOriginalExtension(), 'local'),
            'photos' => array_map(fn (Upload $upload) => $upload->getClientOriginalName(), $this->photos),
        ];
        $this->reset('photo', 'photos');
    }
}
