<?php

namespace Nitro\Tests\Compiler;

use Nitro\Compiler\Scope;
use Nitro\Compiler\Template\TemplateCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Clone mode (nitro.rendering = 'clone'): the views' largest static parts are marked (s: 1) for
 * the browser to copy; anything with data, a binding, a nitro: attribute or a form control is
 * not; and in create mode (the default) nothing is marked.
 */
class StaticPartsTest extends TestCase
{
    public function test_the_largest_static_parts_are_marked(): void
    {
        $root = $this->tree('<div><header class="top"><h1>Title</h1><p>Text <b>bold</b><!-- note --></p></header><p>{{ $name }}</p></div>');

        [$header, $dynamic] = $root['h'];
        $this->assertSame(1, $header['s'] ?? null, 'a static part');
        $this->assertArrayNotHasKey('s', $header['h'][0], 'only its outermost element is marked');
        $this->assertArrayNotHasKey('s', $dynamic, 'an echo in it');
        $this->assertArrayNotHasKey('s', $root, 'the component\'s root element');
    }

    public function test_parts_with_data_bindings_or_runtime_attributes_are_not_marked(): void
    {
        $root = $this->tree('<div>'
            .'<span class="{{ $name }}">a</span>'
            .'<span @class(["on" => true])>b</span>'
            .'<span @disabled($name)>c</span>'
            .'<button nitro:click="save">d</button>'
            .'<span nitro:transition>e</span>'
            .'<input type="text"><select><option>f</option></select><textarea>g</textarea>'
            .'<script>h</script>'
            .'<section><span>static</span><em>{{ $name }}</em></section>'
            .'</div>');

        foreach ($root['h'] as $i => $node) {
            $this->assertArrayNotHasKey('s', $node, "node {$i} ({$node['c']})");
        }

        /** Inside a part with data, the static elements are marked on their own. */
        $this->assertSame(1, $root['h'][9]['h'][0]['s'] ?? null);
    }

    public function test_parts_inside_blocks_and_slots_are_marked(): void
    {
        $root = $this->tree('<div>@if ($name)<p>Yes</p>@endif<nitro:card><i>In a slot</i></nitro:card></div>');

        $this->assertSame(1, $root['h'][0]['h'][0]['s'] ?? null, 'inside @if');
        $this->assertSame(1, $root['h'][1]['slots']['default']['h'][0]['s'] ?? null, 'in a child tag\'s slot');
    }

    public function test_create_mode_marks_nothing(): void
    {
        $js = (new TemplateCompiler(fn () => false))->compile('<div><p>Static</p></div>', $this->scope());

        $this->assertStringNotContainsString('"s"', $js);
    }

    /** The component's root element, as compiled in clone mode. */
    private function tree(string $template): array
    {
        $js = (new TemplateCompiler(fn () => false, cloneStatic: true))->compile($template, $this->scope(), 'view.blade.php');
        [$tree] = explode(",\nexpressions: [\n", substr($js, strlen('template: ')), 2);

        return json_decode($tree, true, flags: JSON_THROW_ON_ERROR)['h'][0];
    }

    private function scope(): Scope
    {
        return new Scope(properties: ['name'], clientMethods: ['save'], file: 'Component.php');
    }
}
