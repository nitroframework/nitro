<?php

use Illuminate\Support\Facades\Route;
use Nitro\Fusion\Tests\Fixtures\Http\RenderController;
use Nitro\Fusion\Tests\Fixtures\Pages\Board;
use Nitro\Fusion\Tests\Fixtures\Pages\Counter;
use Nitro\Fusion\Tests\Fixtures\Pages\Gallery;
use Nitro\Fusion\Tests\Fixtures\Pages\Greeting;
use Nitro\Fusion\Tests\Fixtures\Pages\Journal;
use Nitro\Fusion\Tests\Fixtures\Pages\Links;
use Nitro\Fusion\Tests\Fixtures\Pages\Panels;
use Nitro\Fusion\Tests\Fixtures\Pages\Shelf;
use Nitro\Fusion\Tests\Fixtures\Pages\Showcase;
use Nitro\Fusion\Tests\Fixtures\Pages\StudentPage;

Route::fusion('/students/{student}', StudentPage::class)->name('students.show');
Route::fusion('/counter', Counter::class)->name('counter');
Route::fusion('/board', Board::class)->name('board');
Route::fusion('/links', Links::class)->name('links');
Route::fusion('/journal', Journal::class)->name('journal');
Route::fusion('/shelf', Shelf::class)->name('shelf');
Route::fusion('/panels', Panels::class)->name('panels');
Route::fusion('/private', Counter::class)->middleware('auth');
Route::fusion('/gallery', Gallery::class)->name('gallery');
Route::fusion('/private-gallery', Gallery::class)->middleware('auth');
Route::fusion('/showcase', Showcase::class)->name('showcase');
Route::fusion('/greeting', Greeting::class)->name('greeting');

/** Pages from a controller: Fusion::render(). */
Route::get('/rendered/counter', [RenderController::class, 'counter'])->name('rendered.counter');
Route::get('/rendered/students/{student}', [RenderController::class, 'student'])->name('rendered.student');

Route::get('/embed', fn () => view('embed'));
Route::get('/portals', fn () => view('portals'));
Route::get('/clock', fn () => view('clock'));
Route::middleware('auth')->get('/secure', fn () => view('secure'));
Route::get('/login', fn () => 'login')->name('login');

Route::get('/both', fn () => view('both'));

/** Routes the Links fixture generates URLs for: an optional parameter, a binding field. */
Route::get('/archive/{year?}', fn () => 'archive')->name('archive');
Route::get('/posts/{post:slug}', fn () => 'post')->name('posts.show');
