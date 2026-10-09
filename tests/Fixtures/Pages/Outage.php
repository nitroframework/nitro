<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Component;

/** A page with a child whose mount() fails (Flaky) beside one that works: a visit renders it in place, the child's fallback where it goes. */
class Outage extends Component
{
}
