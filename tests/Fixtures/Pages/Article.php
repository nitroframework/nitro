<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\Description;
use Nitro\Fusion\Attributes\Layout;
use Nitro\Fusion\Attributes\Title;
use Nitro\Fusion\Component;

/**
 * A page that sets its <head> from data ($this->head() in mount()), over its #[Title] and
 * #[Description], in a layout with @fusionHead.
 */
#[Layout('components.layouts.headed')]
#[Title('Article')]
#[Description('Not shown: mount() gives another')]
class Article extends Component
{
    public string $heading = '';

    public function mount(): void
    {
        $this->heading = 'Fusion & Blade';

        $this->head()
            ->title($this->heading)
            ->description('How Fusion compiles <Blade> to JavaScript')
            ->image('https://example.com/article.png')
            ->canonical('https://example.com/article')
            ->meta('author', 'Ada');
    }
}
