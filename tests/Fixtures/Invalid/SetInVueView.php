<?php

namespace Nitro\Fusion\Tests\Fixtures\Invalid;

use Nitro\Fusion\Attributes\View;
use Nitro\Fusion\Component;

/** A property named as what a JavaScript view gets from Fusion (set()): the view would see one of them. */
#[View(vue: 'set-in-vue-view')]
class SetInVueView extends Component
{
    /** @var list<string> */
    public array $set = [];
}
