<?php

namespace Nitro\Tests\Feature;

use Nitro\Blade\NitroTags;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The Blade precompiler: <nitro:...> in markup becomes a component; written in PHP, in a Blade
 * comment or in @verbatim it is text, left as it is.
 */
class NitroTagsTest extends TestCase
{
    public static function notMarkup(): array
    {
        return [
            '@php block' => ["@php\n    \$rows = [['`<nitro:students.table />`', 'a component']];\n@endphp"],
            '<?php ?>' => ["<?php \$tag = '<nitro:counter />'; ?>"],
            'echo' => ["{{ '<nitro:counter />' }}"],
            'raw echo' => ["{!! '<'.'nitro:counter />' !!} {!! \"<nitro:counter :start=\\\"5\\\" />\" !!}"],
            'escaped echo' => ['@{{ <nitro:counter /> }}'],
            'Blade comment' => ['{{-- <nitro:counter /> --}}'],
            '@verbatim' => ["@verbatim\n    <nitro:counter />\n@endverbatim"],
        ];
    }

    #[DataProvider('notMarkup')]
    public function test_a_tag_that_is_not_markup_is_left_as_it_is(string $source): void
    {
        $this->assertSame($source, NitroTags::compile($source));
    }

    #[DataProvider('frameworks')]
    public function test_the_nitro_directive_renders_a_component_from_php(string $framework): void
    {
        $this->boot($framework);
        $view = self::APP.'/resources/views/nitro-directive.blade.php';
        file_put_contents($view, "@php \$name = 'counter'; @endphp\n@nitro(\$name, ['count' => 7])\n");

        try {
            $html = view('nitro-directive')->render();
        } finally {
            @unlink($view);
        }

        $this->assertStringContainsString('<span class="count">7</span>', $html, 'its props, as a tag passes them');
        $this->assertCount(1, $this->snapshots($html), 'a live component, with its state');
    }

    public static function malformed(): array
    {
        return [
            'unquoted value' => ['<nitro:counter :count=$a />', 'Malformed attributes on <nitro:counter>: "=$"'],
            'stray quote' => ['<nitro:counter :count="$a" " />', 'Malformed'],
            'not an expression' => ["<nitro:counter :count=\"'\" />", 'The value of :count on <nitro:counter> is not a PHP expression'],
            'empty expression' => ['<nitro:counter :count="" />', 'The value of :count on <nitro:counter> is not a PHP expression'],
            'two statements' => ['<nitro:counter :count="1; 2" />', 'is not a PHP expression'],
            'closing tag' => ['<nitro:counter :count="1 ?> x <?php 2" />', 'is not a PHP expression'],
            'bad key' => ['<nitro:counter :key="[" />', 'The value of :key on <nitro:counter> is not a PHP expression'],
            'echo in a prop' => ['<nitro:counter label="{{ $a }}" />', 'Use :label="$value"'],
            'model segment not an expression' => ['<nitro:counter nitro:model="items.{{ ; }}" />', '{{ ; }} is not a PHP expression'],
        ];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_tag_is_a_compile_error_not_broken_php(string $source, string $message): void
    {
        $this->expectException(\Nitro\Compiler\CompileException::class);
        $this->expectExceptionMessage($message);

        NitroTags::compile($source);
    }

    public function test_a_model_path_with_blade_in_it_is_the_parents_value_there(): void
    {
        $compiled = NitroTags::compile('<nitro:counter nitro:model="items.{{ $i }}.qty" />');

        $this->assertStringContainsString("['\$model' => \$items[(\$i)]['qty']]", $compiled);
    }

    public function test_a_tag_in_markup_around_them_is_still_a_component(): void
    {
        $source = "@php \$start = 5; @endphp\n<nitro:counter :start=\"\$start\" />\n{{ '<nitro:counter />' }}\n<nitro:counter />";
        $compiled = NitroTags::compile($source);

        $this->assertSame(2, substr_count($compiled, '->tag('), $compiled);
        $this->assertStringContainsString("@php \$start = 5; @endphp", $compiled);
        $this->assertStringContainsString("{{ '<nitro:counter />' }}", $compiled);
        $this->assertStringContainsString("->tag('counter', ['start' => \$start], null, 0)", $compiled, 'numbered as the browser numbers them: the echo is not a child');
        $this->assertStringContainsString("->tag('counter', [], null, 1)", $compiled);
    }
}
