<?php

namespace Nitro\Fusion\Compiler\Template\Parts;

use Nitro\Fusion\Compiler\Template\TemplateNode;
use Nitro\Fusion\Compiler\Template\Token;

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
 * General Blade, plus three Fusion details: @deferred / @placeholder (a page's deferred
 * properties, $this.$deferring()), @teleport (in Blocks), and BladeInliner's marker directives,
 * handed to InlinedViews.
 *
 * @internal
 */
final class Directives extends Part
{
    public function directive(Token $token): void
    {
        $c = $this->context;
        $tree = $this->compiler->tree;
        $table = $this->compiler->expressionTable;
        $blocks = $this->compiler->blocks;
        $name = $token->value;
        $args = $token->args;

        if (! in_array($name, Syntax::DIRECTIVES, true)) {
            throw $c->error("@{$name} cannot render in the browser, so it is not supported in a Fusion component".
                ' (use a child component or pass the data as a property)');
        }

        if ($c->state === 'tag') {
            $this->tagDirective($name, $args);

            return;
        }

        if (in_array($name, ['fusionScope', 'endfusionScope', 'fusionSlot', 'endfusionSlot'], true)) {
            $this->compiler->inlinedViews->inlined($name, $args);

            return;
        }

        if ($c->state === 'value') {
            if ($name !== 'json') {
                throw $c->error("@{$name} cannot be used inside an attribute value: use @class, @style or an echo");
            }

            $this->compiler->html->attributePart(['e' => 1, 'code' => $table->expression('P.fns.json_encode('.$table->expr($this->required($name, $args)).', 15)')]);

            return;
        }

        if ($c->state === 'comment' || $c->state === 'endtag') {
            throw $c->error("@{$name} cannot be used inside an HTML comment or an end tag");
        }

        $blocks->inSwitchBeforeCase($name);

        match ($name) {
            'if' => $tree->openBlock('if', ['c' => 'if', 'code' => $table->condition($this->required($name, $args))]),
            'unless' => $tree->openBlock('unless', ['c' => 'if', 'code' => $table->expression('!P.bool('.$table->expr($this->required($name, $args)).')')]),
            'isset' => $tree->openBlock('isset', ['c' => 'if', 'code' => $table->expression($table->expr('isset('.$this->required($name, $args).')'))]),
            'empty' => $args === null
                ? $blocks->forelseEmpty()
                : $tree->openBlock('empty', ['c' => 'if', 'code' => $table->expression($table->expr('empty('.$args.')'))]),
            'elseif' => $tree->branch(['if'], 'elseif', fn () => ['c' => 'else-if', 'code' => $table->condition($this->required($name, $args))]),
            'else' => $tree->branch(Syntax::CONDITIONALS, 'else', fn () => ['c' => 'else']),
            'endif' => $tree->closeBlock(['if']),
            'endunless' => $tree->closeBlock(['unless']),
            'endisset' => $tree->closeBlock(['isset']),
            'endempty' => $tree->closeBlock(['empty']),
            'foreach' => $blocks->foreach($this->required($name, $args), false),
            'forelse' => $blocks->foreach($this->required($name, $args), true),
            'endforeach' => $tree->closeBlock(['foreach']),
            'endforelse' => $tree->closeBlock(['forelse', 'forelse-empty']),
            'for' => $blocks->for($this->required($name, $args)),
            'endfor' => $tree->closeBlock(['for']),
            'while' => $blocks->while($this->required($name, $args)),
            'endwhile' => $tree->closeBlock(['while']),
            'break', 'continue' => $blocks->jump($name, $args),
            'switch' => $blocks->switch($this->required($name, $args)),
            'case' => $blocks->case(['c' => 'case', 'code' => $table->expression($table->expr($this->required($name, $args)))]),
            'default' => $blocks->case(['c' => 'default']),
            'endswitch' => $tree->closeBlock(['switch']),
            'error' => $blocks->errorBlock($this->required($name, $args)),
            'enderror' => $tree->closeBlock(['error']),
            'deferred' => $tree->openBlock('deferred', ['c' => 'if', 'code' => $table->expression('!$this.$deferring('.$this->deferredProperties($args).')')]),
            'placeholder' => $tree->branch(['deferred'], 'placeholder', fn () => ['c' => 'else']),
            'enddeferred' => $tree->closeBlock(['deferred']),
            'teleport' => $blocks->teleport($this->required($name, $args)),
            'endteleport' => $tree->closeBlock(['teleport']),
            'csrf' => $tree->append($this->hiddenInput('_token', $table->expression('B.session.token'), ['autocomplete' => 'off'])),
            'method' => $tree->append($this->hiddenInput('_method', $table->expression('P.toStr('.$table->expr($this->required($name, $args)).')'))),
            'json' => $tree->append(new TemplateNode(['t' => 'x', 'e' => 1, 'code' => $table->expression('P.fns.json_encode('.$table->expr($this->required($name, $args)).', 15)')])),
            default => throw $c->error("@{$name} belongs inside a tag, such as <div @{$name}(...)>"),
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

    /** @class, @style, @checked, ... inside a tag: attributes of it. */
    private function tagDirective(string $name, ?string $args): void
    {
        $c = $this->context;
        $table = $this->compiler->expressionTable;

        if (in_array($name, Syntax::INLINED_TAG_DIRECTIVES, true)) {
            $name === 'fusionAttr' ? $this->compiler->inlinedViews->componentAttribute((string) $args) : $this->compiler->inlinedViews->inlined($name, $args);

            return;
        }

        if (! in_array($name, Syntax::ATTRIBUTE_DIRECTIVES, true)) {
            throw $c->error("@{$name} cannot sit inside a tag: wrap the element in it, or use @class, @style, @disabled, @checked, ...");
        }

        $c->tag->attributes[] = match ($name) {
            'class' => ['c' => 'class', 'h' => [['e' => 1, 'code' => $table->expression('B.classes('.$table->expr($args ?? '[]').')')]]],
            'style' => ['c' => 'style', 'h' => [['e' => 1, 'code' => $table->expression('B.styles('.$table->expr($args ?? '[]').')')]]],
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
