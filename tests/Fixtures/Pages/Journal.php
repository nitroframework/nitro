<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Tests\Fixtures\Components\Journal as JournalComponent;

/**
 * The journal as a page (/journal): the component, routed, with its view.
 */
#[View('fusion.components.journal')]
class Journal extends JournalComponent
{
}
