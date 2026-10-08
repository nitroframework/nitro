<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Blade\FusionTags;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The Blade precompiler: <fusion:...> in markup becomes a component; written in PHP, in a Blade
 * comment or in @verbatim it is text, left as it is.
 */
class FusionTagsTest extends TestCase
{
    public static function notMarkup(): array
    {
        return [
            '@php block' => ["@php\n    \$rows = [['`<fusion:students.table />`', 'a component']];\n@endphp"],
            '<?php ?>' => ["<?php \$tag = '<fusion:counter />'; ?>"],
            'echo' => ["{{ '<fusion:counter />' }}"],
            'raw echo' => ["{!! '<'.'fusion:counter />' !!} {!! \"<fusion:counter :start=\\\"5\\\" />\" !!}"],
            'escaped echo' => ['@{{ <fusion:counter /> }}'],
            'Blade comment' => ['{{-- <fusion:counter /> --}}'],
            '@verbatim' => ["@verbatim\n    <fusion:counter />\n@endverbatim"],
        ];
    }

    #[DataProvider('notMarkup')]
    public function test_a_tag_that_is_not_markup_is_left_as_it_is(string $source): void
    {
        $this->assertSame($source, FusionTags::compile($source));
    }

    #[DataProvider('frameworks')]
    public function test_the_fusion_directive_renders_a_component_from_php(string $framework): void
    {
        $this->boot($framework);
        $view = self::APP.'/resources/views/fusion-directive.blade.php';
        file_put_contents($view, "@php \$name = 'counter'; @endphp\n@fusion(\$name, ['count' => 7])\n");

        try {
            $html = view('fusion-directive')->render();
        } finally {
            @unlink($view);
        }

        $this->assertStringContainsString('<span class="count">7</span>', $html, 'its props, as a tag passes them');
        $this->assertCount(1, $this->snapshots($html), 'a live component, with its state');
    }

    public static function malformed(): array
    {
        return [
            'unquoted value' => ['<fusion:counter :count=$a />', 'Malformed attributes on <fusion:counter>: "=$"'],
            'stray quote' => ['<fusion:counter :count="$a" " />', 'Malformed'],
            'not an expression' => ["<fusion:counter :count=\"'\" />", 'The value of :count on <fusion:counter> is not a PHP expression'],
            'empty expression' => ['<fusion:counter :count="" />', 'The value of :count on <fusion:counter> is not a PHP expression'],
            'two statements' => ['<fusion:counter :count="1; 2" />', 'is not a PHP expression'],
            'closing tag' => ['<fusion:counter :count="1 ?> x <?php 2" />', 'is not a PHP expression'],
            'bad key' => ['<fusion:counter :key="[" />', 'The value of :key on <fusion:counter> is not a PHP expression'],
            'echo in a prop' => ['<fusion:counter label="{{ $a }}" />', 'Use :label="$value"'],
            'model segment not an expression' => ['<fusion:counter fusion:model="items.{{ ; }}" />', '{{ ; }} is not a PHP expression'],
        ];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_tag_is_a_compile_error_not_broken_php(string $source, string $message): void
    {
        $this->expectException(\Nitro\Fusion\Compiler\CompileException::class);
        $this->expectExceptionMessage($message);

        FusionTags::compile($source);
    }

    public function test_a_model_path_with_blade_in_it_is_the_parents_value_there(): void
    {
        $compiled = FusionTags::compile('<fusion:counter fusion:model="items.{{ $i }}.qty" />');

        $this->assertStringContainsString("['\$model' => \$items[(\$i)]['qty']]", $compiled);
    }

    public function test_a_tag_in_markup_around_them_is_still_a_component(): void
    {
        $source = "@php \$start = 5; @endphp\n<fusion:counter :start=\"\$start\" />\n{{ '<fusion:counter />' }}\n<fusion:counter />";
        $compiled = FusionTags::compile($source);

        $this->assertSame(2, substr_count($compiled, '->tag('), $compiled);
        $this->assertStringContainsString("@php \$start = 5; @endphp", $compiled);
        $this->assertStringContainsString("{{ '<fusion:counter />' }}", $compiled);
        $this->assertStringContainsString("->tag('counter', ['start' => \$start], null, 0)", $compiled, 'numbered as the browser numbers them: the echo is not a child');
        $this->assertStringContainsString("->tag('counter', [], null, 1)", $compiled);
    }
}
