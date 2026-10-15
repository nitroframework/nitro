<?php

namespace Nitro\Lifecycle;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Nitro\Compiler\Components;
use Nitro\Component;
use Nitro\Feature;
use Nitro\Compiler\StateProperties;

/**
 * #[Validate]: on a #[Server] call, the properties the browser changed, against their rules.
 * When one fails, its messages are the component's $errors, and the method does not run.
 *
 * The component's $errors come back with its state (State\Snapshot restores its last call's), and
 * are written here: cleared as a method starts, and set by a failed validation, its rules' or the
 * method's own (a ValidationException from validate(), validateOnly(), Validator or
 * withMessages()).
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
            self::failed($component, $validator->errors());

            return false;
        }

        return true;
    }

    /** A method starts with no errors: those it had are its last call's. */
    public static function clear(Component $component): void
    {
        $component->nitro()->errors = new MessageBag;
    }

    /** A validation failed: its messages are the component's $errors. */
    public static function failed(Component $component, MessageBag $messages): void
    {
        $component->nitro()->errors = $messages;
    }
}
