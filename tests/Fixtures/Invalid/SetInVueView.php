<?php

namespace Nitro\Tests\Fixtures\Invalid;

use Nitro\Attributes\View;
use Nitro\Component;

/** A property named as what a JavaScript view gets from Nitro (set()): the view would see one of them. */
#[View(vue: 'set-in-vue-view')]
class SetInVueView extends Component
{
    /** @var list<string> */
    public array $set = [];
}
