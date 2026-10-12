<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\Url;
use Nitro\Component;

/**
 * #[Url(history: true)]: each tab chosen is a history entry, so back and forward step through
 * the tabs, the page as it was for each.
 */
class Tabs extends Component
{
    #[Url(history: true)]
    public string $tab = 'one';
}
