<?php

namespace Nitro\Tests\Compiler;

use Illuminate\Support\Facades\Blade;
use Nitro\Compiler\Scope;
use Nitro\Compiler\Template\ComponentViews;
use Nitro\Compiler\Template\TemplateCompiler;
use Nitro\Tests\TestCase;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Each expression of a compiled view has its PHP twin, for the server's render: what the server
 * prints or reads there (TemplateCompiler::phpExpressions()). Every twin is PHP, with the
 * template's variables it takes as its parameters; those that print are run and compared with
 * what Blade prints for the same values. (The render as a whole is the conformance suite's.)
 */
class PhpTwinsTest extends TestCase
{
    private const DIRECTIVES = ['include', 'props', 'csrf', 'method', 'json', 'lang', 'choice', 'error', 'each'];

    /** @return array{0: TemplateCompiler, 1: list<array{locals: list<string>, php: string}|null>} */
    private function compile(string $template): array
    {
        $compiler = new TemplateCompiler(fn (string $name) => Blade::getCustomDirectives()[$name] ?? in_array($name, self::DIRECTIVES, true));
        $compiler->compile($template, new Scope(properties: ['count', 'name', 'items', 'flag', 'tone'], clientMethods: ['click'], file: 'Component.php'), 'view.blade.php');

        return [$compiler, $compiler->phpExpressions()];
    }

    public static function views(): array
    {
        return [
            'echoes' => ['<p title="{{ $name }}">{{ $count + 1 }} {!! $name !!}</p>'],
            'attribute directives' => ['<input @class([\'a\', \'b\' => $flag]) @style([\'color: red\' => $flag]) @checked($flag) @disabled(! $flag)>'],
            'conditionals' => ['<p>@if ($flag) a @elseif ($count > 1) b @else c @endif @unless ($flag) d @endunless @isset($name) e @endisset @empty($items) f @endempty</p>'],
            'loops' => ['<ul>@foreach ($items as $key => $item)<li>{{ $key }} {{ $loop->index }} {{ $item }}</li>@break($loop->last)@endforeach @forelse ($items as $item) x @empty y @endforelse @for ($i = 0; $i < $count; $i++){{ $i }}@endfor @while ($count < 0) z @endwhile</ul>'],
            'switch' => ['<p>@switch ($count) @case (1) one @break @default other @endswitch</p>'],
            'errors' => ["<p>@error('name') {{ \$message }} @enderror @error('name', 'login') {{ \$message }} @enderror {{ \$errors->first('name') }}</p>"],
            'helpers' => ["<form>@csrf @method('PUT') @json(\$items) @lang('messages.welcome') @choice('messages.apples', \$count)</form>"],
            'a handler and a child' => ['<div><button nitro:click="click">x</button><nitro:badge :tone="$tone" key="b" /></div>'],
            'a component and an include' => ["<div><x-badge :tone=\"\$tone\" data-n=\"{{ \$count }}\">{{ \$name }}</x-badge>@include('partials.heading', ['title' => \$name, 'level' => 1])</div>"],
        ];
    }

    #[DataProvider('views')]
    public function test_every_expression_has_a_php_twin_that_is_php(string $template): void
    {
        $this->boot('laravel');
        [$compiler, $twins] = $this->compile($template);
        $parser = (new ParserFactory)->createForVersion(PhpVersion::fromComponents(8, 3));

        $this->assertCount(count($compiler->expressions()), $twins, 'one twin (or null) for each expression');
        $views = app(ComponentViews::class);

        foreach ([[$compiler->expressions(), $twins], ...array_map(fn (string $id) => [$views->expressions($id), $views->phpExpressions($id)], $views->ids())] as [$expressions, $all]) {
            foreach ($all as $index => $twin) {
                /** Only what the browser alone runs has none: a handler. */
                if ($twin === null) {
                    $this->assertStringContainsString('$event', $expressions[$index], "expression {$index} has no twin but is not a handler");

                    continue;
                }

                $parameters = implode(', ', array_map(fn (string $local) => '$'.$local, $twin['locals']));
                $parser->parse("<?php fn ({$parameters}) => {$twin['php']};");
                $this->addToAssertionCount(1);
            }
        }
    }

    public static function printed(): array
    {
        /** [template, the view's variables, what Blade prints for the first expression that prints] */
        return [
            'an escaped echo' => ['<p>{{ $name }}</p>', ['name' => 'a <b> & "c"'], e('a <b> & "c"')],
            'arithmetic' => ['<p>{{ $count + 1 }}</p>', ['count' => 2], '3'],
            'a raw echo' => ['<p>{!! $name !!}</p>', ['name' => '<b>x</b>'], '<b>x</b>'],
            '@class' => ['<p @class([\'a\', \'b\' => $flag, \'c\' => ! $flag])>x</p>', ['flag' => true], 'a b'],
            '@json' => ['<p>@json($items)</p>', ['items' => ['a' => '<b>']], json_encode(['a' => '<b>'], 15)],
        ];
    }

    public function test_a_for_loops_twin_gives_its_iterations_as_php_runs_them(): void
    {
        $this->boot('laravel');
        [, $twins] = $this->compile('<p>@for ($i = 0; $i < $count; $i += 2){{ $i }}@endfor @while ($count < 0) x @endwhile</p>');

        $run = (function (array $__data, string $__php) {
            extract($__data);

            return eval("return {$__php};");
        })(...);

        $this->assertSame(['runs' => [[0], [2], [4]], 'after' => [6]], $run(['count' => 5], $twins[0]['php']), 'each iteration\'s $i, and what PHP leaves it');
        $this->assertSame(['runs' => [], 'after' => []], $run(['count' => 5], array_values(array_filter($twins, fn ($twin) => $twin !== null && str_contains($twin['php'], 'while')))[0]['php']), '@while whose condition fails');
    }

    public function test_a_bags_attribute_twin_prints_it_as_the_bag_does(): void
    {
        $this->boot('laravel');
        $this->compile('<div><x-badge title="say hi">x</x-badge></div>');
        $views = app(ComponentViews::class);
        $twins = array_merge(...array_map(fn (string $id) => array_filter($views->phpExpressions($id)), $views->ids()));
        $bag = array_values(array_filter($twins, fn (array $twin) => str_contains($twin['php'], 'str_replace')));

        $this->assertNotSame([], $bag);

        /** ComponentAttributeBag::__toString(): str_replace('"', '\\"', trim($value)), for a value as it holds it (here with a ", unescaped). */
        $twin = preg_replace('/htmlspecialchars\(\(string\) \$__value, ENT_QUOTES, \'UTF-8\', true\)/', '$__value', $bag[0]['php']);
        $printed = (fn () => eval("return {$twin};"))->call(new class { public string $tone = ' x"y '; });

        $this->assertSame(str_replace('"', '\\"', trim('badge badge- x"y ')), $printed);
    }

    /** @param  array<string, mixed>  $data */
    #[DataProvider('printed')]
    public function test_a_twin_prints_what_blade_prints(string $template, array $data, string $blade): void
    {
        $this->boot('laravel');
        [, $twins] = $this->compile($template);
        $twin = array_values(array_filter($twins))[0];

        $run = (function (array $__data, string $__php) {
            extract($__data);

            return eval("return {$__php};");
        })(...);

        $this->assertSame($blade, (string) $run($data, $twin['php']));
    }
}
