<?php

namespace Nitro\Fusion\Tests\Parity;

use Illuminate\Support\MessageBag;
use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Component;
use Nitro\Fusion\State;
use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Tests\Fixtures\Models\Student;
use Nitro\Fusion\Tests\Support\Node;
use Nitro\Fusion\Tests\TestCase;

/**
 * Each fixture view, in many states, rendered by Blade on the server and by its compiled render
 * function in Node: the two must be the same DOM (child components compared by key only).
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
        $page = fn () => $this->fusion()->fillPortals(
            '<aside id="dock">'.$this->fusion()->portal('dock').'</aside><div id="toasts">'.$this->fusion()->portal('toasts').'</div>'
        );

        foreach ($this->states() as $i => [$name, $state, $errors]) {
            $emptyPage = $page();
            $component = $this->component($name, $state, $errors);
            $meta = $this->fusion()->meta($name);
            $ssr = $this->fusion()->renderer()->render($component, $meta, $this->fusion()->key());

            $data = State::of($component);
            foreach ($data as $property => $value) {
                if ($value instanceof \Illuminate\Database\Eloquent\Model) {
                    $data[$property] = $value->toArray();
                }
            }

            $cases[] = [
                'id' => $i,
                'name' => $name,
                'componentId' => 'p',
                'state' => Wire::encode($data),
                'errors' => Wire::encode($errors),
                'ssr' => $ssr,
                'page' => $page(),
                'emptyPage' => $emptyPage,
            ];
        }

        $failures = Node::run('js/test/support/run-templates.mjs', [
            /** The bundle and its #[LazyLoad] chunks: every component. */
            'bundle' => implode("\n", $this->app->make(Bundle::class)->compileAll()),
            'csrf' => csrf_token(),
            /** What @fusionScripts ships for Laravel's helpers (routes, translations, public config). */
            'globals' => $this->fusion()->globals(),
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
        $meta = $this->fusion()->meta($name);
        $component = $this->app->make($meta->class);
        $component->fusion()->id = 'p';
        $component->fusion()->name = $name;
        $component->fusion()->errors = new MessageBag($errors);

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
            ['wallet', [], []],
            ['wallet', ['amount' => 30, 'balance' => 80], ['amount' => ['Not enough money.']]],
            ['wallet', ['amount' => 150], []],
            ['student-page', ['student' => Student::find(1), 'note' => 'Hi <b>there</b>'], []],
            ['student-page', ['student' => Student::find(1)], []],
            ['journal', [], []],
            ['journal', ['ready' => true, 'note' => '<b>x</b>', 'saved' => ['one', 'two']], []],
            ['panels', [], []],
            ['panels', ['panel' => 'search-box', 'stack' => ['search-box', 'card', 'card']], []],
            ['panels', ['panel' => '', 'stack' => []], []],
            ['links', [], []],
            ['links', ['studentId' => 42, 'name' => 'ünï <b>x</b>', 'slug' => 'a/b?c#d', 'student' => Student::find(1)], []],
        ];
    }
}
