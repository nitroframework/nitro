<?php

use Illuminate\Support\Facades\Route;
use Nitro\Facades\Nitro;
use Nitro\Tests\Fixtures\Pages\Article;
use Nitro\Tests\Fixtures\Pages\Board;
use Nitro\Tests\Fixtures\Pages\Counter;
use Nitro\Tests\Fixtures\Pages\Devices;
use Nitro\Tests\Fixtures\Pages\Feed;
use Nitro\Tests\Fixtures\Pages\Gallery;
use Nitro\Tests\Fixtures\Pages\Greeting;
use Nitro\Tests\Fixtures\Pages\Inbox;
use Nitro\Tests\Fixtures\Pages\Journal;
use Nitro\Tests\Fixtures\Pages\Libraries;
use Nitro\Tests\Fixtures\Pages\LibrariesLobby;
use Nitro\Tests\Fixtures\Pages\Lifecycle;
use Nitro\Tests\Fixtures\Pages\Links;
use Nitro\Tests\Fixtures\Pages\Live;
use Nitro\Tests\Fixtures\Pages\MountedTimeline;
use Nitro\Tests\Fixtures\Pages\Notice;
use Nitro\Tests\Fixtures\Pages\Orders;
use Nitro\Tests\Fixtures\Pages\Pair;
use Nitro\Tests\Fixtures\Pages\Panels;
use Nitro\Tests\Fixtures\Pages\Report;
use Nitro\Tests\Fixtures\Pages\Shelf;
use Nitro\Tests\Fixtures\Pages\Showcase;
use Nitro\Tests\Fixtures\Pages\StudentPage;
use Nitro\Tests\Fixtures\Pages\Terms;
use Nitro\Tests\Fixtures\Pages\ToolsLibraries;
use Nitro\Tests\Fixtures\Pages\Transitions;

Route::nitro('/students/{student}', StudentPage::class)->name('students.show');
Route::nitro('/counter', Counter::class)->name('counter');
Route::nitro('/board', Board::class)->name('board');
Route::nitro('/links', Links::class)->name('links');
Route::nitro('/journal', Journal::class)->name('journal');
Route::nitro('/lifecycle', Lifecycle::class)->name('lifecycle');
Route::nitro('/shelf', Shelf::class)->name('shelf');
Route::nitro('/panels', Panels::class)->name('panels');
Route::nitro('/private', Counter::class)->middleware('auth');
Route::nitro('/gallery', Gallery::class)->name('gallery');
Route::nitro('/private-gallery', Gallery::class)->middleware('auth');
Route::nitro('/showcase', Showcase::class)->name('showcase');
Route::nitro('/stress', \Nitro\Tests\Fixtures\Pages\Stress::class)->name('stress');
Route::nitro('/catalog', \Nitro\Tests\Fixtures\Pages\Catalog::class)->name('catalog');
Route::nitro('/nested', \Nitro\Tests\Fixtures\Pages\Nested::class)->name('nested');
Route::nitro('/outage', \Nitro\Tests\Fixtures\Pages\Outage::class)->name('outage');
Route::nitro('/engines', \Nitro\Tests\Fixtures\Pages\Engines::class)->name('engines');
Route::nitro('/parity', \Nitro\Tests\Fixtures\Pages\Parity::class)->name('parity');
Route::nitro('/talk', \Nitro\Tests\Fixtures\Pages\Talk::class)->name('talk');
Route::nitro('/greeting', Greeting::class)->name('greeting');
Route::nitro('/report', Report::class)->name('report');
Route::nitro('/feed', Feed::class)->name('feed');
Route::nitro('/transitions', Transitions::class)->name('transitions');
Route::nitro('/live', Live::class)->name('live');
Route::nitro('/devices', Devices::class)->name('devices');
Route::nitro('/timeline', MountedTimeline::class)->name('timeline');

Route::nitro('/orders', Orders::class)->name('orders');
Route::nitro('/inbox', Inbox::class)->name('inbox');
Route::nitro('/article', Article::class)->name('article');
Route::nitro('/notice', Notice::class)->name('notice');
Route::nitro('/terms', Terms::class)->name('terms');
Route::nitro('/chapters/{number}/{part?}', \Nitro\Tests\Fixtures\Pages\Chapter::class)->where('number', '[0-9]+')->name('chapters.show');
Route::nitro('/pair/{student}', Pair::class)->name('pair');
Route::nitro('/shop', \Nitro\Tests\Fixtures\Pages\Shop::class)->name('shop');
Route::nitro('/shop/cart', \Nitro\Tests\Fixtures\Pages\ShopCart::class)->name('shop.cart');

/** Nitro::flash() and a redirect: the page it goes to delivers it. */
Route::get('/flash-and-redirect', function () {
    Nitro::flash('toast', 'Saved');

    return redirect('/greeting');
});

Route::get('/embed', fn () => view('embed'));
/** #[Html]: a body made on the server (HtmlTest). */
Route::get('/excerpt', fn () => view('excerpt'));
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

/** Third-party libraries for the browser tests (Nitro's devDependencies), and the directives that bind them. */
Route::get('/_test/lib/{file}', function (string $file) {
    $files = [
        'jquery.js' => 'node_modules/jquery/dist/jquery.js',
        'select2.js' => 'node_modules/select2/dist/js/select2.full.js',
        'flatpickr.js' => 'node_modules/flatpickr/dist/flatpickr.js',
        'tom-select.js' => 'node_modules/tom-select/dist/js/tom-select.complete.js',
        'choices.js' => 'node_modules/choices.js/public/assets/scripts/choices.js',
        'trix.js' => 'node_modules/trix/dist/trix.umd.js',
        'quill.js' => 'node_modules/quill/dist/quill.js',
        'sortable.js' => 'node_modules/sortablejs/Sortable.js',
        'nouislider.js' => 'node_modules/nouislider/dist/nouislider.js',
        'imask.js' => 'node_modules/imask/dist/imask.js',
        'libraries.js' => 'tests/Fixtures/app/resources/js/libraries.js',
    ];
    abort_unless(isset($files[$file]), 404);

    return response(file_get_contents(base_path('../../../'.$files[$file])), 200, ['Content-Type' => 'text/javascript']);
});
Route::nitro('/libraries', Libraries::class);
Route::nitro('/libraries-lobby', LibrariesLobby::class);
Route::nitro('/tools', ToolsLibraries::class);
Route::get('/islands', fn () => view('islands'));
Route::get('/scripts-first', fn () => view('scripts-first'));

/** For the browser tests: the names the browser has for components and chunks (an obfuscated build's aliases). */
Route::get('/_test/names', function () {
    $registry = app(\Nitro\Support\Registry::class);
    $components = app(\Nitro\Compiler\Components::class);
    $names = app(\Nitro\Build\BrowserNames::class);
    $bundle = app(\Nitro\Build\Bundle::class);

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
