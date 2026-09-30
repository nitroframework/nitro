<?php

namespace Nitro\Fusion\Tests\Compiler;

use Nitro\Fusion\Compiler\Scope;
use Nitro\Fusion\Compiler\Subscriptions;
use Nitro\Fusion\Compiler\Template\TemplateCompiler;
use PHPUnit\Framework\TestCase;

/**
 * What a template expression reads: the properties the browser renders it again for.
 */
class SubscriptionsTest extends TestCase
{
    private function subscriptions(): Subscriptions
    {
        return new Subscriptions([
            'doubled' => "function () {\nconst \$this = this;\nreturn P.mul(\$this.count, 2);\n}",
            'label' => "function (\$prefix) {\nconst \$this = this;\nreturn P.concat(\$prefix, \$this.doubled(), \$this.\$computed(\"total\"));\n}",
            'total' => "function () {\nconst \$this = this;\nreturn P.fns.array_sum(\$this.items);\n}",
            'even' => "function (\$n) {\nconst \$this = this;\nreturn \$n === 0 ? true : \$this.odd(\$n - 1);\n}",
            'odd' => "function (\$n) {\nconst \$this = this;\nreturn \$n === 0 ? P.bool(\$this.flag) : \$this.even(\$n - 1);\n}",
        ], ['count', 'items', 'flag', 'name']);
    }

    public function test_properties_read_directly(): void
    {
        $this->assertSame(['count', 'name'], $this->subscriptions()->of('function ($this, $item) { return P.concat(P.toStr($this.name), $item, $this.count); }'));
        $this->assertSame([], $this->subscriptions()->of('function ($this, $item) { return P.toStr($item); }'), 'a loop variable is its loop\'s');
    }

    public function test_what_the_methods_it_calls_read(): void
    {
        $this->assertSame(['count'], $this->subscriptions()->of('function ($this) { return P.toStr($this.doubled()); }'));
        $this->assertSame(['count', 'items'], $this->subscriptions()->of('function ($this) { return P.toStr($this.label("x")); }'), 'through methods and #[Computed]');
        $this->assertSame(['items'], $this->subscriptions()->of('function ($this) { return P.toStr($this.$computed("total")); }'));
        $this->assertSame(['flag'], $this->subscriptions()->of('function ($this) { return $this.even(3); }'), 'recursion');
    }

    /** Viewi's nestedDependencies: a template variable reads what its block reads. */
    public function test_template_variables_read_what_their_blocks_read(): void
    {
        $compiler = new TemplateCompiler(fn () => false);
        $compiler->compile(
            '<ul>@foreach ($items as $item)<li>{{ $item }} {{ $loop->index }} {{ $count }}@foreach ($item as $x){{ $x }}{{ $loop->parent->index }}@endforeach</li>@endforeach'
            .' @error(\'a\'){{ $message }}@enderror</ul>',
            new Scope(properties: ['items', 'count']),
        );

        $subs = (new Subscriptions([], ['items', 'count']))->ofTemplate($compiler->expressions(), $compiler->expressionSources());
        $by = fn (string $js) => $subs[array_key_first(array_filter($compiler->expressions(), fn ($e) => str_contains($e, $js)))];

        $this->assertSame(['items'], $by('return P.toStr($item);'), '$item: its @foreach\'s data');
        $this->assertSame(['items'], $by('return P.toStr(P.prop($loop, "index"));'), '$loop too');
        $this->assertSame(['count'], $by('return P.toStr($this.count);'), 'a property: itself only');
        $this->assertSame(['items'], $by('return P.toStr($x);'), 'through the loop around');
        $this->assertSame(['items'], $by('P.prop(P.prop($loop, "parent"), "index")'), '$loop->parent');
        $this->assertSame(['$errors'], $by('return P.toStr($message);'), '@error\'s $message: the messages');
    }

    public function test_errors_and_the_unknown(): void
    {
        $this->assertSame(['$errors'], $this->subscriptions()->of('function ($this) { return $this.$errors.has("name"); }'));
        $this->assertSame(['*'], $this->subscriptions()->of('function ($this) { return $this.missing(); }'), 'a method the browser does not have: anything');
    }
}
