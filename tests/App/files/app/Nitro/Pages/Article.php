<?php

namespace App\Nitro\Pages;

use Illuminate\Support\Str;
use Nitro\Attributes\Html;
use Nitro\Attributes\Server;
use Nitro\Attributes\Title;
use Nitro\Attributes\Url;
use Nitro\Attributes\View;
use Nitro\Component;

/** A Vue page: its topic in the address, its body Markdown made on the server. */
#[Title('Article')]
#[View(vue: 'article')]
class Article extends Component
{
    #[Url]
    public string $topic = 'nitro';

    #[Html]
    public function body(): string
    {
        return Str::markdown('# '.ucfirst($this->topic)."\n\nAll about *".e($this->topic).'*.');
    }

    #[Server]
    public function choose(string $topic): void
    {
        $this->topic = $topic;
    }
}
