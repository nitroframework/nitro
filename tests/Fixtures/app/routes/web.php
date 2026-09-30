<?php

use Illuminate\Support\Facades\Route;
use Nitro\Fusion\Tests\Fixtures\Components\Board;
use Nitro\Fusion\Tests\Fixtures\Components\Counter;
use Nitro\Fusion\Tests\Fixtures\Components\Journal;
use Nitro\Fusion\Tests\Fixtures\Components\Links;
use Nitro\Fusion\Tests\Fixtures\Components\Panels;
use Nitro\Fusion\Tests\Fixtures\Components\Shelf;
use Nitro\Fusion\Tests\Fixtures\Components\StudentPage;

Route::fusion('/students/{student}', StudentPage::class)->name('students.show');
Route::fusion('/counter', Counter::class)->name('counter');
Route::fusion('/board', Board::class)->name('board');
Route::fusion('/links', Links::class)->name('links');
Route::fusion('/journal', Journal::class)->name('journal');
Route::fusion('/shelf', Shelf::class)->name('shelf');
Route::fusion('/panels', Panels::class)->name('panels');
Route::fusion('/private', Counter::class)->middleware('auth');

Route::get('/embed', fn () => view('embed'));
Route::get('/portals', fn () => view('portals'));
Route::get('/clock', fn () => view('clock'));
Route::middleware('auth')->get('/secure', fn () => view('secure'));
Route::get('/login', fn () => 'login')->name('login');

Route::get('/both', fn () => view('both'));

/** Routes the Links fixture generates URLs for: an optional parameter, a binding field. */
Route::get('/archive/{year?}', fn () => 'archive')->name('archive');
Route::get('/posts/{post:slug}', fn () => 'post')->name('posts.show');
