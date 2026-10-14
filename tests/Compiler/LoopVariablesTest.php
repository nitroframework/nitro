<?php

namespace Nitro\Tests\Compiler;

use Illuminate\Support\Facades\Blade;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\Scope;
use Nitro\Compiler\Template\TemplateCompiler;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A loop's variables after the loop (Blocks::leftRead()): PHP keeps their last value, and so do
 * Nitro's renders: the loop is told to set them where they are read (its directive's left). Read
 * after a block the loop was in ended, the value is that block's, which may not have run: a
 * compile error. (How they render: the conformance suite's "left" cases.)
 */
class LoopVariablesTest extends TestCase
{
    /** @return array<string, mixed> the tree */
    private function compile(string $template): array
    {
        $compiler = new TemplateCompiler(fn (string $name) => Blade::getCustomDirectives()[$name] ?? in_array($name, ['include', 'props'], true));
        $compiler->compile($template, new Scope(properties: ['name', 'items'], file: 'Component.php'), 'view.blade.php');

        return $compiler->tree();
    }

    /** @return list<list<int|null>> every loop's left, in the tree */
    private function lefts(array $node): array
    {
        $found = ($node['t'] ?? null) === 'template' ? $node['i'][0]['left'] ?? [] : [];

        foreach ($node['h'] ?? [] as $child) {
            array_push($found, ...$this->lefts($child));
        }

        return $found;
    }

    public static function left(): array
    {
        return [
            '@foreach\'s item' => ['<p>@foreach ($items as $name){{ $name }}@endforeach{{ $name }}</p>'],
            '@foreach\'s key' => ['<p>@foreach ($items as $key => $item)@endforeach{{ $key }}</p>'],
            '@forelse\'s item, after @endforelse' => ['<p>@forelse ($items as $name)a @empty b @endforelse{{ $name }}</p>'],
            '@for\'s variable' => ['<p>@for ($i = 0; $i < 2; $i++)x @endfor{{ $i }}</p>'],
            'in an included view after its loop' => ["<div>@include('partials.left')</div>"],
            'in a block after the loop' => ['<p>@foreach ($items as $name)a @endforeach @if ($name) {{ $name }} @endif</p>'],
            'an inner loop\'s, of the outer loop\'s name' => ['<p>@foreach ($items as $name)@foreach ($items as $name)@endforeach{{ $name }}@endforeach</p>'],
        ];
    }

    #[DataProvider('left')]
    public function test_a_loops_variable_read_after_it_is_set_by_the_loop(string $template): void
    {
        $this->boot('laravel');

        $this->assertNotSame([], $this->lefts($this->compile($template)));
    }

    public static function notLeft(): array
    {
        return [
            'inside the loop' => ['<p>@foreach ($items as $name){{ $name }}@endforeach</p>'],
            'in another loop of the same name' => ['<p>@foreach ($items as $name)a @endforeach @foreach ($items as $name){{ $name }}@endforeach</p>'],
            'in @forelse\'s @empty (no iteration set it)' => ['<p>@forelse ($items as $item)a @empty {{ $name }} @endforelse</p>'],
            'a property after a loop over other names' => ['<p>@foreach ($items as $item)a @endforeach{{ $name }}</p>'],
            'after an included view\'s loop of that name (the view\'s own)' => ["<div>@include('partials.loop-only'){{ \$name }}</div>"],
            'in the @else of the @if whose branch looped (that branch did not run)' => ['<p>@if ($items) @foreach ($items as $name)a @endforeach @else {{ $name }} @endif</p>'],
            '@for loops of one variable in different blocks (each assigns it)' => ['<div>@if ($items)@for ($i = 0; $i < 2; $i++)a @endfor @endif<p>@for ($i = 0; $i < 3; $i++){{ $i }}@endfor</p></div>'],
            'in a view included after the loop, given that name (its data is merged over)' => ["<div>@foreach (\$items as \$title)x @endforeach @include('partials.heading', ['title' => 'T', 'level' => 1])</div>"],
        ];
    }

    #[DataProvider('notLeft')]
    public function test_a_variable_that_is_not_left_is_not_set_by_a_loop(string $template): void
    {
        $this->boot('laravel');

        $this->assertSame([], $this->lefts($this->compile($template)));
    }

    public static function outOfReach(): array
    {
        return [
            'after the @if whose branch looped (it may have run)' => ['<p>@if ($items) @foreach ($items as $name)a @endforeach @else b @endif{{ $name }}</p>'],
            'an inner loop\'s, after the outer loop' => ['<p>@foreach ($items as $item)@foreach ($items as $name)@endforeach @endforeach{{ $name }}</p>'],
        ];
    }

    #[DataProvider('outOfReach')]
    public function test_a_loops_variable_read_after_a_block_it_was_in_is_refused(string $template): void
    {
        $this->boot('laravel');

        try {
            $this->compile($template);
            $this->fail("Expected a CompileException for: {$template}");
        } catch (CompileException $e) {
            $this->assertStringContainsString("is a loop's variable read after the block the loop is in", $e->getMessage());
        }
    }
}
