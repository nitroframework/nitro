<?php

namespace Nitro\Fusion\Attributes;

use Attribute;

/**
 * The component's view, when it does not follow the convention (App\Fusion\Students\Form ->
 * fusion.students.form).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class View
{
    public function __construct(public readonly string $name)
    {
    }
}
