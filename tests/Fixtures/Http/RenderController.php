<?php

namespace Nitro\Fusion\Tests\Fixtures\Http;

use Nitro\Fusion\Facades\Fusion;
use Nitro\Fusion\Tests\Fixtures\Components\Store;
use Nitro\Fusion\Tests\Fixtures\Models\Student;
use Nitro\Fusion\Tests\Fixtures\Pages\Counter;
use Nitro\Fusion\Tests\Fixtures\Pages\Orders;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages from a Laravel controller (Fusion::render()), as Inertia::render() returns them.
 */
class RenderController
{
    /** A page without mount(): the props set its public properties. */
    public function counter(): Response
    {
        return Fusion::render(Counter::class, ['count' => 7, 'step' => 2]);
    }

    /** A deferred prop: its property is set after the page is shown. */
    public function deferredCounter(): Response
    {
        return Fusion::render(Counter::class, ['count' => 7, 'step' => Fusion::defer(fn () => 5)]);
    }

    /** Closure props: called when the page needs them (a partial reload, only those it wants). */
    public function orders(): Response
    {
        return Fusion::render(Orders::class, [
            'orders' => function () {
                Store::$log[] = 'orders';

                return session('orders', []);
            },
            'total' => function () {
                Store::$log[] = 'total';

                return count(session('orders', []));
            },
        ]);
    }

    /** A page with mount(): the props are its arguments, by name (here, by the page's name). */
    public function student(Student $student): Response
    {
        return Fusion::render('pages::student-page', ['student' => $student]);
    }
}
