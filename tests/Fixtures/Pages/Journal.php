<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\View;
use Nitro\Tests\Fixtures\Components\Journal as JournalComponent;

/**
 * The journal as a page (/journal): the component, routed, with its view.
 */
#[View('nitro.components.journal')]
class Journal extends JournalComponent
{
}
