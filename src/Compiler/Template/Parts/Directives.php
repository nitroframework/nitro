<?php

namespace Nitro\Compiler\Template\Parts;

use Nitro\Compiler\Template\PhpSource;
use Nitro\Compiler\Template\TemplateNode;
use Nitro\Compiler\Template\Token;

/**
 * Blade directives: each @directive sent where it belongs, by where it appears.
 *
 *  - between elements: a block (@if, @foreach, @switch, ... in Blocks), or a node of its own
 *    (@csrf and @method as hidden inputs, @json as text)
 *  - inside a tag: an attribute of it (@class, @style, @checked, @disabled, ...), as Blade's
 *    attribute directives write it; a condition one is present only while its condition holds
 *  - inside an attribute value: only @json; inside a comment or an end tag: none
 *  - a directive not in Syntax::DIRECTIVES is a compile error: it cannot render in the browser
 *
 * General Blade, plus three Nitro details: @deferred / @placeholder (a page's deferred
 * properties, $this.$deferring()), @teleport (in Blocks), and BladeInliner's marker directives,
 * handed to InlinedViews.
 *
 * @internal
 */
final class Directives extends Part
{
    public function directive(Token $token): void
    {
        $context = $this->context;
        $tree = $this->compiler->tree;
        $table = $this->compiler->expressionTable;
        $blocks = $this->compiler->blocks;
        $name = $token->value;
        $args = $token->args;

        if (! in_array($name, Syntax::DIRECTIVES, true)) {
            throw $context->error("@{$name} cannot render in the browser, so it is not supported in a Nitro component".
                ' (use a child component or pass the data as a property)');
        }

        /** An inlined view begins or ends wherever its @include is: in a tag, an attribute's value, text. */
        if (in_array($name, ['nitroScope', 'endnitroScope'], true) || ($context->state !== 'tag' && in_array($name, ['nitroSlot', 'endnitroSlot'], true))) {
            $this->compiler->inlinedViews->inlined($name, $args);

            return;
        }

        if (in_array($name, ['nitroTrim', 'endnitroTrim'], true)) {
            $this->trimMark($name === 'endnitroTrim', (int) $args);

            return;
        }

        if ($context->state === 'tag') {
            $this->tagDirective($name, $args);

            return;
        }

        if ($name === 'nitroCall') {
            $this->compiler->componentCalls->call((string) $args);

            return;
        }

        if ($context->state === 'value') {
            if (! in_array($name, ['json', 'lang', 'choice'], true)) {
                throw $context->error("@{$name} cannot be used inside an attribute value: use @class, @style or an echo");
            }

            $table->rawOutput(...($name === 'json' ? $this->json($this->required($name, $args)) : $this->translation($name, $args)));

            return;
        }

        if ($context->state === 'comment' || $context->state === 'endtag') {
            throw $context->error("@{$name} cannot be used inside an HTML comment or an end tag");
        }

        $blocks->inSwitchBeforeCase($name);

        match ($name) {
            'if' => $tree->openBlock('if', ['c' => 'if', 'code' => $table->condition($this->required($name, $args))]),
            'unless' => $tree->openBlock('unless', ['c' => 'if', 'code' => $table->expression('!P.bool('.$table->expr($this->required($name, $args)).')', '! ('.$table->php($this->required($name, $args)).')')]),
            'isset' => $tree->openBlock('isset', ['c' => 'if', 'code' => $table->expression($table->expr('isset('.$this->required($name, $args).')'), $table->php('isset('.$this->required($name, $args).')'))]),
            'empty' => $args === null
                ? $blocks->forelseEmpty()
                : $tree->openBlock('empty', ['c' => 'if', 'code' => $table->expression($table->expr('empty('.$args.')'), $table->php('empty('.$args.')'))]),
            /** @unless, @isset and @empty($x) are PHP's if as @if is (CompilesConditionals): @elseif follows them too. */
            'elseif' => $tree->branch(['if', 'unless', 'isset', 'empty'], 'elseif', fn () => ['c' => 'else-if', 'code' => $table->condition($this->required($name, $args))]),
            'else' => $tree->branch(Syntax::CONDITIONALS, 'else', fn () => ['c' => 'else']),
            /** Each is PHP's endif: any of them ends any of these (and @forelse's @empty part). */
            'endif', 'endunless', 'endisset', 'endempty' => $this->endIf($name),
            'foreach' => $blocks->foreach($this->required($name, $args), false),
            'forelse' => $blocks->foreach($this->required($name, $args), true),
            /** BladeInliner's @each: its iterations, no Blade loop (no $loop, no @break target). */
            'nitroEach' => $blocks->foreach($this->required($name, $args), true, '__eachLoop'),
            'endnitroEach' => $tree->closeBlock(['forelse', 'forelse-empty']),
            'endforeach' => $tree->closeBlock(['foreach']),
            'endforelse' => $this->endIf($name),
            'for' => $blocks->for($this->required($name, $args)),
            'endfor' => $tree->closeBlock(['for']),
            'while' => $blocks->while($this->required($name, $args)),
            'endwhile' => $tree->closeBlock(['while']),
            'break', 'continue' => $blocks->jump($name, $args),
            'switch' => $blocks->switch($this->required($name, $args)),
            'case' => $blocks->case(['c' => 'case', 'code' => $table->expression($table->expr($this->required($name, $args)), $table->php($this->required($name, $args)))]),
            'default' => $blocks->case(['c' => 'default']),
            'endswitch' => $tree->closeBlock(['switch']),
            'error' => $blocks->errorBlock($this->required($name, $args)),
            'enderror' => $tree->closeBlock(['error']),
            'deferred' => $tree->openBlock('deferred', ['c' => 'if', 'code' => $table->expression('!$this.$deferring('.$this->deferredProperties($args).')', '! $this->nitro()->deferring(['.trim((string) $args).'])')]),
            'placeholder' => $tree->branch(['deferred'], 'placeholder', fn () => ['c' => 'else']),
            'enddeferred' => $tree->closeBlock(['deferred']),
            'teleport' => $blocks->teleport($this->required($name, $args)),
            'endteleport' => $tree->closeBlock(['teleport']),
            /** As Blade's (CompilesHelpers: csrf_field(), method_field()): the values printed as they are. */
            'csrf' => $tree->append($this->hiddenInput('_token', $table->expression('B.session.token', '(string) \\Nitro\\Support\\CurrentRequest::session(app())?->token()'), ['autocomplete' => 'off'])),
            'method' => $tree->append($this->hiddenInput('_method', $table->expression('P.toStr('.$table->expr($this->required($name, $args)).')', '(string) ('.$table->php($this->required($name, $args)).')'))),
            /** Printed as they are, as Blade echoes them (@json's default flags leave no markup in it; a line's HTML is the line's). */
            'json' => $table->rawOutput(...$this->json($this->required($name, $args))),
            'lang', 'choice' => $table->rawOutput(...$this->translation($name, $args)),
            /** Renders nothing: its imports apply to the whole view (TemplateCompiler::imports()). */
            'use' => $this->required($name, $args),
            default => throw $context->error("@{$name} belongs inside a tag, such as <div @{$name}(...)>"),
        };
    }

    /**
     * @endif, @endunless, @endisset, @endempty, @endforelse: Blade compiles each to PHP's endif, so
     * each ends an @if, @unless, @isset, @empty($x) or @forelse's @empty part. A @forelse without
     * @empty is a PHP parse error in Blade (its foreach is never closed): refused.
     */
    private function endIf(string $name): void
    {
        $top = end($this->context->stack);

        if (($top['kind'] ?? null) === 'forelse') {
            throw $this->context->error("@forelse from line {$top['line']} has no @empty: Blade's @{$name} ends the @empty part, so without one its loop is never closed (use @foreach for a loop without @empty)");
        }

        $this->compiler->tree->closeBlock(['if', 'unless', 'isset', 'empty', 'forelse-empty']);
    }

    /**
     * BladeInliner's @nitroTrim / @endnitroTrim: where a view Blade renders on its own (and
     * ltrim()s: PhpEngine) begins and ends, wherever its @include is: a node in the page, in
     * <script>/<style>, <textarea>/<title> ({t: "w"}, runtime/view/trim.js); a part of an
     * attribute's value ({w}: the value is trimmed there as it is put together, attributes.js);
     * nothing in a tag or a comment (no text of the page there).
     */
    private function trimMark(bool $end, int $id): void
    {
        $mark = $end ? ['w' => $id, 'end' => 1] : ['w' => $id];

        match ($this->context->state) {
            'tag', 'comment', 'endtag' => null,
            'value' => $this->compiler->html->attributePart($mark),
            default => $this->compiler->tree->append(new TemplateNode(['t' => 'w', ...$mark])),
        };
    }

    /** A directive's expression, which it cannot do without. */
    public function required(string $name, ?string $args): string
    {
        if ($args === null || trim($args) === '') {
            throw $this->context->error("@{$name} needs an expression");
        }

        return $args;
    }

    /**
     * Blade's json directive as Blade compiles it (CompilesJson): its text split at every comma,
     * json_encode(first, second ?? Blade's flags, third ?? 512), with Blade's flags JSON_HEX_TAG |
     * JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT. A value with a comma in it is split too (as
     * Blade splits it): @json(['a' => 1, 'b' => 2]) is json_encode(['a' => 1, 'b' => 2], 512).
     *
     * @return array{0: string, 1: string} the browser's JavaScript and the server's PHP
     */
    private function json(string $args): array
    {
        $parts = explode(',', $args);
        $php = 'json_encode('.$parts[0].', '.(isset($parts[1]) ? trim($parts[1]) : '15').', '.(isset($parts[2]) ? trim($parts[2]) : '512').')';

        try {
            PhpSource::expression($php, $this->context->file, $this->context->line);
        } catch (\Nitro\Compiler\CompileException) {
            throw $this->context->error("@json({$args}): Blade splits @json at every comma, which makes this {$php}, not PHP; pass a variable, or json_encode() it in an echo");
        }

        $table = $this->compiler->expressionTable;

        return ['P.toStr('.$table->expr($php).')', $table->php($php)];
    }

    /**
     * Blade's @lang('key', [...]) and @choice('key', $count, [...]), as Blade compiles them:
     * the translator's get() and choice(), which __() and trans_choice() are.
     *
     * @return array{0: string, 1: string} the browser's JavaScript and the server's PHP
     */
    private function translation(string $name, ?string $args): array
    {
        if ($args === null || trim($args) === '' || str_starts_with(ltrim($args), '[')) {
            throw $this->context->error("@{$name} ... @end{$name} cannot render in the browser: use @{$name}('key')");
        }

        $table = $this->compiler->expressionTable;

        return [
            'P.toStr('.$table->expr(($name === 'lang' ? '__' : 'trans_choice')."({$args})").')',
            $table->php("app('translator')->".($name === 'lang' ? 'get' : 'choice')."({$args})"),
        ];
    }

    /** @class, @style, @checked, ... inside a tag: attributes of it. */
    private function tagDirective(string $name, ?string $args): void
    {
        $context = $this->context;
        $table = $this->compiler->expressionTable;

        if (in_array($name, Syntax::INLINED_TAG_DIRECTIVES, true)) {
            match ($name) {
                'nitroAttr' => $this->compiler->inlinedViews->componentAttribute((string) $args),
                /** In a Blade component's view: the caller's nitro: attribute (its name as written), rendered with the caller. */
                default => $context->tag->attributes[] = ['d' => trim((string) $args)],
            };

            return;
        }

        if (! in_array($name, Syntax::ATTRIBUTE_DIRECTIVES, true)) {
            throw $context->error("@{$name} cannot sit inside a tag: wrap the element in it, or use @class, @style, @disabled, @checked, ...");
        }

        $context->tag->attributes[] = match ($name) {
            /** As Blade's: Arr::toCssClasses() / toCssStyles(), printed as they are. */
            'class' => ['c' => 'class', 'h' => [['e' => 1, 'code' => $table->expression('B.classes('.$table->expr($args ?? '[]').')', '\Illuminate\Support\Arr::toCssClasses('.$table->php($args ?? '[]').')')]]],
            'style' => ['c' => 'style', 'h' => [['e' => 1, 'code' => $table->expression('B.styles('.$table->expr($args ?? '[]').')', '\Illuminate\Support\Arr::toCssStyles('.$table->php($args ?? '[]').')')]]],
            default => ['c' => $name, 'when' => $table->condition($this->required($name, $args))],
        };
    }

    /** @deferred's properties, by name ('stats', 'chart'): a JSON list, empty for any. */
    private function deferredProperties(?string $args): string
    {
        $args = trim((string) $args);

        if ($args !== '' && ! preg_match('/^([\'"])\w+\1(\s*,\s*([\'"])\w+\3)*$/', $args)) {
            throw $this->context->error("@deferred names properties as strings: @deferred('stats') or @deferred('stats', 'chart')");
        }

        preg_match_all('/\w+/', $args, $names);

        return json_encode(array_map($this->context->scope->browserProperty(...), $names[0]), JSON_THROW_ON_ERROR);
    }

    /**
     * @csrf / @method: a hidden input, its value an expression.
     *
     * @param  array<string, string>  $more  more static attributes
     */
    private function hiddenInput(string $name, int $value, array $more = []): TemplateNode
    {
        $input = new TemplateNode(['t' => 't', 'c' => 'input']);
        $input->attributes = [
            ['c' => 'type', 'h' => [['c' => 'hidden']]],
            ['c' => 'name', 'h' => [['c' => $name]]],
            ['c' => 'value', 'h' => [['e' => 1, 'code' => $value]]],
        ];

        foreach ($more as $attribute => $static) {
            $input->attributes[] = ['c' => $attribute, 'h' => [['c' => $static]]];
        }

        return $input;
    }
}
