<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Component;

/**
 * <fusion:dynamic-component :is="...">: the child is the component its expression names, and
 * another one when the name changes (none for "").
 */
class Panels extends Component
{
    public string $panel = 'card';

    /** Each with its own key (the name is part of it): a name twice is two components. */
    public array $stack = ['card', 'search-box'];
}
