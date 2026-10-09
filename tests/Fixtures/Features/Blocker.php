<?php

namespace Nitro\Tests\Fixtures\Features;

use Nitro\Component;
use Nitro\Feature;

/** An application's feature that refuses a value: the method does not run, and the error reaches the browser. */
class Blocker extends Feature
{
    public function updated(Component $component, array $changed): bool
    {
        if (in_array('note', $changed, true) && ($component->note ?? null) === 'blocked') {
            $component->nitro()->errors->add('note', 'This note is blocked.');

            return false;
        }

        return true;
    }
}
