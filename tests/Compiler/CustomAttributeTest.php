<?php

namespace Nitro\Tests\Compiler;

use Nitro\Compiler\CompileException;
use Nitro\Compiler\Globals;
use Nitro\Compiler\Scope;
use Nitro\Compiler\Template\TemplateCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The application's own nitro: attributes (nitro.attributes): passed through with their value
 * and modifiers, their element marked for the runtime's Nitro.directive(); what is not listed is
 * refused, and a listed name must be one of the application's. What runs is the browser tests'.
 */
class CustomAttributeTest extends TestCase
{
    private function compile(string $template, array $attributes = ['tooltip', 'auto-size']): string
    {
        return (new TemplateCompiler(fn () => false, attributes: $attributes))
            ->compile($template, new Scope(properties: ['count', 'note'], file: 'Component.php'), 'view.blade.php');
    }

    public function test_a_listed_attribute_is_passed_through_and_its_element_marked(): void
    {
        $js = $this->compile('<div><span nitro:tooltip.top.300ms="{{ $count }} clicks">?</span><textarea nitro:auto-size></textarea><p>plain</p></div>');

        $this->assertSame(2, substr_count($js, '"c": "nitro:h-directive"'), 'each element with one is marked');
        $this->assertStringContainsString('"c": "nitro:tooltip.top.300ms"', $js, 'the attribute as written, modifiers and all');
        $this->assertStringContainsString('"c": "nitro:auto-size"', $js, 'without a value');
        $this->assertStringContainsString('" clicks"', $js, 'its value rendered like any attribute\'s');
        $this->assertStringContainsString('$this.count', $js, 'the {{ }} in it reads the state');
    }

    public function test_json_in_the_value_passes_data(): void
    {
        $js = $this->compile('<div nitro:tooltip="@json($note)"></div>');

        $this->assertStringContainsString('"c": "nitro:tooltip"', $js);
        $this->assertStringContainsString('P.fns.json_encode($this.note, 15)', $js, '@json in the value: the value is the data as JSON');
    }

    public function test_an_unlisted_attribute_is_refused_with_how_to_add_it(): void
    {
        try {
            $this->compile('<div nitro:tooltip="x"></div>', []);
            $this->fail('Expected a compile error');
        } catch (CompileException $e) {
            $this->assertStringContainsString('Unknown attribute nitro:tooltip', $e->getMessage());
            $this->assertStringContainsString('add it to nitro.attributes and give it a Nitro.directive()', $e->getMessage());
            $this->assertSame(1, $e->sourceLine);
        }
    }

    public function test_listed_names(): void
    {
        $this->assertSame(['tooltip', 'auto-size', 'x2'], Globals::customAttributes(['tooltip', 'auto-size', 'x2', 'tooltip']));
    }

    public static function refusedNames(): array
    {
        return [
            'capitals' => ['Tooltip', 'is not an attribute name'],
            'a dot' => ['tool.tip', 'is not an attribute name'],
            'two hyphens' => ['auto--size', 'is not an attribute name'],
            'a trailing hyphen' => ['auto-', 'is not an attribute name'],
            'not a string' => [5, 'is not an attribute name'],
            'an event' => ['click', "nitro:click is Nitro's own"],
            'an attribute' => ['model', "nitro:model is Nitro's own"],
            'a hook' => ['poll', "nitro:poll is Nitro's own"],
            'the runtime\'s' => ['placeholder', "nitro:placeholder is Nitro's own"],
            'its marks' => ['h-directive', "nitro:h-directive is Nitro's own"],
        ];
    }

    #[DataProvider('refusedNames')]
    public function test_refused_names(mixed $name, string $message): void
    {
        try {
            Globals::customAttributes([$name]);
            $this->fail('Expected a compile error');
        } catch (CompileException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
