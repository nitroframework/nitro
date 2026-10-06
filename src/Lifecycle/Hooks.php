<?php

namespace Nitro\Fusion\Lifecycle;

use Nitro\Fusion\Component;

/**
 * Livewire's lifecycle hooks on the server, when the component has them:
 *
 *   boot()       every request: when the component is created, and on each #[Server] call
 *   mount(...)   once, when the component is created (the route's or the parent's values)
 *   hydrate()    each #[Server] call, once its state is restored
 *   dehydrate()  every request, before its state goes to the browser
 *
 * In the browser (compiled, or #[Server] to run on the server): mounted() once the component is
 * live in the page, destroyed() when it leaves.
 */
final class Hooks
{
    public const SERVER = ['boot', 'hydrate', 'dehydrate'];

    public const BROWSER = ['mounted', 'destroyed'];

    /** The component's own hook, when it has it (Features runs it, then the features'). */
    public static function call(Component $component, string $hook): void
    {
        if (method_exists($component, $hook)) {
            $component->{$hook}();
        }
    }
}
