<?php

namespace Nitro\Fusion\Lifecycle;

use Illuminate\Support\Facades\Validator;
use Nitro\Fusion\Compiler\Components;
use Nitro\Fusion\Component;
use Nitro\Fusion\Feature;
use Nitro\Fusion\Compiler\StateProperties;

/**
 * #[Validate]: on a #[Server] call, the properties the browser changed, against their rules.
 * When one fails, its messages are the component's $errors, and the method does not run.
 *
 * @internal
 */
final class Validation extends Feature
{
    public function __construct(private readonly Components $components) {}

    public function updated(Component $component, array $changed): bool
    {
        [$rules, $attributes] = $this->components->meta($component::class)->rulesFor($changed);

        if ($rules === []) {
            return true;
        }

        $validator = Validator::make(StateProperties::values($component), $rules, [], $attributes);

        if ($validator->fails()) {
            $component->fusion()->errors = $validator->errors();

            return false;
        }

        return true;
    }
}
