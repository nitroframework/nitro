<?php

use Illuminate\Support\Facades\Route;
use Nitro\Fusion\Facades\Fusion;
use Nitro\Fusion\Tests\Fixtures\Pages\Article;
use Nitro\Fusion\Tests\Fixtures\Pages\Board;
use Nitro\Fusion\Tests\Fixtures\Pages\Counter;
use Nitro\Fusion\Tests\Fixtures\Pages\Devices;
use Nitro\Fusion\Tests\Fixtures\Pages\Feed;
use Nitro\Fusion\Tests\Fixtures\Pages\Gallery;
use Nitro\Fusion\Tests\Fixtures\Pages\Greeting;
use Nitro\Fusion\Tests\Fixtures\Pages\Inbox;
use Nitro\Fusion\Tests\Fixtures\Pages\Journal;
use Nitro\Fusion\Tests\Fixtures\Pages\Links;
use Nitro\Fusion\Tests\Fixtures\Pages\Live;
use Nitro\Fusion\Tests\Fixtures\Pages\MountedTimeline;
use Nitro\Fusion\Tests\Fixtures\Pages\Notice;
use Nitro\Fusion\Tests\Fixtures\Pages\Orders;
use Nitro\Fusion\Tests\Fixtures\Pages\Panels;
use Nitro\Fusion\Tests\Fixtures\Pages\Report;
use Nitro\Fusion\Tests\Fixtures\Pages\Shelf;
use Nitro\Fusion\Tests\Fixtures\Pages\Showcase;
use Nitro\Fusion\Tests\Fixtures\Pages\StudentPage;
use Nitro\Fusion\Tests\Fixtures\Pages\Terms;
use Nitro\Fusion\Tests\Fixtures\Pages\Transitions;

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
Route::fusion('/report', Report::class)->name('report');
Route::fusion('/feed', Feed::class)->name('feed');
Route::fusion('/transitions', Transitions::class)->name('transitions');
Route::fusion('/live', Live::class)->name('live');
Route::fusion('/devices', Devices::class)->name('devices');
Route::fusion('/timeline', MountedTimeline::class)->name('timeline');

Route::fusion('/orders', Orders::class)->name('orders');
Route::fusion('/inbox', Inbox::class)->name('inbox');
Route::fusion('/article', Article::class)->name('article');
Route::fusion('/notice', Notice::class)->name('notice');
Route::fusion('/terms', Terms::class)->name('terms');

/** Fusion::flash() and a redirect: the page it goes to delivers it. */
Route::get('/flash-and-redirect', function () {
    Fusion::flash('toast', 'Saved');

    return redirect('/greeting');
});

Route::get('/embed', fn () => view('embed'));
Route::get('/islands', fn () => view('islands'));

/** For the browser tests: the names the browser has for components and chunks (an obfuscated build's aliases). */
Route::get('/_test/names', function () {
    $fusion = app(\Nitro\Fusion\Fusion::class);
    $bundle = app(\Nitro\Fusion\Build\Bundle::class);

    return [
        'components' => collect($fusion->registry()->all())->keys()->mapWithKeys(fn (string $name) => [$name => $fusion->browserName($name)]),
        'chunks' => collect($bundle->groups())->mapWithKeys(fn (string $group) => [$group => $bundle->chunkId($group)]),
        'methods' => collect($fusion->registry()->all())->keys()
            ->flatMap(fn (string $name) => [...$fusion->meta($name)->browserMethods, ...$fusion->meta($name)->serverMethods])
            ->unique()->mapWithKeys(fn (string $method) => [$method => $fusion->names()?->method($method) ?? $method]),
        'properties' => collect($fusion->registry()->all())->keys()
            ->flatMap(fn (string $name) => array_keys($fusion->meta($name)->properties))
            ->unique()->mapWithKeys(fn (string $property) => [$property => $fusion->names()?->property($property) ?? $property]),
    ];
});
Route::get('/portals', fn () => view('portals'));
Route::get('/clock', fn () => view('clock'));
Route::middleware('auth')->get('/secure', fn () => view('secure'));
Route::get('/login', fn () => 'login')->name('login');

Route::get('/both', fn () => view('both'));

/** Routes the Links fixture generates URLs for: an optional parameter, a binding field. */
Route::get('/archive/{year?}', fn () => 'archive')->name('archive');
Route::get('/posts/{post:slug}', fn () => 'post')->name('posts.show');
