<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Component;

/** A component with a Solid view that reads it with useFusion() in a child: a key under an array set by its path. */
#[View(solid: 'solid-note')]
class SolidNote extends Component
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
