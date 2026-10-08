<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Tests\Fixtures\Components\Counter as CounterComponent;

/**
 * The counter as a page (/counter, /private): the component, routed, with its view.
 */
#[View('fusion.components.counter')]
class Counter extends CounterComponent
{
}
