<?php

namespace Nitro\Tests\Compiler;

use Nitro\Compiler\CompileException;
use Nitro\Compiler\Scope;
use Nitro\Compiler\Template\TemplateCompiler;
use Nitro\Compiler\Transpiler;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What cannot run in the browser fails the build with a message and the line it is on.
 */
class CompileErrorsTest extends TestCase
{
    private function scope(): Scope
    {
        return new Scope(
            properties: ['count', 'items', 'balance', 'post'],
            clientMethods: ['helper', 'total'],
            serverMethods: ['save'],
            file: 'Component.php',
            locked: ['balance'],
            computed: ['total'],
        );
    }

    public static function methods(): array
    {
        return [
            'new' => ['$x = new \DateTime();', 'Creating objects (new)', 3],
            'static call' => ['return Post::find(1);', 'Static calls', 3],
            'Str method not in the browser' => ['return \Illuminate\Support\Str::uuid();', 'Str::uuid() is not available in the browser', 3],
            'preg count reference' => ['return preg_replace("/a/", "b", "a", -1, $count);', "preg_replace()'s argument 5 (a reference) is not available in the browser", 3],
            'echo' => ['echo $this->count;', 'echo has no output', 3],
            'unknown function' => ["\n\nreturn dd(\$this->count);", 'The function dd() is not available', 5],
            'undefined property' => ['return $this->missing;', '$this->missing is not a public property', 3],
            'undefined method' => ['$this->missing();', '$this->missing() is not a method', 3],
            'reload of an expression' => ['$this->reload($name);', 'reload() takes the names of public properties', 3],
            'server result used' => ['$x = $this->save();', 'runs on the server', 3],
            'variable variable' => ['return $$name;', 'Variable variables', 3],
            'closure by reference' => ['$f = function () use (&$x) {};', 'capturing by reference', 3],
            'try' => ['try { $a = 1; } catch (\Exception $e) {}', 'try/catch', 3],
            'instanceof' => ['return $this->post instanceof Post;', 'instanceof', 3],
            'object alias write' => ['$p = $this->post; $p->title = "x";', 'values are copies in the browser', 3],
            'locked write' => ['$this->balance = 5;', '#[Locked]', 3],
            'locked write in array' => ["\n\$this->balance += 5;", '#[Locked]', 4],
            'computed write' => ['$this->total = 5;', 'is #[Computed]', 3],
            'foreach by reference' => ['foreach ($this->items as &$item) {}', 'foreach by reference', 3],
            'break too far' => ['foreach ($this->items as $i) { break 2; }', "Cannot 'break 2'", 3],
            'magic constant' => ['return __LINE__;', 'Magic constants', 3],
            'unknown constant' => ['return SOME_CONSTANT;', 'The constant SOME_CONSTANT', 3],
            'named arguments' => ['return str_pad(string: "x", length: 2);', 'Named arguments', 3],
            'server-side Nitro' => ['Nitro::flash("saved", true);', 'Nitro:: runs on the server; in the browser, Nitro::browser()', 3],
            'unknown browser API' => ['Nitro::browser()->camera();', 'Nitro::browser()->camera() does not exist: it has clipboard(), storage(), session(), canvas(), socket()', 3],
            'unknown browser API method' => ["\$c = Nitro::browser()->canvas('c');\n\$c->drawCircle();", 'Nitro::browser()->canvas()->drawCircle() does not exist', 4],
            'browser API without its argument' => ['Nitro::browser()->canvas();', 'Nitro::browser()->canvas($ref) takes 1 argument', 3],
            'browser API with too many' => ['Nitro::browser()->storage()->get("a", 1, 2);', 'Nitro::browser()->storage()->get($key, $default) takes 1 to 2 arguments', 3],
            'browser API named arguments' => ['Nitro::browser()->storage()->get(key: "a");', 'takes its arguments in order', 3],
        ];
    }

    public function test_browser_apis_compile_to_their_functions(): void
    {
        $source = "<?php class C {\n    public function run() {\n        \$canvas = \\Nitro\\Facades\\Nitro::browser()->canvas('chart')->size(10, 10);\n        \$canvas->arc(1, 2, 3);\n        \$draw = fn () => \$canvas->fill();\n        Nitro::browser()->session()->set('k', \$this->count);\n    }\n}";
        $method = (new NodeFinder)->findFirstInstanceOf((new ParserFactory)->createForNewestSupportedVersion()->parse($source), Stmt\ClassMethod::class);
        $transpiler = new Transpiler;
        $js = $transpiler->method($method, $this->scope());

        $this->assertStringContainsString('($canvas = P.fns._browser_canvas($this.$browser, "chart").size(10, 10))', $js);
        $this->assertStringContainsString('$canvas.arc(1, 2, 3, 0, 6.283185307179586)', $js, 'arguments left out take the PHP defaults');
        $this->assertStringContainsString('$canvas.fill()', $js, 'a captured variable keeps its API');
        $this->assertStringContainsString('P.fns._browser_session($this.$browser).set("k", $this.count)', $js);
        $this->assertSame(['_browser_canvas', '_browser_session'], array_keys($transpiler->uses['functions']));
    }

    #[DataProvider('methods')]
    public function test_browser_methods_reject_what_cannot_run_there(string $body, string $message, int $line): void
    {
        $source = "<?php class C {\n    public function run() {\n        {$body}\n    }\n}";
        $method = (new NodeFinder)->findFirstInstanceOf((new ParserFactory)->createForNewestSupportedVersion()->parse($source), Stmt\ClassMethod::class);

        try {
            (new Transpiler)->method($method, $this->scope());
            $this->fail("Expected a CompileException for: {$body}");
        } catch (CompileException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertSame('Component.php', $e->sourceFile);
            $this->assertSame($line, $e->sourceLine, $e->getMessage());
        }
    }

    public static function templates(): array
    {
        return [
            '@php' => ["<div>\n@php \$x = 1; @endphp\n</div>", '@php cannot render in the browser', 2],
            /** @include and <x-components> are inlined (BladeInliner, tests/Feature/InlinedViewsTest). */
            '@push' => ["<div>\n\n@push('scripts') x</div>", '@push cannot render in the browser', 3],
            'unknown nitro attribute' => ['<div nitro:clik="save"></div>', 'Unknown attribute nitro:clik', 1],
            'unknown modifier' => ['<form nitro:submit.prevnt="save"></form>', 'Unknown modifier .prevnt', 1],
            'two letters are no key' => ['<input nitro:keydown.ctrl.kk="open">', 'Unknown modifier .kk', 1],
            'a capital is no key' => ['<input nitro:keydown.K="open">', 'Unknown modifier .K', 1],
            'unknown navigate modifier' => ["<div>\n<a href=\"/x\" nitro:navigate.keypres>x</a></div>", 'Unknown modifier .keypres on nitro:navigate (it takes .keypress, .preserve-scroll, .transition)', 2],
            'prefetch on navigate' => ['<div><a href="/x" nitro:navigate.visible>x</a></div>', 'Unknown modifier .visible on nitro:navigate', 1],
            'unknown prefetch modifier' => ['<div><a href="/x" nitro:navigate nitro:prefetch.hover>x</a></div>', 'Unknown modifier .hover on nitro:prefetch (it takes .visible, .wait, .keep, or a time first: .150ms)', 1],
            'a wait without a time' => ['<div><a href="/x" nitro:navigate nitro:prefetch.wait>x</a></div>', 'nitro:prefetch.wait needs a time', 1],
            'a keep that is no time' => ['<div><a href="/x" nitro:navigate nitro:prefetch.keep.soon>x</a></div>', 'nitro:prefetch.keep needs a time', 1],
            'a time not first' => ['<div><a href="/x" nitro:navigate nitro:prefetch.visible.60s>x</a></div>', 'Unknown modifier .60s on nitro:prefetch', 1],
            'a wait in view' => ['<div><a href="/x" nitro:navigate nitro:prefetch.visible.wait.100ms>x</a></div>', 'nitro:prefetch.visible has no wait', 1],
            'a bare wait in view' => ['<div><a href="/x" nitro:navigate nitro:prefetch.150ms.visible>x</a></div>', 'nitro:prefetch.visible has no wait', 1],
            'unknown current modifier' => ['<div><a href="/x" nitro:current.exat="on">x</a></div>', 'Unknown modifier .exat on nitro:current', 1],
            'unknown confirm modifier' => ['<div><button nitro:click="helper" nitro:confirm.promt="x">x</button></div>', 'Unknown modifier .promt on nitro:confirm', 1],
            'unknown loading modifier' => ["<div>\n<span nitro:loading.slow>x</span></div>", 'nitro:loading takes .delay (.delay.500ms) first if it waits, then nothing or one of .remove, .class, .class.remove, .attr (not .slow)', 2],
            'loading modifiers out of order' => ['<div><span nitro:loading.remove.class="a">x</span></div>', '(not .remove.class)', 1],
            'delay after the form' => ['<div><span nitro:loading.class.delay="a">x</span></div>', '(not .class.delay)', 1],
            'delay with a bad time' => ['<div><span nitro:loading.delay.fast>x</span></div>', '(not .delay.fast)', 1],
            'delay on nitro:dirty' => ['<div><span nitro:dirty.delay>x</span></div>', 'nitro:dirty takes nothing or one of .remove, .class, .class.remove, .attr (not .delay)', 1],
            'modifier on nitro:ignore' => ['<div><span nitro:ignore.self>x</span></div>', 'nitro:ignore takes no modifiers (.self)', 1],
            'modifier on nitro:target' => ['<div><span nitro:loading nitro:target.x="save">x</span></div>', 'nitro:target takes no modifiers', 1],
            'unknown poll modifier' => ['<div nitro:poll.visibel="helper"></div>', 'Unknown modifier .visibel on nitro:poll', 1],
            'poll calls an unknown method' => ["<div>\n<span nitro:poll.5s=\"nope\"></span></div>", '$this->nope() is not a method', 2],
            'init takes no interval' => ['<div nitro:init.5s="helper"></div>', 'Unknown modifier .5s on nitro:init', 1],
            'writing a computed value' => ['<div><button nitro:click="$set(\'total\', 1)">x</button></div>', 'total is not a public property', 1],
            'echo in a handler' => ['<button nitro:click="remove({{ $count }})"></button>', 'without {{ }}', 1],
            'handler that is not a call' => ['<button nitro:click="$count + 1"></button>', 'An event handler is a method call', 1],
            'model on a locked property' => ['<input nitro:model="balance">', 'is #[Locked]', 1],
            'model on an unknown property' => ['<input nitro:model="nope">', 'nope is not a public property', 1],
            'undefined variable' => ["<div>\n{{ \$undefined }}</div>", 'Undefined variable $undefined', 2],
            'assignment in an echo' => ['<div>{{ $count = 5 }}</div>', 'cannot assign', 1],
            'server call while rendering' => ['<div>{{ $this->save() }}</div>', 'runs on the server', 1],
            'unclosed @if' => ["<div>\n@if (\$count)\n</div>", '</div> closes an element opened outside the @if of line 2', 3],
            'route name not a literal' => ['<a href="{{ route($count) }}">x</a>', 'route() in the browser takes the route name as a string literal', 1],
            'translation key not a literal' => ['<p>{{ __($count) }}</p>', '__() in the browser takes the translation key as a string literal', 1],
            'trans with a locale' => ["<p>{{ __('Hi', [], 'fr') }}</p>", "__() in the browser uses the page's locale", 1],
            'url without a path' => ['<p>{{ url() }}</p>', 'url() in the browser needs a path', 1],
            'unclosed @if at the end' => ["<div></div>\n@if (\$count)\n", '@if is not closed', 2],
            'element closed outside its block' => ["<div>\n@if (\$count)\n<p>\n@endif\n</p></div>", '<p> (line 3) must be closed before @endif', 4],
            'element crossing @else' => ["<div>@if (\$count)<p>@else</p>@endif</div>", 'must be closed before @else', 1],
            'unclosed element' => ["<div>\n<span>\n</div>", '</div> does not match <span> from line 2', 3],
            'element left open' => ["<div>\n<span></div>", '</div> does not match <span>', 2],
            'end tag closing nothing' => ['<div></div></p>', '</p> closes nothing that is open', 1],
            'control directive in a tag' => ["<div\n@if (\$count) hidden @endif>x</div>", '@if cannot sit inside a tag', 2],
            'directive in an attribute value' => ['<div class="a @if($count) b @endif">x</div>', '@if cannot be used inside an attribute value', 1],
            'attribute directive outside a tag' => ['<div>@disabled($count)</div>', '@disabled belongs inside a tag', 1],
            'rows without tbody' => ["<table>\n<tr><td>1</td></tr></table>", 'Put the rows of a <table> inside <tbody>', 2],
            'rows in a loop without tbody' => ['<table>@foreach ($items as $item)<tr><td>1</td></tr>@endforeach</table>', 'inside <tbody>', 1],
            'echo in a tag' => ['<div {{ $count }}>x</div>', 'An echo inside a tag must be an attribute value', 1],
            'mismatched end' => ["<div>\n@if (\$count)\n@endforeach\n</div>", 'Unexpected @endforeach', 3],
            'child tag not closed' => ["<div><nitro:card>\ntext</div>", '</div> closes an element opened outside', 2],
            'child tag left open' => ["<div></div>\n<nitro:card>text", '<nitro:card> is not closed', 2],
            'child tag closed by another' => ['<div><nitro:card>x</nitro:panel></div>', '</nitro:panel> closes no <nitro:panel> (<nitro:card> from line 1 is open)', 1],
            'slot outside a child tag' => ['<div><nitro:slot name="x">y</nitro:slot></div>', '<nitro:slot> belongs directly inside a <nitro:...> tag', 1],
            'slot inside an element' => ['<div><nitro:card><p><nitro:slot name="x">y</nitro:slot></p></nitro:card></div>', '<nitro:slot> belongs directly inside a <nitro:...> tag (<p> from line 1 is still open)', 1],
            'slot without a name' => ['<div><nitro:card><nitro:slot>y</nitro:slot></nitro:card></div>', '<nitro:slot> needs a name', 1],
            'slot not closed' => ['<div><nitro:card><nitro:slot name="x">y</nitro:card></div>', '</nitro:card> closes no <nitro:card> (<nitro:slot name="x"> from line 1 is open)', 1],
            'teleport not closed' => ["<div>@teleport('body')<p>x</p></div>", '</div> closes an element opened outside the @teleport of line 1', 1],
            'element closed in a teleport' => ["<div><p>@teleport('body')x</p>@endteleport</div>", '</p> closes an element opened outside the @teleport of line 1', 1],
            'teleport without its end' => ["<div>@teleport('body')<p>x</p>@endif</div>", 'Unexpected @endif (@teleport from line 1 is still open)', 1],
            'continue in a forelse\'s empty block' => ['<div>@forelse($items as $i)<b>x</b>@empty @continue @endforelse</div>', '@continue outside of a loop', 1],
            'break inside an element' => ["<div>@foreach(\$items as \$i)<b>\n@if(\$i) @break @endif</b>@endforeach</div>", '@break inside <b> (line 1): Blade would leave the <b> without its end tag', 2],
            'continue inside an element of a switch' => ['<div>@switch($count)@case(1)<b>@break</b>@endswitch</div>', '@break inside <b>', 1],
            'div inside a paragraph' => ["<div><p>\n<span><div>x</div></span></p></div>", '<div> inside <p> (line 1): the browser ends the <p> before a <div>', 2],
            'hr inside a paragraph' => ['<div><p>a<hr>b</p></div>', '<hr> inside <p>', 1],
            'break out of a teleport' => ['<div>@foreach($items as $i)@teleport(\'body\')@break@endteleport@endforeach</div>', '@break inside @teleport cannot leave a loop around the @teleport', 1],
            'teleport in a script' => ["<div><script>@teleport('body')</script></div>", '@teleport goes between elements', 1],
            'teleport to an expression' => ['<div>@teleport($name)x@endteleport</div>', "@teleport takes the name of a @portal, as a string literal: @teleport('modals')", 1],
            'teleport to a selector' => ["<div>@teleport('#modals')x@endteleport</div>", '@teleport takes the name of a @portal', 1],
            'teleport in a teleport' => ["<div>@teleport('a')@teleport('b')x@endteleport@endteleport</div>", '@teleport cannot be inside another @teleport', 1],
            'unknown visible modifier' => ['<div><p nitro:visible.twice="helper">x</p></div>', 'Unknown modifier .twice on nitro:visible', 1],
            'island inside a component' => ["<div>\n<nitro:counter nitro:hydrate.idle /></div>", 'nitro:hydrate and nitro:static are for islands, components placed in Blade views: <nitro:counter> is a child of this component', 2],
            'unknown transition modifier' => ['<div><p nitro:transition.durration.200ms>x</p></div>', 'Unknown modifier .durration on nitro:transition', 1],
            'transition duration without a time' => ['<div><p nitro:transition.duration>x</p></div>', 'nitro:transition.duration needs a time', 1],
            'transition with both modes' => ['<div><p nitro:transition.out-in.in-out>x</p></div>', 'nitro:transition takes one mode: .out-in or .in-out', 1],
            'transition origin without a side' => ['<div><p nitro:transition.scale.origin.middle>x</p></div>', 'nitro:transition.origin needs a side', 1],
            'set with named arguments' => ['<div><button nitro:click="$set(value: 1, property: \'count\')">x</button></div>', '$set takes its arguments in order', 1],
            'reload of no property' => ['<div><button nitro:click="$reload(\'nothing\')">x</button></div>', '$reload takes the names of public properties', 1],
            'deferred by expression' => ['<div>@deferred($name)x@enddeferred</div>', "@deferred names properties as strings: @deferred('stats')", 1],
            'placeholder outside deferred' => ['<div>@if($count)<p>x</p>@placeholder<p>y</p>@endif</div>', '@placeholder outside of @deferred', 1],
            'deferred without its end' => ['<div>@deferred(\'count\')<p>x</p>@endif</div>', 'Unexpected @endif (@deferred from line 1 is still open)', 1],
            'dynamic component without is' => ['<div><nitro:dynamic-component /></div>', '<nitro:dynamic-component> needs :is, the name of the component to render', 1],
            'unclosed echo' => ["<div>\n{{ \$count </div>", 'Unclosed {{', 2],
            'malformed foreach' => ['<div>@foreach ($items) @endforeach</div>', 'Malformed @foreach', 1],
            'text before the first case' => ["<div>@switch(\$count) text @case(1) @endswitch</div>", 'Only @case and @default can follow @switch', 1],
        ];
    }

    #[DataProvider('templates')]
    public function test_templates_reject_what_cannot_render_in_the_browser(string $template, string $message, int $line): void
    {
        $compiler = new TemplateCompiler(fn (string $name) => in_array($name, ['php', 'endphp', 'include', 'push', 'section'], true));

        try {
            $compiler->compile($template, $this->scope(), 'view.blade.php');
            $this->fail("Expected a CompileException for: {$template}");
        } catch (CompileException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertSame('view.blade.php', $e->sourceFile);
            $this->assertSame($line, $e->sourceLine, $e->getMessage());
        }
    }

    public function test_handlers_can_call_methods_named_like_php_keywords(): void
    {
        $scope = new Scope(properties: ['page'], clientMethods: ['goTo', 'print', 'list', 'save'], file: 'Component.php');
        $compiler = new TemplateCompiler(fn () => false);

        $js = $compiler->compile('<div><button nitro:click="goTo($page - 1)">a</button><button nitro:click="print">b</button>'
            .'<button nitro:click="list(); save">c</button><button nitro:click="$set(\'page\', 1)">d</button></div>', $scope);

        $this->assertStringContainsString('$this.goTo(P.sub($this.page, 1))', $js);
        $this->assertStringContainsString('$this.print()', $js);
        $this->assertStringContainsString('$this.list()', $js);
        $this->assertStringContainsString('$this.save()', $js);
        $this->assertStringContainsString('$this.$set("page", 1)', $js);
    }

    public function test_handler_strings_are_left_as_written(): void
    {
        $scope = new Scope(properties: ['note'], clientMethods: ['notify', 'go'], file: 'Component.php');
        $compiler = new TemplateCompiler(fn () => false);

        $js = $compiler->compile('<div><button nitro:click="$set(\'note\', \'a;b;\')">a</button>'
            .'<button nitro:click="$set(\'note\', \'x; go(1)\'); notify(\'item;save\')">b</button></div>', $scope);

        $this->assertStringContainsString('$this.$set("note", "a;b;")', $js);
        $this->assertStringContainsString('$this.$set("note", "x; go(1)")', $js);
        $this->assertStringContainsString('$this.notify("item;save")', $js);
    }

    /**
     * A template compiled: its tree (decoded) and its expressions (the JavaScript of each).
     *
     * @return array{0: array, 1: list<string>}
     */
    private function compiled(string $template, ?Scope $scope = null): array
    {
        $js = (new TemplateCompiler(fn () => false))->compile($template, $scope ?? $this->scope());
        [$tree, $expressions] = explode(",\nexpressions: [\n", substr($js, strlen('template: ')), 2);

        return [
            json_decode($tree, true, flags: JSON_THROW_ON_ERROR),
            array_values(array_filter(preg_split('/,?\n(?=\/\* \d+ \*\/ )/', substr($expressions, 0, -2)))),
        ];
    }

    public function test_the_view_becomes_a_tree_and_its_dynamic_parts_expressions(): void
    {
        [$tree, $expressions] = $this->compiled('<ul class="list x-{{ $count }}">@foreach ($items as $i => $item)<li @class([\'on\' => $loop->first])>{{ $item }}</li>@endforeach</ul>');

        $ul = $tree['h'][0];
        $this->assertSame(['t' => 't', 'c' => 'ul'], array_intersect_key($ul, ['t' => 1, 'c' => 1]));
        $this->assertSame([['c' => 'list x-'], ['e' => 1, 'code' => 0]], $ul['a'][0]['h']);

        $loop = $ul['h'][0];
        $this->assertSame('template', $loop['t']);
        $this->assertSame(['c' => 'foreach', 'forData' => 1, 'key' => 0, 'item' => 1, 'loop' => 2], $loop['i'][0]);
        $this->assertSame('li', $loop['h'][0]['c']);
        $this->assertSame('class', $loop['h'][0]['a'][0]['c']);

        /** Each expression: the component and the variables in scope, by position. */
        $this->assertStringContainsString('function ($this) { return P.toStr($this.count); }', $expressions[0]);
        $this->assertStringContainsString('function ($this) { return $this.items; }', $expressions[1]);
        $this->assertStringContainsString('function ($this, $i, $item, $loop) { return B.classes(', $expressions[2]);
        $this->assertStringContainsString('function ($this, $i, $item, $loop) { return P.toStr($item); }', $expressions[3]);
    }

    public function test_blocks_are_template_nodes_with_their_directive(): void
    {
        [$tree] = $this->compiled('<div>@if ($count > 1)<b>many</b>@elseif ($count)<i>one</i>@else<s>none</s>@endif '
            .'@forelse ($items as $item){{ $item }}@empty<em>no items</em>@endforelse '
            .'@error(\'count\')<p>{{ $message }}</p>@enderror '
            .'@switch($count)@case(1)<b>1</b>@break @default<b>?</b>@endswitch</div>');

        $blocks = array_map(fn ($node) => $node['i'][0]['c'], array_values(array_filter($tree['h'][0]['h'], fn ($n) => $n['t'] === 'template')));
        $this->assertSame(['if', 'else-if', 'else', 'foreach', 'empty', 'error', 'switch'], $blocks);

        $switch = end($tree['h'][0]['h']);
        $this->assertSame(['case', 'default'], array_map(fn ($c) => $c['i'][0]['c'], $switch['h']));
        $this->assertSame(['t' => 'b', 'c' => 'break', 'levels' => 1], $switch['h'][0]['h'][1]);
    }

    public function test_handlers_are_expressions_given_the_variables_they_were_rendered_with(): void
    {
        [$tree, $expressions] = $this->compiled('<div>@for ($n = 1; $n <= 3; $n++)<button nitro:click="helper($n, $count)">{{ $n }}</button>@endfor</div>');

        $for = $tree['h'][0]['h'][0];
        $this->assertSame(['c' => 'for', 'code' => 0, 'vars' => [0]], $for['i'][0]);

        $button = $for['h'][0];
        $this->assertSame(['c' => 'nitro:click', 'h' => [['c' => 'helper($n, $count)']], 'on' => ['e' => 'click', 'm' => [], 'code' => 1]], $button['a'][0]);
        $this->assertStringContainsString("function (\$this, \$n) { return (\$event) => {\n\$this.helper(\$n, \$this.count);", $expressions[1]);
    }

    public function test_a_model_path_can_be_built_with_blade(): void
    {
        [$tree, $expressions] = $this->compiled('<div>@foreach ($items as $i => $item)<input nitro:model="items.{{ $i }}.name" class="x">@endforeach</div>');

        $input = $tree['h'][0]['h'][0]['h'][0];
        $this->assertSame(['c' => 'nitro:model', 'h' => [['c' => 'items.'], ['e' => 1, 'code' => 1], ['c' => '.name']]], $input['a'][0]);
        $this->assertStringContainsString('return P.toStr($i);', $expressions[1]);

        $this->expectExceptionMessage('nope is not a public property');
        $this->compiled('<div>@foreach ($items as $i => $item)<input nitro:model="nope.{{ $i }}.name">@endforeach</div>');
    }

    public function test_a_childs_model_path_can_be_built_with_blade(): void
    {
        [$tree, $expressions] = $this->compiled('<div>@foreach ($items as $i => $item)<nitro:counter nitro:model="items.{{ $i }}.qty" />@endforeach</div>');

        $child = $tree['h'][0]['h'][0]['h'][0];
        $this->assertSame('c', $child['t']);
        $this->assertSame('"items." + P.toStr($i) + ".qty"', preg_replace('/^.*return (.*);.*$/s', '$1', $expressions[$child['model']]));
        $this->assertStringContainsString('"$model": P.get(P.get($this.items, $i), "qty")', $expressions[$child['props']]);

        [$tree] = $this->compiled('<div><nitro:counter nitro:model="items.0.qty" /></div>');
        $this->assertSame('items.0.qty', $tree['h'][0]['h'][0]['model'], 'a path without {{ }} stays a string');
    }

    public static function childModels(): array
    {
        return [
            'not a property' => ['<div><nitro:counter nitro:model="nope.{{ $count }}" /></div>', 'nope must be a public property of this component'],
            'part of a segment' => ['<div><nitro:counter nitro:model="items.a{{ $count }}" /></div>', 'names a property of this component, or a key under it'],
            'the root' => ['<div><nitro:counter nitro:model="{{ $count }}.qty" /></div>', 'names a property of this component, or a key under it'],
            'bound' => ['<div><nitro:counter :nitro:model="$count" /></div>', 'names a property of this component, or a key under it'],
            'not an expression' => ['<div><nitro:counter nitro:model="items.{{ ; }}" /></div>', '{{ ; }} is not a PHP expression'],
            'another attribute' => ['<div><nitro:counter label="{{ $count }}" /></div>', 'Use :label="$value" to pass a value to <nitro:counter>, not {{ }}'],
        ];
    }

    #[DataProvider('childModels')]
    public function test_a_childs_model_path_is_checked(string $template, string $message): void
    {
        $this->expectExceptionMessage($message);
        $this->compiled($template);
    }

    public function test_computed_values_poll_init_and_confirm_compile(): void
    {
        [$tree, $expressions] = $this->compiled('<div nitro:init="helper"><p>{{ $this->total }}</p><span nitro:poll.5s.visible="save"></span><span nitro:poll></span>'
            .'<button nitro:click="save" nitro:confirm="Sure?">x</button></div>');

        $div = $tree['h'][0];
        $this->assertSame(['e' => 'init', 'm' => [], 'code' => 0], $div['a'][0]['on']);
        $this->assertStringContainsString('P.toStr($this.$computed("total"))', $expressions[1]);
        $this->assertSame(['e' => 'poll', 'm' => ['5s', 'visible'], 'code' => 2], $div['h'][1]['a'][0]['on']);
        $this->assertSame(['c' => 'nitro:poll', 'on' => ['e' => 'poll', 'm' => [], 'code' => 3]], $div['h'][2]['a'][0]);
        $this->assertStringContainsString('$this.$refresh()', $expressions[3]);
        $this->assertSame(['c' => 'nitro:confirm', 'h' => [['c' => 'Sure?']]], $div['h'][3]['a'][1]);
    }

    /** A key written as its letter or digit, beside the named ones: passed to the runtime as written. */
    public function test_a_letter_or_a_digit_is_a_key(): void
    {
        [$tree] = $this->compiled('<div><input nitro:keydown.ctrl.k="save"><input nitro:keyup.1="save"></div>');

        $this->assertSame(['ctrl', 'k'], $tree['h'][0]['h'][0]['a'][0]['on']['m']);
        $this->assertSame(['1'], $tree['h'][0]['h'][1]['a'][0]['on']['m']);
    }

    public function test_navigate_and_current_take_their_modifiers(): void
    {
        [$prefetching] = $this->compiled('<div><a href="/x" nitro:navigate nitro:prefetch>x</a><a href="/y" nitro:navigate.keypress nitro:prefetch.150ms>y</a><a href="/z" nitro:navigate nitro:prefetch.wait.150ms.keep.60s>z</a><a href="/w" nitro:navigate nitro:prefetch.visible.keep.500ms>w</a></div>');
        $links = $prefetching['h'][0]['h'];
        $this->assertSame('nitro:prefetch', $links[0]['a'][2]['c'], 'on hover: passed through for the runtime');
        $this->assertSame('nitro:prefetch.150ms', $links[1]['a'][2]['c'], 'a time first: the hover wait');
        $this->assertSame('nitro:prefetch.wait.150ms.keep.60s', $links[2]['a'][2]['c'], 'the wait and the keep, spelled out');
        $this->assertSame('nitro:prefetch.visible.keep.500ms', $links[3]['a'][2]['c'], 'in view, kept 500ms');

        [$transition] = $this->compiled('<nav><a href="/y" nitro:navigate.transition>y</a></nav>');
        $this->assertSame('nitro:navigate.transition', $transition['h'][0]['h'][0]['a'][1]['c'], '.transition: a View Transition for this link');

        [$tree] = $this->compiled('<nav><a href="/a" nitro:navigate.keypress nitro:current.exact="on">a</a><a href="/b" nitro:navigate nitro:current>b</a></nav>');

        [$a, $b] = $tree['h'][0]['h'];
        $this->assertSame([['c' => 'href', 'h' => [['c' => '/a']]], ['c' => 'nitro:navigate.keypress'], ['c' => 'nitro:current.exact', 'h' => [['c' => 'on']]]], $a['a']);
        $this->assertSame([['c' => 'href', 'h' => [['c' => '/b']]], ['c' => 'nitro:navigate'], ['c' => 'nitro:current']], $b['a']);
    }

    public function test_alpine_attributes_and_email_addresses_are_left_alone(): void
    {
        [$tree] = $this->compiled('<div @click="open = !open" x-data="{ open: false }">a@b.com @@if &amp; &times;</div>');

        $div = $tree['h'][0];
        $this->assertSame([['c' => '@click', 'h' => [['c' => 'open = !open']]], ['c' => 'x-data', 'h' => [['c' => '{ open: false }']]]], $div['a']);
        $this->assertSame([['t' => 'x', 'c' => 'a@b.com @if & ×']], $div['h'], 'text as the HTML parser reads it');
    }

    public function test_svg_keeps_the_case_of_its_names(): void
    {
        [$tree] = $this->compiled('<div><svg viewBox="0 0 20 20"><linearGradient gradientUnits="x"/><path d="M0"/></svg><input readOnly></div>');

        $svg = $tree['h'][0]['h'][0];
        $this->assertSame('viewBox', $svg['a'][0]['c']);
        $this->assertSame(['linearGradient', 'path'], array_column($svg['h'], 'c'), 'self-closed in SVG');
        $this->assertSame('gradientUnits', $svg['h'][0]['a'][0]['c']);
        $this->assertSame('readonly', $tree['h'][0]['h'][1]['a'][0]['c'], 'HTML attribute names are lowercase');
    }
}
