<?php

namespace Nitro\Tests\Compiler;

use Illuminate\Support\Facades\Blade;
use Nitro\Compiler\Scope;
use Nitro\Compiler\Template\TemplateCompiler;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The table elements the HTML parser adds where a view leaves them out (Parts\Tree::implied()):
 * the tree has them as the parser's DOM does, so both renders give that DOM.
 */
class ImpliedElementsTest extends TestCase
{
    /** The view's tree as markup: its elements and static text; a block's nodes in [ ]. */
    private function shape(string $template): string
    {
        $compiler = new TemplateCompiler(fn (string $name) => Blade::getCustomDirectives()[$name] ?? false);
        $compiler->compile($template, new Scope(properties: ['flag'], file: 'Component.php'), 'view.blade.php');

        return $this->markup($compiler->tree()['h'] ?? []);
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private function markup(array $nodes): string
    {
        return implode('', array_map(fn (array $node) => match ($node['t']) {
            't' => "<{$node['c']}>".$this->markup($node['h'] ?? []).(in_array($node['c'], ['col'], true) ? '' : "</{$node['c']}>"),
            'x' => $node['c'] ?? '{}',
            'template' => '['.$this->markup($node['h'] ?? []).']',
            default => '',
        }, $nodes));
    }

    /** [the view, the DOM the HTML parser makes of it (JSDOM, as browsers)] */
    public static function tables(): array
    {
        return [
            'a row in a table' => ['<table><tr><td>x</td></tr></table>', '<table><tbody><tr><td>x</td></tr></tbody></table>'],
            'a cell in a table' => ['<table><td>x</td></table>', '<table><tbody><tr><td>x</td></tr></tbody></table>'],
            'cells in a tbody' => ['<table><tbody><td>a</td><th>b</th></tbody></table>', '<table><tbody><tr><td>a</td><th>b</th></tr></tbody></table>'],
            'a row after an implied row' => ['<table><tbody><td>a</td><tr><td>b</td></tr></tbody></table>', '<table><tbody><tr><td>a</td></tr><tr><td>b</td></tr></tbody></table>'],
            'cols in a table' => ['<table><col><col><tr><td>x</td></tr></table>', '<table><colgroup><col><col></colgroup><tbody><tr><td>x</td></tr></tbody></table>'],
            'whitespace around rows' => ["<table>\n<tr><td>x</td></tr>\n</table>", "<table>\n<tbody><tr><td>x</td></tr>\n</tbody></table>"],
            'a thead and an implied tbody' => ['<table><thead><tr><th>h</th></tr></thead><tr><td>x</td></tr></table>', '<table><thead><tr><th>h</th></tr></thead><tbody><tr><td>x</td></tr></tbody></table>'],
            'an explicit tbody ends an implied one' => ['<table><tr><td>a</td></tr><tbody><tr><td>b</td></tr></tbody></table>', '<table><tbody><tr><td>a</td></tr></tbody><tbody><tr><td>b</td></tr></tbody></table>'],
            'rows of a loop after a row: in its tbody' => ['<table><tr><td>a</td></tr>@if ($flag)<tr><td>b</td></tr>@endif</table>', '<table><tbody><tr><td>a</td></tr>[<tr><td>b</td></tr>]</tbody></table>'],
            'a table in a cell' => ['<table><td><table><tr><td>x</td></tr></table></td></table>', '<table><tbody><tr><td><table><tbody><tr><td>x</td></tr></tbody></table></td></tr></tbody></table>'],
        ];
    }

    #[DataProvider('tables')]
    public function test_the_tree_has_what_the_parser_adds(string $template, string $parsed): void
    {
        $this->boot('laravel');

        $this->assertSame($parsed, $this->shape($template));
    }
}
