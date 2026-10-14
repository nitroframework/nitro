<?php

namespace Nitro\Tests\Compiler;

use Illuminate\Support\Facades\Blade;
use Nitro\Compiler\Scope;
use Nitro\Compiler\Template\TemplateCompiler;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The newline the HTML parser drops right after <pre>, <textarea> and <listing>: the compiler
 * drops it from static text; an element whose content starts with what renders is marked (nl),
 * for the browser to drop it from that (runtime/view/trim.js).
 */
class LeadingNewlineTest extends TestCase
{
    /** @return array<string, mixed> the view's root element */
    private function root(string $template): array
    {
        $compiler = new TemplateCompiler(fn (string $name) => Blade::getCustomDirectives()[$name] ?? false);
        $compiler->compile($template, new Scope(properties: ['name', 'flag'], file: 'Component.php'), 'view.blade.php');

        return $compiler->tree()['h'][0];
    }

    public static function views(): array
    {
        return [
            'an echo first' => ['<pre>{{ $name }}</pre>', true],
            'a block first' => ['<textarea>@if ($flag) x @endif</textarea>', true],
            'an echo in a listing' => ['<listing>{!! $name !!}</listing>', true],
            'static text first' => ["<pre>\n{{ \$name }}</pre>", false],
            'static text with no newline' => ['<pre>x{{ $name }}</pre>', false],
            'an element first' => ['<pre><b>{{ $name }}</b></pre>', false],
            'another element' => ['<div>{{ $name }}</div>', false],
        ];
    }

    #[DataProvider('views')]
    public function test_an_element_starting_with_what_renders_is_marked(string $template, bool $marked): void
    {
        $this->boot('laravel');

        $this->assertSame($marked, isset($this->root($template)['nl']));
    }

    public function test_static_text_loses_the_newline_and_keeps_a_second(): void
    {
        $this->boot('laravel');

        $this->assertSame("\nx", $this->root("<pre>\n\nx</pre>")['h'][0]['c']);
    }
}
