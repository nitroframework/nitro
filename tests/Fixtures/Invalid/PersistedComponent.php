<?php

namespace Nitro\Fusion\Tests\Fixtures\Invalid;

use Nitro\Fusion\Attributes\Persist;
use Nitro\Fusion\Component;

/** #[Persist] on a component: it is for stores. */
#[Persist]
class PersistedComponent extends Component
{
    public string $note = '';
}
