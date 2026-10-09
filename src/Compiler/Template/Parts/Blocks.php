<?php

namespace Nitro\Compiler\Template\Parts;

use Nitro\Compiler\Template\TemplateNode;
use Nitro\Support\Protocol;

/**
 * Blade's blocks that need more than open / branch / close (those are Tree's): the loops,
 * @switch, @break / @continue, @error and @teleport. Blade's semantics, kept in the browser:
 *
 *  - @foreach / @forelse: the data, the key and item variables, and $loop (with $loop->parent);
 *    @empty inside @forelse is the block for no items
 *  - @for / @while: the loop runs in its expression and yields each iteration's variables, at
 *    most Syntax::MAX_ITERATIONS times (a template cannot stop an endless loop otherwise)
 *  - @break / @continue, with levels (@break(2)) or a condition (@break($done)); @continue in a
 *    @switch is refused (Blade treats it as break)
 *  - @switch: only @case and @default may follow it, as in Blade's PHP
 *  - @error('field'): the component's validation message for the field, as $message
 *
 * Nitro: @teleport('modals') renders its content at @portal('modals'), with this component's
 * state and handlers; and a field named in @error is the browser's name for it (an obfuscated
 * build).
 *
 * @internal
 */
final class Blocks extends Part
{
    /** Blade's @foreach($items as $key => $item) and @forelse, with $loop. */
    public function foreach(string $args, bool $forelse): void
    {
        $context = $this->context;
        $table = $this->compiler->expressionTable;

        if (! preg_match('/^\s*(.+)\s+as\s+(.*?)\s*$/is', $args, $match)) {
            throw $context->error('Malformed @'.($forelse ? 'forelse' : 'foreach').': expected "$items as $item"');
        }

        [, $iteratee, $iteration] = $match;

        if (! preg_match('/^\$(\w+)(?:\s*=>\s*\$(\w+))?$/', trim($iteration), $vars)) {
            throw $context->error('@foreach must name its variables ($item or $key => $item)');
        }

        [$keyVar, $valueVar] = isset($vars[2]) ? [$vars[1], $vars[2]] : [null, $vars[1]];

        $data = $table->expression($table->expr($iteratee));
        $parentLoop = in_array('loop', $context->locals, true);
        /** $loop->parent is the loop around it: its state comes from that loop's data too. */
        $loopSources = [$data, ...($parentLoop ? $context->sources['loop'] ?? [] : [])];
        $node = $this->compiler->tree->openBlock($forelse ? 'forelse' : 'foreach', [], true);

        $node->fields['i'] = [array_filter([
            'c' => 'foreach',
            'forData' => $data,
            'key' => $keyVar !== null ? $table->declareFrom($keyVar, [$data]) : null,
            'item' => $table->declareFrom($valueVar, [$data]),
            'loop' => $table->declareFrom('loop', $loopSources),
            'parentLoop' => $parentLoop ? 1 : null,
        ], fn ($value) => $value !== null)];
    }

    /**
     * Blade's @empty inside @forelse: the loop's block ends, the one for no items begins. That
     * block is after the loop, not in it: @break and @continue there are the loops' around it.
     */
    public function forelseEmpty(): void
    {
        $this->compiler->tree->branch(['forelse'], 'empty', fn () => ['c' => 'empty']);
        $frame = &$this->context->stack[count($this->context->stack) - 1];
        $frame['kind'] = 'forelse-empty';
        $frame['loop'] = false;
    }

    /** Blade's @for($i = 0; $i < 10; $i++): its iterations, worked out by the expression. */
    public function for(string $args): void
    {
        $context = $this->context;
        $table = $this->compiler->expressionTable;

        [$head, $variables] = $context->transpiler->templateFor($args, $context->scope, $context->locals, $context->line);
        $table->track();
        $declare = $variables === [] ? '' : 'let '.implode(', ', array_map(fn ($variable) => '$'.$variable, $variables)).'; ';
        $values = '['.implode(', ', array_map(fn ($variable) => '$'.$variable, $variables)).']';

        $code = $table->expression($this->iterations($declare.$head, $values, '@for'));
        $node = $this->compiler->tree->openBlock('for', [], true);
        $node->fields['i'] = [['c' => 'for', 'code' => $code, 'vars' => array_map(fn ($variable) => $table->declareFrom($variable, [$code]), $variables)]];
    }

    public function while(string $args): void
    {
        $table = $this->compiler->expressionTable;
        $code = $table->expression($this->iterations('while (P.bool('.$table->expr($args).'))', '[]', '@while'));
        $this->compiler->tree->openBlock('while', ['c' => 'while', 'code' => $code], true);
    }

    /** Blade's @break, @break(2), @break($condition); @continue likewise. */
    public function jump(string $keyword, ?string $args): void
    {
        $context = $this->context;
        $loops = array_values(array_filter($context->stack, fn ($frame) => $frame['loop'] ?? false));

        if ($loops === []) {
            throw $context->error("@{$keyword} outside of a loop");
        }

        /** Teleported content renders elsewhere: a loop around the @teleport is not around it there. */
        $inner = array_slice($context->stack, (int) array_key_last(array_filter($context->stack, fn ($frame) => $frame['loop'] ?? false)));

        if (in_array('teleport', array_column($inner, 'kind'), true)) {
            throw $context->error("@{$keyword} inside @teleport cannot leave a loop around the @teleport");
        }

        $node = new TemplateNode(['t' => 'b', 'c' => $keyword, 'levels' => 1]);

        if ($args !== null && preg_match('/^\s*(-?\d+)\s*$/', $args, $match)) {
            $levels = max(1, (int) $match[1]);

            if ($levels > count($loops)) {
                throw $context->error("@{$keyword}({$levels}): not inside that many loops");
            }

            $node->fields['levels'] = $levels;
        } else {
            if ($keyword === 'continue' && end($loops)['kind'] === 'switch') {
                throw $context->error('@continue inside @switch: use @break');
            }

            if ($args !== null) {
                $node->fields['code'] = $this->compiler->expressionTable->condition($args);
            }
        }

        /**
         * Blade's @break leaves the loop where it is: an element opened inside the loop would end
         * without its end tag in the server's HTML (the browser then nests what follows in it).
         */
        $targets = array_keys(array_filter($context->stack, fn ($frame) => $frame['loop'] ?? false));
        $target = $targets[count($targets) - $node->fields['levels']];

        foreach (array_slice($context->stack, $target + 1) as $frame) {
            if ($frame['kind'] === 'element') {
                throw $context->error("@{$keyword} inside <{$frame['tag']}> (line {$frame['line']}): Blade would leave the <{$frame['tag']}> without its end tag. Put the @{$keyword} outside the element");
            }
        }

        $this->compiler->tree->append($node);
    }

    public function switch(string $args): void
    {
        $table = $this->compiler->expressionTable;
        $this->compiler->tree->openBlock('switch', ['c' => 'switch', 'code' => $table->expression($table->expr($args))], true);
        $this->context->stack[count($this->context->stack) - 1]['case'] = null;
    }

    /** @param  array<string, mixed>  $directive */
    public function case(array $directive): void
    {
        $context = $this->context;
        $this->compiler->tree->flushText();
        $top = end($context->stack);

        if ($top['kind'] !== 'switch') {
            throw $context->error('@'.($directive['c'] === 'case' ? 'case' : 'default').' outside of @switch');
        }

        $case = new TemplateNode(['t' => 'template', 'i' => [$directive]]);
        $top['node']->children[] = $case;
        $context->stack[count($context->stack) - 1]['case'] = $case;
    }

    /** Text or directives between @switch and its first @case: Blade's PHP, where they cannot be. */
    public function inSwitchBeforeCase(string $directive): void
    {
        $top = end($this->context->stack);

        if ($top['kind'] === 'switch' && $top['case'] === null && ! in_array($directive, ['case', 'default', 'endswitch'], true)) {
            throw $this->context->error('Only @case and @default can follow @switch');
        }
    }

    /** Blade's @error('field'): the component's validation message for the field, as $message. */
    public function errorBlock(string $args): void
    {
        $table = $this->compiler->expressionTable;

        /**
         * A field written out, or one built from a start written out ('items.'.$i.'.price'): its
         * property by the browser's name (an obfuscated build).
         */
        if (preg_match('/^(\s*)([\'"])([\w.*\-]+)\2/', $args, $literal) === 1) {
            $args = $literal[1].var_export($this->context->scope->browserPath($literal[3]), true).substr($args, strlen($literal[0]));
        }

        $field = $table->expression($table->expr('['.$args.'][0]'));
        $node = $this->compiler->tree->openBlock('error', []);
        $node->fields['i'] = [['c' => 'error', 'code' => $field, 'message' => $table->declareFrom('message', [$field, Protocol::signal('errors')])]];
    }

    /**
     * Livewire's @teleport, as Viewi's <Portal to="modals">: the content renders at @portal('modals')
     * (Viewi's <Portal name="modals">), on the server and in the browser (runtime renderTeleport),
     * with this component's state and handlers. The name is a literal, as Viewi's is.
     */
    public function teleport(string $args): void
    {
        $context = $this->context;

        if ($context->state !== 'data') {
            throw $context->error('@teleport goes between elements, not inside <script>, <style>, <textarea> or <title>');
        }

        if (! preg_match('/^\s*([\'"])([A-Za-z0-9_-]+)\1\s*$/', $args, $name)) {
            throw $context->error("@teleport takes the name of a @portal, as a string literal: @teleport('modals')");
        }

        if (in_array('teleport', array_column($context->stack, 'kind'), true)) {
            throw $context->error('@teleport cannot be inside another @teleport');
        }

        $node = new TemplateNode(['t' => 'p', 'to' => $name[2]]);
        $this->compiler->tree->append($node);
        $context->stack[] = ['kind' => 'teleport', 'node' => $node, 'line' => $context->line, 'locals' => $context->locals, 'sources' => $context->sources, 'loop' => false];
    }

    /** A loop's iterations as a list: each the values its variables have in it. */
    private function iterations(string $loop, string $values, string $directive): string
    {
        return "(() => { const \$\$runs = []; {$loop} { \$\$runs.push({$values}); if (\$\$runs.length > ".Syntax::MAX_ITERATIONS.") throw new Error('{$directive} ran more than ".Syntax::MAX_ITERATIONS." times'); } return \$\$runs; })()";
    }
}
