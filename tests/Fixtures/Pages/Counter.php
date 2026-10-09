<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\View;
use Nitro\Tests\Fixtures\Components\Counter as CounterComponent;

/**
 * The counter as a page (/counter, /private): the component, routed, with its view.
 */
#[View('nitro.components.counter')]
class Counter extends CounterComponent
{
}
