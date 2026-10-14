<?php

namespace Nitro\Tests\Parity;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\MessageBag;
use Nitro\Build\Bundle;
use Nitro\Component;
use Nitro\Compiler\StateProperties;
use Nitro\Support\Wire;
use Nitro\Tests\Fixtures\Models\Student;
use Nitro\Tests\Support\Node;
use Nitro\Tests\TestCase;
use Nitro\Upload;

/**
 * Each fixture view, in many states, rendered by the server (the compiled tree, View\TreeRenderer)
 * and by its compiled render function in Node: the two must be the same DOM (child components
 * compared by key only).
 */
class TemplateParityTest extends TestCase
{
    public function test_compiled_views_render_what_blade_renders(): void
    {
        $this->boot('laravel');

        /** As during a page request: a request with a started session (@csrf reads its token). */
        $request = \Illuminate\Http\Request::create('/');
        $session = $this->app->make('session')->driver();
        $session->start();
        $request->setLaravelSession($session);
        $this->app->instance('request', $request);

        $cases = [];

        /** A page's portals (@portal in its layout), as the response has them: @teleport content goes there. */
        $page = fn () => app(\Nitro\Rendering\Portals::class)->fill(
            '<aside id="dock">'.app(\Nitro\Rendering\Portals::class)->portal('dock').'</aside><div id="toasts">'.app(\Nitro\Rendering\Portals::class)->portal('toasts').'</div>'
        );

        foreach ($this->states() as $i => $case) {
            [$name, $state, $errors] = $case;
            $emptyPage = $page();
            $component = $this->component($name, $state, $errors);

            /** The stores it uses: a new request's, with the items given (the server renders with them, the browser reads them). */
            $stores = [];

            foreach (app(\Nitro\Compiler\Components::class)->meta($name)->stores as $property => $class) {
                $store = $component->{$property};
                $store->items = $case[3] ?? [];
                $stores[$property] = ['name' => app(\Nitro\Compiler\Components::class)->meta($class)->name, 'state' => Wire::encode(StateProperties::values($store))];
            }
            $meta = app(\Nitro\Compiler\Components::class)->meta($name);
            $ssr = app(\Nitro\Rendering\ComponentRenderer::class)->render($component, $meta);

            $data = StateProperties::values($component);

            $cases[] = [
                'id' => $i,
                'name' => $name,
                'componentId' => 'p',
                'state' => Wire::encode($data),
                'errors' => Wire::encode($errors),
                'ssr' => $ssr,
                'page' => $page(),
                'emptyPage' => $emptyPage,
                'stores' => (object) $stores,
            ];
        }

        $failures = Node::run('js/test/support/run-templates.mjs', [
            /** The bundle and its #[LazyLoad] chunks: every component. */
            'bundle' => implode("\n", $this->app->make(Bundle::class)->compileAll()),
            'csrf' => csrf_token(),
            /** What @nitroScripts ships for Laravel's helpers (routes, translations, public config). */
            'globals' => app(\Nitro\Rendering\Scripts::class)->globals(),
            'cases' => $cases,
        ]);

        $this->assertSame([], array_map(
            fn ($f) => "#{$f['id']} {$f['name']}\n--- server\n{$f['expected']}\n--- browser\n{$f['actual']}",
            $failures
        ));
        $this->assertGreaterThan(15, count($cases));
    }

    private function component(string $name, array $state, array $errors): Component
    {
        $meta = app(\Nitro\Compiler\Components::class)->meta($name);
        $component = $this->app->make($meta->class);
        $component->nitro()->id = 'p';
        $component->nitro()->name = $name;
        $component->nitro()->errors = new MessageBag($errors);
        app(\Nitro\State\Stores::class)->give($component, $meta);

        foreach ($state as $property => $value) {
            $component->{$property} = $value;
        }

        return $component;
    }

    /** @return list<array{0: string, 1: array, 2: array}> */
    private function states(): array
    {
        $todos = [
            ['id' => 1, 'title' => 'Write <tests> & "docs"', 'done' => false],
            ['id' => 2, 'title' => "It's done", 'done' => true],
            ['id' => 7, 'title' => 'Ünïcödé ✓', 'done' => false],
        ];

        $grace = Student::create(['name' => 'Grace <Hopper>', 'email' => 'grace@example.com', 'mentor_id' => 1]);
        $jpeg = Upload::receive(UploadedFile::fake()->image('Birthday Cake «1».jpg', 40, 30));
        $pdf = Upload::receive(UploadedFile::fake()->create('menu.pdf', 3, 'application/pdf'));

        return [
            ['counter', [], []],
            ['counter', ['count' => 7, 'step' => 2, 'history' => [2, 4, 7]], []],
            ['counter', ['count' => -3, 'history' => [-1, -2, -3]], []],
            ['counter', ['count' => 5, 'history' => [5]], []],
            ['counter', ['count' => 6, 'history' => [1, 2, 3, 4, 5, 6]], []],
            ['todo-list', [], []],
            ['todo-list', ['todos' => $todos], []],
            ['todo-list', ['todos' => $todos, 'filter' => 'done'], []],
            ['todo-list', ['todos' => $todos, 'filter' => 'open', 'title' => 'typing'], []],
            ['todo-list', ['todos' => [['id' => 3, 'title' => 'Only', 'done' => false]]], []],
            ['todo-list', ['todos' => $todos, 'filter' => 'nope'], []],
            ['todo-list', [], ['todos' => ['The todos field is required.']]],
            ['todo-list', ['todos' => $todos], ['todos.0.title' => ['Too long.', 'Second.']]],
            ['todo-item', ['todo' => $todos[0]], []],
            ['todo-item', ['todo' => $todos[1], 'editing' => true], []],
            ['todo-item', ['todo' => $todos[2]], []],
            /** Components reading a store (a fourth item: the store's items). */
            ['cart-badge', [], [], []],
            ['cart-badge', [], [], [['name' => 'Pen <b>', 'price' => 2], ['name' => 'Book', 'price' => 10]]],
            ['cart-list', [], [], []],
            ['cart-list', [], [], [['name' => 'Pen <b>', 'price' => 2], ['name' => "It's", 'price' => 0]]],
            ['wallet', [], []],
            ['wallet', ['amount' => 30, 'balance' => 80], ['amount' => ['Not enough money.']]],
            ['wallet', ['amount' => 150], []],
            ['pages::student-page',['student' => Student::find(1), 'note' => 'Hi <b>there</b>'], []],
            ['pages::student-page',['student' => Student::find(1)], []],
            ['journal', [], []],
            ['journal', ['ready' => true, 'note' => '<b>x</b>', 'saved' => ['one', 'two']], []],
            ['pages::panels',[], []],
            ['pages::panels',['panel' => 'search-box', 'stack' => ['search-box', 'card', 'card']], []],
            ['pages::panels',['panel' => '', 'stack' => []], []],
            ['pages::links',[], []],
            ['pages::links',['studentId' => 42, 'name' => 'ünï <b>x</b>', 'slug' => 'a/b?c#d', 'student' => Student::find(1)], []],
            /** Uploads in the state: what the view shows of them (Globals::UPLOAD_METHODS) is the server's. */
            ['pages::gallery',[], []],
            ['pages::gallery',['photo' => $jpeg, 'photos' => [$jpeg, $pdf], 'caption' => 'Cakes'], []],
            ['pages::gallery',['photo' => $pdf], ['photo' => ['The photo field must be an image.']]],
            ['pages::gallery',['stored' => ['photo' => 'gallery/cover.jpg', 'photos' => ['one.jpg']]], []],
            /** Blade components and @include, inlined: what Blade renders of them. */
            ['pages::showcase',[], []],
            ['pages::showcase',['featured' => true, 'tone' => 'red', 'clicks' => 3, 'note' => '<b>x</b> & "y"'], []],
            ['pages::showcase',['cakes' => [], 'note' => '0'], []],
            /** Models read with -> (and [ ]) in the browser, their loaded relations too. */
            ['pages::pair',['maybe' => Student::find(1), 'sure' => Student::find(1), 'mentee' => Student::with('mentor')->find($grace->id), 'everyone' => Student::with('mentor')->orderBy('id')->get()], []],
            ['pages::pair',['sure' => Student::find(1), 'mentee' => Student::find(1)], []],
            /** The stress page: everything together. */
            ['pages::stress',[], []],
            ['pages::stress',['filter' => 'a', 'sort' => 'total', 'selected' => 3, 'focus' => \Nitro\Tests\Fixtures\Browser\OrderStatus::Shipped, 'log' => ['bump 1.0', 'pick 3']], []],
            ['pages::stress',['sort' => 'customer', 'orders' => [], 'draft' => ['customer' => 'x', 'lines' => [['name' => 'Ladoo', 'price' => 150, 'qty' => 0]]]], ['draft.customer' => ['The draft.customer field must be at least 2 characters.']]],
            ['pages::stress',['filter' => 'zzz'], []],
            ['stress-row',['order' => ['id' => 1, 'customer' => 'Ada <b>', 'status' => 'paid', 'lines' => [['name' => 'Rasmalai', 'price' => 450, 'qty' => 2]]], 'selected' => true, 'focus' => 'paid'], []],
            ['stress-row',['order' => ['id' => 3, 'customer' => "It's", 'status' => 'shipped', 'lines' => [['name' => 'A', 'price' => 1, 'qty' => 5], ['name' => 'Jalebi', 'price' => 99, 'qty' => 120], ['name' => 'never', 'price' => 1, 'qty' => 1]]]], []],
            ['stress-row',['order' => ['id' => 2, 'customer' => 'Grace', 'status' => 'draft', 'lines' => []]], []],
            ['qty-input', ['value' => 4], []],
        ];
    }
}
