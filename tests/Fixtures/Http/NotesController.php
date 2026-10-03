<?php

namespace Nitro\Fusion\Tests\Fixtures\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Nitro\Fusion\Facades\Fusion;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page that is only a view (fusion/pages/notes.blade.php, @page(...)), as Inertia's: the
 * controller renders it and its actions are the controller's routes ($post, $delete in the view).
 * The notes are kept in the session.
 */
class NotesController
{
    public function index(): Response
    {
        return Fusion::render('notes', [
            'notes' => fn () => session('notes', []),
            'count' => fn () => count(session('notes', [])),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['title' => 'required|string|min:3']);
        session()->push('notes', $request->input('title'));
        Fusion::flash('toast', 'Added.');

        return back();
    }

    public function destroy(int $index): RedirectResponse
    {
        $notes = session('notes', []);
        unset($notes[$index]);
        session(['notes' => array_values($notes)]);

        return back();
    }
}
