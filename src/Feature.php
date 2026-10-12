<?php

namespace Nitro;

use Closure;
use Throwable;

/**
 * Something that runs around every component on the server: list the class in nitro.features
 * and override the hooks it needs. Features are made by the container, once per request (so a
 * constructor can take services), and run after Nitro's own, in the order listed. Each hook runs
 * after the component's own method of that name, when it has one.
 *
 *     class AuditCalls extends Feature
 *     {
 *         public function called(Component $component, string $method, mixed $result): void
 *         {
 *             Log::info('nitro call', ['component' => $component::class, 'method' => $method]);
 *         }
 *     }
 *
 * Nitro's own #[Url] and #[Validate] are features too (Nitro\Lifecycle).
 */
abstract class Feature
{
    /** Every request, when the component is made or restored (a store too), after its boot(). */
    public function boot(Component $component): void {}

    /**
     * A new component, before its mount() (which takes its tag's props), or once its props are
     * set when it has none. Not on a #[Server] call: the component exists already.
     */
    public function mounting(Component $component): void {}

    /** A #[Server] call: the component's state is restored, after its hydrate(). */
    public function hydrate(Component $component): void {}

    /**
     * A #[Server] call: the properties the browser changed are set (by name). False keeps the
     * method from running, as a failed #[Validate] does: its errors reach the browser.
     *
     * @param  list<string>  $changed
     */
    public function updated(Component $component, array $changed): bool
    {
        return true;
    }

    /**
     * Before a #[Server] method runs, with the arguments it will get.
     *
     * @param  list<mixed>  $arguments
     */
    public function calling(Component $component, string $method, array $arguments): void {}

    /**
     * Around a #[Server] method, after calling(): $run() runs it (and the features listed after
     * this one) and gives what it returned. Return that, or something else; return without
     * $run() to answer without the method (a value of your own, a cache's). The first listed
     * is the outermost, as middleware are.
     *
     * @param  list<mixed>  $arguments
     * @param  Closure(): mixed  $run
     */
    public function call(Component $component, string $method, array $arguments, Closure $run): mixed
    {
        return $run();
    }

    /** After a #[Server] method returned (not when it threw), with what it returned. */
    public function called(Component $component, string $method, mixed $result): void {}

    /**
     * A #[Server] method threw, and the component's own exception() did not handle it (it has
     * none, or that threw too). True: handled, the call answers with the state as it is, and the
     * features after this one do not hear it. When none handles it, it is thrown as before.
     */
    public function exception(Component $component, string $method, Throwable $exception): bool
    {
        return false;
    }

    /** Every request, before the component's state goes to the browser, after its dehydrate(). Once per request. */
    public function dehydrate(Component $component): void {}
}
