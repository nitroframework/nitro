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
use Nitro\Fusion\Tests\Fixtures\Pages\Lifecycle;
use Nitro\Fusion\Tests\Fixtures\Pages\Links;
use Nitro\Fusion\Tests\Fixtures\Pages\Live;
use Nitro\Fusion\Tests\Fixtures\Pages\MountedTimeline;
use Nitro\Fusion\Tests\Fixtures\Pages\Notice;
use Nitro\Fusion\Tests\Fixtures\Pages\Orders;
use Nitro\Fusion\Tests\Fixtures\Pages\Pair;
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
Route::fusion('/lifecycle', Lifecycle::class)->name('lifecycle');
Route::fusion('/shelf', Shelf::class)->name('shelf');
Route::fusion('/panels', Panels::class)->name('panels');
Route::fusion('/private', Counter::class)->middleware('auth');
Route::fusion('/gallery', Gallery::class)->name('gallery');
Route::fusion('/private-gallery', Gallery::class)->middleware('auth');
Route::fusion('/showcase', Showcase::class)->name('showcase');
Route::fusion('/stress', \Nitro\Fusion\Tests\Fixtures\Pages\Stress::class)->name('stress');
Route::fusion('/catalog', \Nitro\Fusion\Tests\Fixtures\Pages\Catalog::class)->name('catalog');
Route::fusion('/engines', \Nitro\Fusion\Tests\Fixtures\Pages\Engines::class)->name('engines');
Route::fusion('/parity', \Nitro\Fusion\Tests\Fixtures\Pages\Parity::class)->name('parity');
Route::fusion('/talk', \Nitro\Fusion\Tests\Fixtures\Pages\Talk::class)->name('talk');
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
Route::fusion('/chapters/{number}/{part?}', \Nitro\Fusion\Tests\Fixtures\Pages\Chapter::class)->where('number', '[0-9]+')->name('chapters.show');
Route::fusion('/pair/{student}', Pair::class)->name('pair');
Route::fusion('/shop', \Nitro\Fusion\Tests\Fixtures\Pages\Shop::class)->name('shop');
Route::fusion('/shop/cart', \Nitro\Fusion\Tests\Fixtures\Pages\ShopCart::class)->name('shop.cart');

/** Fusion::flash() and a redirect: the page it goes to delivers it. */
Route::get('/flash-and-redirect', function () {
    Fusion::flash('toast', 'Saved');

    return redirect('/greeting');
});

Route::get('/embed', fn () => view('embed'));
Route::get('/fallbacks', fn () => view('fallbacks'));

/** Vue for the browser tests: its global build, then the fixture's .vue views and the adapter (js/test/support/build-adapters.mjs). */
Route::get('/_test/vue.js', fn () => response(
    file_get_contents(base_path('../../../node_modules/vue/dist/vue.global.js'))."\n".file_get_contents(base_path('../../../js/test/e2e/.built/vue.js')),
    200,
    ['Content-Type' => 'text/javascript'],
));
Route::get('/_test/solid.js', fn () => response(file_get_contents(base_path('../../../js/test/e2e/.built/solid.js')), 200, ['Content-Type' => 'text/javascript']));
Route::get('/_test/svelte.js', fn () => response(file_get_contents(base_path('../../../js/test/e2e/.built/svelte.js')), 200, ['Content-Type' => 'text/javascript']));
Route::get('/_test/react.js', fn () => response(file_get_contents(base_path('../../../js/test/e2e/.built/react.js')), 200, ['Content-Type' => 'text/javascript']));
Route::get('/islands', fn () => view('islands'));
Route::get('/scripts-first', fn () => view('scripts-first'));

/** For the browser tests: the names the browser has for components and chunks (an obfuscated build's aliases). */
Route::get('/_test/names', function () {
    $registry = app(\Nitro\Fusion\Support\Registry::class);
    $components = app(\Nitro\Fusion\Compiler\Components::class);
    $names = app(\Nitro\Fusion\Build\BrowserNames::class);
    $bundle = app(\Nitro\Fusion\Build\Bundle::class);

    return [
        'components' => collect($registry->all())->keys()->mapWithKeys(fn (string $name) => [$name => $names->component($name)]),
        'chunks' => collect($bundle->groups())->mapWithKeys(fn (string $group) => [$group => $bundle->chunkId($group)]),
        'methods' => collect($registry->all())->keys()
            ->flatMap(fn (string $name) => [...$components->meta($name)->browserMethods, ...$components->meta($name)->serverMethods])
            ->unique()->mapWithKeys(fn (string $method) => [$method => $names->obfuscator()?->method($method) ?? $method]),
        'properties' => collect($registry->all())->keys()
            ->flatMap(fn (string $name) => array_keys($components->meta($name)->properties))
            ->unique()->mapWithKeys(fn (string $property) => [$property => $names->obfuscator()?->property($property) ?? $property]),
    ];
});
Route::get('/portals', fn () => view('portals'));
Route::get('/clock', fn () => view('clock'));
Route::middleware('auth')->get('/secure', fn () => view('secure'));
Route::get('/login', fn () => 'login')->name('login');

/** What logging in and out do to the session: a login regenerates it (its data kept), a logout invalidates it. */
Route::get('/_test/login', fn () => tap('in', fn () => session()->regenerate()));
Route::get('/_test/logout', fn () => tap('out', fn () => session()->invalidate()));

Route::get('/both', fn () => view('both'));

/** Routes the Links fixture generates URLs for: an optional parameter, a binding field. */
Route::get('/archive/{year?}', fn () => 'archive')->name('archive');
Route::get('/posts/{post:slug}', fn () => 'post')->name('posts.show');
