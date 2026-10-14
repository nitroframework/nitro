<?php

namespace Nitro\Tests\Compiler;

use Illuminate\Support\Facades\Blade;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\Scope;
use Nitro\Compiler\Template\TemplateCompiler;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Blade's conditionals as Blade compiles them (CompilesConditionals, CompilesLoops): @if, @unless,
 * @isset and @empty($x) are PHP's if, their ends all PHP's endif; what is a PHP parse error in
 * Blade is refused, what PHP runs compiles.
 */
class ConditionalsTest extends TestCase
{
    private function compile(string $template): string
    {
        $compiler = new TemplateCompiler(fn (string $name) => Blade::getCustomDirectives()[$name] ?? false);

        return $compiler->compile($template, new Scope(properties: ['flag', 'items', 'name'], file: 'Component.php'), 'view.blade.php');
    }

    public static function invalid(): array
    {
        return [
            '@else after @else' => ['<p>@if ($flag) a @else b @else c @endif</p>', '@else after the @else of line 1'],
            '@elseif after @else' => ['<p>@if ($flag) a @else b @elseif ($name) c @endif</p>', '@elseif after the @else of line 1'],
            '@forelse without @empty' => ['<p>@forelse ($items as $item) a @endforelse</p>', '@forelse from line 1 has no @empty'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_what_blade_cannot_compile_is_refused(string $template, string $message): void
    {
        try {
            $this->compile($template);
            $this->fail("Expected a CompileException for: {$template}");
        } catch (CompileException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public static function valid(): array
    {
        return [
            '@elseif after @unless' => ['<p>@unless ($flag) a @elseif ($name) b @else c @endunless</p>'],
            '@elseif after @isset' => ['<p>@isset ($name) a @elseif ($flag) b @endisset</p>'],
            '@isset ended by @endif' => ['<p>@isset ($name) a @endif</p>'],
            '@if ended by @endunless' => ['<p>@if ($flag) a @endunless</p>'],
            '@forelse\'s @empty ended by @endif' => ['<p>@forelse ($items as $item) a @empty b @endif</p>'],
        ];
    }

    #[DataProvider('valid')]
    public function test_what_blade_compiles_compiles(string $template): void
    {
        $this->assertNotSame('', $this->compile($template));
    }
}
