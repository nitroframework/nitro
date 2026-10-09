<?php

namespace Nitro\Tests\Fixtures\Invalid;

use Nitro\Attributes\Persist;
use Nitro\Component;

/** #[Persist] on a component: it is for stores. */
#[Persist]
class PersistedComponent extends Component
{
    public string $note = '';
}
