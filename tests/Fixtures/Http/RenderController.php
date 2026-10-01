<?php

namespace Nitro\Fusion\Tests\Fixtures\Http;

use Nitro\Fusion\Facades\Fusion;
use Nitro\Fusion\Tests\Fixtures\Components\Store;
use Nitro\Fusion\Tests\Fixtures\Models\Student;
use Nitro\Fusion\Tests\Fixtures\Pages\Counter;
use Nitro\Fusion\Tests\Fixtures\Pages\Orders;
use Nitro\Fusion\Tests\Fixtures\Pages\Timeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
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

    /**
     * Merge props: 23 posts, 10 to a page (Fusion::scroll()); tags matched on their id; settings
     * merged key by key (the reload brings one key, the page keeps the other).
     */
    public function timeline(): Response
    {
        $posts = array_map(fn (int $n) => ['id' => $n, 'title' => "Post {$n}"], range(1, 23));
        $page = Paginator::resolveCurrentPage();

        return Fusion::render(Timeline::class, [
            'posts' => Fusion::scroll(fn () => new LengthAwarePaginator(array_slice($posts, ($page - 1) * 10, 10), 23, 10, $page)),
            'tags' => Fusion::merge(fn () => [['id' => 1, 'name' => 'php'], ['id' => 2, 'name' => 'js']])->matchOn('id'),
            'settings' => Fusion::deepMerge(fn () => request()->hasHeader('X-Fusion-Partial')
                ? ['theme' => ['color' => 'teal']]
                : ['theme' => ['color' => 'red', 'size' => 'large']]),
        ]);
    }

    /** Fusion::flash() and a redirect: the page it goes to delivers it. */
    public function flashAndRedirect(): RedirectResponse
    {
        Fusion::flash('toast', 'Saved');

        return redirect('/greeting');
    }

    /** A page with mount(): the props are its arguments, by name (here, by the page's name). */
    public function student(Student $student): Response
    {
        return Fusion::render('pages::student-page', ['student' => $student]);
    }
}
