<?php

namespace Nitro\Fusion\Tests\Fixtures\Http;

use Nitro\Fusion\Facades\Fusion;
use Nitro\Fusion\Tests\Fixtures\Models\Student;
use Nitro\Fusion\Tests\Fixtures\Pages\Counter;
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

    /** A page with mount(): the props are its arguments, by name (here, by the page's name). */
    public function student(Student $student): Response
    {
        return Fusion::render('pages::student-page', ['student' => $student]);
    }
}
