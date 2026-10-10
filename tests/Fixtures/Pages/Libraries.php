<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\Server;
use Nitro\Attributes\Title;
use Nitro\Component;

/**
 * Third-party libraries bound to properties with the app's nitro: attributes (js/libraries.js):
 * select2 (one, and a multiple), Flatpickr, Tom Select, Choices, Trix, Quill, SortableJS,
 * noUiSlider, IMask, and a widget that rebuilds its own markup; one select2 inside an @if, one per
 * row of a list. The browser tests drive each library both ways, through re-renders, a #[Server]
 * call, an @if, new rows and visits (LibrariesLobby, ToolsLibraries: the ways in).
 */
#[Title('Libraries')]
class Libraries extends Component
{
    public string $category = 'b';

    /** @var list<string> */
    public array $tags = ['x'];

    public string $day = '2026-01-15';

    public string $color = 'red';

    public string $size = 'm';

    public string $fake = 'one';

    public string $body = '<div>Hello</div>';

    public string $note = '<p>Hi</p>';

    /** @var list<string> */
    public array $order = ['a', 'b', 'c'];

    public int $volume = 30;

    public string $card = '1234123412341234';

    public bool $show = true;

    public int $clicks = 0;

    /** @var list<array{pick: string}> */
    public array $rows = [['pick' => 'a']];

    public function addRow(): void
    {
        $this->rows[] = ['pick' => 'c'];
    }

    /** In the browser: each property set, as code would; the libraries follow. */
    public function localReset(): void
    {
        $this->category = 'a';
        $this->tags = [];
        $this->day = '2026-01-01';
        $this->color = 'red';
        $this->size = 's';
        $this->fake = 'three';
        $this->body = '<div>Reset</div>';
        $this->note = '<p>Reset</p>';
        $this->order = ['c', 'b', 'a'];
        $this->volume = 10;
        $this->card = '1111222233334444';
    }

    /** On the server: the answer sets them; the libraries follow. */
    #[Server]
    public function serverReset(): void
    {
        $this->category = 'b';
        $this->tags = ['y', 'z'];
        $this->day = '2026-02-02';
        $this->color = 'green';
        $this->size = 'm';
        $this->fake = 'two';
        $this->body = '<div>Server</div>';
        $this->note = '<p>Server</p>';
        $this->order = ['b', 'a', 'c'];
        $this->volume = 90;
        $this->card = '9999888877776666';
    }
}
