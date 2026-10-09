<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\View;
use Nitro\Component;

/** A component with a React view that reads it with useNitro() in a child: a key under an array set by its path. */
#[View(react: 'react-note')]
class ReactNote extends Component
{
    public string $text = 'hi';

    public array $form = ['email' => '', 'tags' => ['a']];

    public function shout(): void
    {
        $this->text = strtoupper($this->text).'!';
    }

    public function addTag(string $tag): void
    {
        $this->form['tags'][] = $tag;
    }
}
