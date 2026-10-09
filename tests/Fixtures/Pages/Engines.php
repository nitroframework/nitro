<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Component;

/**
 * A Blade component and one whose view is JavaScript on the same page: the server renders the
 * first PlainCounter's element; the second is made in the browser once $more is on.
 */
class Engines extends Component
{
    public bool $more = false;
}
