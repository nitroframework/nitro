<?php

namespace Nitro\Attributes;

use Attribute;

/**
 * Validation rules for a property, checked on the server. On every #[Server] call, the
 * properties the browser changed are checked against their rules before the method runs: when
 * one fails, the method does not run and the messages are in $errors. $this->validate() with no
 * rules checks every property that has them.
 *
 *   #[Validate('integer|min:1|max:10')]
 *   public int $quantity = 1;
 *
 *   #[Validate(['tags' => 'array|max:3', 'tags.*' => 'string|min:2'])]  // keys of an array
 *   public array $tags = [];
 *
 *   #[Validate('required|email', as: 'email address')]                  // the name in messages
 *   public string $email = '';
 *
 * The browser's updated*() hooks run in the browser and protect nothing on the server: anyone
 * can send any value of a property's type. These rules are where to stop what must not reach a
 * #[Server] method.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Validate
{
    /**
     * @param  string|array<int|string, mixed>  $rules  the property's rules, or rules by key (dot notation) for an array
     */
    public function __construct(public readonly string|array $rules, public readonly ?string $as = null)
    {
    }
}
