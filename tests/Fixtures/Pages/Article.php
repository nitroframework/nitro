<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\Description;
use Nitro\Attributes\Layout;
use Nitro\Attributes\Title;
use Nitro\Component;

/**
 * A page that sets its <head> from data ($this->head() in mount()), over its #[Title] and
 * #[Description], in a layout with @nitroHead.
 */
#[Layout('components.layouts.headed')]
#[Title('Article')]
#[Description('Not shown: mount() gives another')]
class Article extends Component
{
    public string $heading = '';

    public function mount(): void
    {
        $this->heading = 'Nitro & Blade';

        $this->head()
            ->title($this->heading)
            ->description('How Nitro compiles <Blade> to JavaScript')
            ->image('https://example.com/article.png')
            ->canonical('https://example.com/article')
            ->meta('author', 'Ada');
    }
}
