<?php

namespace Nitro\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\Scope;
use Nitro\Compiler\Template\TemplateCompiler;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @use in a component's view: its names resolve in the view's expressions and handlers as Blade's
 * use statements make them, across the whole view (an included view's too). What the view renders
 * is tests/Parity and the browser tests (/showcase).
 */
class UseDirectiveTest extends TestCase
{
    private const MONEY = 'Nitro.classes["Nitro\\\\Tests\\\\Fixtures\\\\Browser\\\\Money"]["format"]';

    private const PAID = 'Nitro.classes["Nitro\\\\Tests\\\\Fixtures\\\\Browser\\\\OrderStatus"].$cases["Paid"]';

    private function compile(string $template): TemplateCompiler
    {
        $compiler = new TemplateCompiler(fn (string $name) => Blade::getCustomDirectives()[$name] ?? in_array($name, ['include', 'use'], true));
        $compiler->compile($template, new Scope(properties: ['pence', 'status'], file: 'Component.php'), 'view.blade.php');

        return $compiler;
    }

    private function code(TemplateCompiler $compiler): string
    {
        return implode("\n", $compiler->expressions());
    }

    public static function forms(): array
    {
        return [
            'the class' => ["@use('Nitro\\Tests\\Fixtures\\Browser\\Money')\n<p>{{ Money::format(\$pence) }}</p>", self::MONEY],
            'double quotes, a leading backslash' => ["@use(\"\\Nitro\\Tests\\Fixtures\\Browser\\Money\")\n<p>{{ Money::format(\$pence) }}</p>", self::MONEY],
            'an alias' => ["@use('Nitro\\Tests\\Fixtures\\Browser\\Money', 'Cash')\n<p>{{ Cash::format(\$pence) }}</p>", self::MONEY],
            'a group' => ["@use('Nitro\\Tests\\Fixtures\\Browser\\{Money, OrderStatus}')\n<p>{{ Money::format(\$pence) }} {{ OrderStatus::Paid->label() }}</p>", self::PAID],
            'written after its use, as PHP\'s use statements apply to the whole file' => ["<p>{{ Money::format(\$pence) }}</p>\n@use('Nitro\\Tests\\Fixtures\\Browser\\Money')", self::MONEY],
            'in a handler' => ["@use('Nitro\\Tests\\Fixtures\\Browser\\OrderStatus')\n<button nitro:click=\"\$set('status', OrderStatus::Paid)\">Pay</button>", self::PAID],
        ];
    }

    #[DataProvider('forms')]
    public function test_names_resolve_through_use(string $template, string $expected): void
    {
        $this->boot('laravel');
        $compiler = $this->compile($template);

        $this->assertStringContainsString($expected, $this->code($compiler).json_encode($compiler->fragment()));
    }

    public function test_an_included_views_use_applies_to_it(): void
    {
        $this->boot('laravel');
        $compiler = $this->compile("<div>@include('uses.priced', ['pence' => \$pence])</div>");

        $this->assertStringContainsString(self::MONEY, $this->code($compiler));
    }

    public function test_without_use_the_short_name_is_refused(): void
    {
        $this->boot('laravel');

        try {
            $this->compile("<p>\n{{ Money::format(\$pence) }}</p>");
            $this->fail('Expected a compile error');
        } catch (CompileException $e) {
            $this->assertStringContainsString('Static calls are not supported in the browser', $e->getMessage());
            $this->assertSame(2, $e->sourceLine, 'its line');
        }
    }

    public function test_errors_keep_their_lines_with_imports(): void
    {
        $this->boot('laravel');

        try {
            $this->compile("@use('Nitro\\Tests\\Fixtures\\Browser\\Money')\n<p>\n\n{{ Money::nope(\$pence) }}</p>");
            $this->fail('Expected a compile error');
        } catch (CompileException $e) {
            $this->assertStringContainsString('Call to undefined method Nitro\Tests\Fixtures\Browser\Money::nope()', $e->getMessage());
            $this->assertSame(4, $e->sourceLine);
        }
    }

    public function test_an_escaped_use_is_text(): void
    {
        $this->boot('laravel');
        $compiler = $this->compile("<p>@@use('Nitro\\Tests\\Fixtures\\Browser\\Money')</p>");

        $this->assertSame('', TemplateCompiler::imports("<p>@@use('X')</p>"));
        $this->assertStringContainsString("@use('Nitro", json_encode($compiler->fragment(), JSON_UNESCAPED_SLASHES));
    }
}
