<?php

namespace Nitro\Tests\Compiler;

use Illuminate\Support\Facades\Blade;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\Scope;
use Nitro\Compiler\Template\TemplateCompiler;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Where the browser's HTML parser lets an element be (Parts\Nesting): markup it would rebuild is
 * a compile error, in a view and across a Blade component's call; markup it keeps compiles.
 */
class NestingTest extends TestCase
{
    private function compile(string $template): void
    {
        $compiler = new TemplateCompiler(fn (string $name) => Blade::getCustomDirectives()[$name] ?? in_array($name, ['include', 'props'], true));
        $compiler->compile($template, new Scope(properties: ['flag'], file: 'Component.php'), 'view.blade.php');
    }

    public static function rebuilt(): array
    {
        return [
            'a block element in a <p>' => ['<p><span><div>x</div></span></p>', '<div> inside <p>'],
            'a <li> in a <p>' => ['<p><li>x</li></p>', '<li> inside <p>'],
            'a <li> in an open <li>' => ['<ul><li><span><li>x</li></span></li></ul>', '<li> inside an open <li>'],
            'a <dt> in an open <dd>' => ['<dl><dd><dt>x</dt></dd></dl>', '<dt> inside an open <dd>'],
            'a button in a button' => ['<button><span><button>x</button></span></button>', '<button> inside <button>'],
            'a link in a link' => ['<a href="#"><span><a href="#">x</a></span></a>', '<a> inside <a>'],
            'a form in a form' => ['<form><div><form></form></div></form>', '<form> inside <form>'],
            'a heading in a heading' => ['<h1><h2>x</h2></h1>', '<h2> directly inside <h1>'],
            'an option in an option' => ['<select><option><option>x</option></option></select>', '<option> directly inside <option>'],
            'a row in a loop in a table' => ['<table>@foreach ([1] as $i)<tr><td>x</td></tr>@endforeach</table>', 'Put the rows of a <table>'],
            'a cell in a block in a tbody' => ['<table><tbody>@if ($flag)<td>x</td>@endif</tbody></table>', 'Put a <td> inside a <tr>'],
            'a col in a block in a table' => ['<table>@if ($flag)<col>@endif</table>', 'Put a <col> inside a <colgroup>'],
            'a row in an open row' => ['<table><tbody><tr><tr><td>x</td></tr></tr></tbody></table>', '<tr> inside an open <tr>'],
            'a cell in an open cell' => ['<table><tbody><tr><td><span><td>x</td></span></td></tr></tbody></table>', '<td> inside an open <td>'],
            'a row in a cell' => ['<table><tbody><tr><th><tr></tr></th></tr></tbody></table>', '<tr> inside an open <th>'],
            'a section in a cell' => ['<table><tbody><tr><td><tbody></tbody></td></tr></tbody></table>', '<tbody> inside an open <td>'],
            'markup in an option' => ['<select><option><b>x</b></option></select>', '<b> inside <option>'],
            'a component\'s markup in an option' => ['<select><option><x-badge /></option></select>', '<span> (in <x-badge>) inside <option>'],
            'a component\'s cell in a cell' => ['<table><tbody><tr><td><x-cell>x</x-cell></td></tr></tbody></table>', '<td> (in <x-cell>) inside an open <td>'],
            'a component\'s root in a <p>' => ['<p><x-card title="T">x</x-card></p>', '<div> (in <x-card>) inside <p>'],
            'a slot\'s block where the component puts it in a <p>' => ['<div><x-label><div>x</div></x-label></div>', '<div> (in the slot of <x-label>) inside <p>'],
        ];
    }

    #[DataProvider('rebuilt')]
    public function test_markup_the_parser_would_rebuild_is_refused(string $template, string $message): void
    {
        $this->boot('laravel');

        try {
            $this->compile($template);
            $this->fail("Expected a CompileException for: {$template}");
        } catch (CompileException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public static function kept(): array
    {
        return [
            'a block element in a button in a <p>' => ['<p><button><div>x</div></button></p>'],
            'a <li> in a nested list' => ['<ul><li><ul><li>x</li></ul></li></ul>'],
            'a link in a cell of a table in a link' => ['<a href="#"><table><tbody><tr><td><a href="#">x</a></td></tr></tbody></table></a>'],
            'a form in a template in a form' => ['<form><template><form></form></template></form>'],
            'a heading in a div in a heading' => ['<h1><span>x</span></h1><h2>y</h2>'],
            'rows in a tbody' => ['<table><tbody><tr><td>x</td></tr></tbody></table>'],
            'a table in a cell, with its rows' => ['<table><tbody><tr><td><table><tbody><tr><td>x</td></tr></tbody></table></td></tr></tbody></table>'],
            'options and groups in a select' => ['<select><optgroup label="g"><option>{{ $flag }}</option></optgroup><option>x</option></select>'],
            'cols in a colgroup' => ['<table><colgroup><col><col></colgroup><tbody><tr><td>x</td></tr></tbody></table>'],
            'a component\'s cell in a row' => ['<table><tbody><tr><x-cell>x</x-cell></tr></tbody></table>'],
            'a component\'s root where a block can be' => ['<div><x-card title="T">x</x-card></div>'],
            'a slot\'s block where the component puts it in a <div>' => ['<p><span>a</span></p><x-card title="T"><div>x</div></x-card>'],
        ];
    }

    #[DataProvider('kept')]
    public function test_markup_the_parser_keeps_compiles(string $template): void
    {
        $this->boot('laravel');
        $this->compile($template);
        $this->addToAssertionCount(1);
    }
}
