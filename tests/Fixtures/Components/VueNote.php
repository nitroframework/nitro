<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Component;

/** A component with a Vue view that is a template alone: its names used bare, v-model deep into an array. */
#[View(vue: 'vue-note')]
class VueNote extends Component
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
