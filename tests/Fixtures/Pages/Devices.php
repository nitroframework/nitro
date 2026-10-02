<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Component;
use Nitro\Fusion\Facades\Fusion;

/** Fusion::browser(): the clipboard, storage, a canvas and a WebSocket, from browser methods. */
class Devices extends Component
{
    public string $code = 'INV-0042';

    public bool $copied = false;

    public string $pasted = '';

    public string $title = 'Draft';

    public string $loaded = '';

    public bool $stored = false;

    /** @var list<int> */
    public array $bars = [40, 70, 25];

    /** @var list<string> */
    public array $messages = [];

    public bool $open = false;

    public function copy(): void
    {
        Fusion::browser()->clipboard()->write($this->code, fn () => $this->copied = true);
    }

    public function paste(): void
    {
        Fusion::browser()->clipboard()->read(function (string $text) {
            $this->pasted = $text;
        });
    }

    public function save(): void
    {
        $storage = Fusion::browser()->storage();
        $storage->set('draft', ['title' => $this->title, 'at' => 1]);
        $this->stored = $storage->has('draft');
    }

    public function load(): void
    {
        $this->loaded = Fusion::browser()->storage()->get('draft', ['title' => 'none'])['title'];
        Fusion::browser()->session()->set('loads', Fusion::browser()->session()->get('loads', 0) + 1);
    }

    public function draw(): void
    {
        $canvas = Fusion::browser()->canvas('chart')->size(120, 100)->clear()->fillStyle('#4f46e5');

        foreach ($this->bars as $i => $bar) {
            $canvas->fillRect($i * 40, 100 - $bar, 30, $bar);
        }

        $canvas->beginPath()->arc(60, 50, 10)->stroke();
    }

    public function grow(): void
    {
        $this->bars[] = 90;
        $this->draw();
    }

    public function connect(): void
    {
        Fusion::browser()->socket('/echo')
            ->onOpen(fn () => $this->open = true)
            ->onClose(fn () => $this->open = false)
            ->onMessage(function (array $data) {
                $this->messages[] = $data['text'];
            })
            ->send(['text' => 'hello']);
    }

    public function say(): void
    {
        Fusion::browser()->socket('/echo')->send(['text' => 'again']);
    }

    public function disconnect(): void
    {
        Fusion::browser()->socket('/echo')->close();
    }
}
