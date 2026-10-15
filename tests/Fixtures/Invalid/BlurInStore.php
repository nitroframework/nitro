<?php

namespace Nitro\Tests\Fixtures\Invalid;

use Nitro\Attributes\Validate;
use Nitro\Store;

/** #[Validate(onBlur: true)] on a store's property: a check is of a component's fields. */
class BlurInStore extends Store
{
    #[Validate('required|email', onBlur: true)]
    public string $email = '';
}
