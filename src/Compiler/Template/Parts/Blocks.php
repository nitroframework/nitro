<?php

namespace Nitro\Fusion\Compiler\Template\Parts;

use Nitro\Fusion\Compiler\Template\TemplateNode;

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
 * Fusion: @teleport('modals') renders its content at @portal('modals'), with this component's
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
        $c = $this->context;
        $table = $this->compiler->expressionTable;

        if (! preg_match('/^\s*(.+)\s+as\s+(.*?)\s*$/is', $args, $m)) {
            throw $c->error('Malformed @'.($forelse ? 'forelse' : 'foreach').': expected "$items as $item"');
        }

        [, $iteratee, $iteration] = $m;

        if (! preg_match('/^\$(\w+)(?:\s*=>\s*\$(\w+))?$/', trim($iteration), $vars)) {
            throw $c->error('@foreach must name its variables ($item or $key => $item)');
        }

        [$keyVar, $valueVar] = isset($vars[2]) ? [$vars[1], $vars[2]] : [null, $vars[1]];

        $data = $table->expression($table->expr($iteratee));
        $parentLoop = in_array('loop', $c->locals, true);
        /** $loop->parent is the loop around it: its state comes from that loop's data too. */
        $loopSources = [$data, ...($parentLoop ? $c->sources['loop'] ?? [] : [])];
        $node = $this->compiler->tree->openBlock($forelse ? 'forelse' : 'foreach', [], true);

        $node->fields['i'] = [array_filter([
            'c' => 'foreach',
            'forData' => $data,
            'key' => $keyVar !== null ? $table->declareFrom($keyVar, [$data]) : null,
            'item' => $table->declareFrom($valueVar, [$data]),
            'loop' => $table->declareFrom('loop', $loopSources),
            'parentLoop' => $parentLoop ? 1 : null,
        ], fn ($v) => $v !== null)];
    }

    /** Blade's @empty inside @forelse: the loop's block ends, the one for no items begins. */
    public function forelseEmpty(): void
    {
        $this->compiler->tree->branch(['forelse'], 'empty', fn () => ['c' => 'empty']);
        $this->context->stack[count($this->context->stack) - 1]['kind'] = 'forelse-empty';
    }

    /** Blade's @for($i = 0; $i < 10; $i++): its iterations, worked out by the expression. */
    public function for(string $args): void
    {
        $c = $this->context;
        $table = $this->compiler->expressionTable;

        [$head, $variables] = $c->transpiler->templateFor($args, $c->scope, $c->locals, $c->line);
        $table->track();
        $declare = $variables === [] ? '' : 'let '.implode(', ', array_map(fn ($v) => '$'.$v, $variables)).'; ';
        $values = '['.implode(', ', array_map(fn ($v) => '$'.$v, $variables)).']';

        $code = $table->expression($this->iterations($declare.$head, $values, '@for'));
        $node = $this->compiler->tree->openBlock('for', [], true);
        $node->fields['i'] = [['c' => 'for', 'code' => $code, 'vars' => array_map(fn ($v) => $table->declareFrom($v, [$code]), $variables)]];
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
        $c = $this->context;
        $loops = array_values(array_filter($c->stack, fn ($frame) => $frame['loop'] ?? false));

        if ($loops === []) {
            throw $c->error("@{$keyword} outside of a loop");
        }

        /** Teleported content renders elsewhere: a loop around the @teleport is not around it there. */
        $inner = array_slice($c->stack, (int) array_key_last(array_filter($c->stack, fn ($frame) => $frame['loop'] ?? false)));

        if (in_array('teleport', array_column($inner, 'kind'), true)) {
            throw $c->error("@{$keyword} inside @teleport cannot leave a loop around the @teleport");
        }

        $node = new TemplateNode(['t' => 'b', 'c' => $keyword, 'levels' => 1]);

        if ($args !== null && preg_match('/^\s*(-?\d+)\s*$/', $args, $m)) {
            $levels = max(1, (int) $m[1]);

            if ($levels > count($loops)) {
                throw $c->error("@{$keyword}({$levels}): not inside that many loops");
            }

            $node->fields['levels'] = $levels;
        } else {
            if ($keyword === 'continue' && end($loops)['kind'] === 'switch') {
                throw $c->error('@continue inside @switch: use @break');
            }

            if ($args !== null) {
                $node->fields['code'] = $this->compiler->expressionTable->condition($args);
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
        $c = $this->context;
        $this->compiler->tree->flushText();
        $top = end($c->stack);

        if ($top['kind'] !== 'switch') {
            throw $c->error('@'.($directive['c'] === 'case' ? 'case' : 'default').' outside of @switch');
        }

        $case = new TemplateNode(['t' => 'template', 'i' => [$directive]]);
        $top['node']->children[] = $case;
        $c->stack[count($c->stack) - 1]['case'] = $case;
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
        $node->fields['i'] = [['c' => 'error', 'code' => $field, 'message' => $table->declareFrom('message', [$field, '$errors'])]];
    }

    /**
     * Livewire's @teleport, as Viewi's <Portal to="modals">: the content renders at @portal('modals')
     * (Viewi's <Portal name="modals">), on the server and in the browser (runtime renderTeleport),
     * with this component's state and handlers. The name is a literal, as Viewi's is.
     */
    public function teleport(string $args): void
    {
        $c = $this->context;

        if ($c->state !== 'data') {
            throw $c->error('@teleport goes between elements, not inside <script>, <style>, <textarea> or <title>');
        }

        if (! preg_match('/^\s*([\'"])([A-Za-z0-9_-]+)\1\s*$/', $args, $name)) {
            throw $c->error("@teleport takes the name of a @portal, as a string literal: @teleport('modals')");
        }

        if (in_array('teleport', array_column($c->stack, 'kind'), true)) {
            throw $c->error('@teleport cannot be inside another @teleport');
        }

        $node = new TemplateNode(['t' => 'p', 'to' => $name[2]]);
        $this->compiler->tree->append($node);
        $c->stack[] = ['kind' => 'teleport', 'node' => $node, 'line' => $c->line, 'locals' => $c->locals, 'sources' => $c->sources, 'loop' => false];
    }

    /** A loop's iterations as a list: each the values its variables have in it. */
    private function iterations(string $loop, string $values, string $directive): string
    {
        return "(() => { const \$\$runs = []; {$loop} { \$\$runs.push({$values}); if (\$\$runs.length > ".Syntax::MAX_ITERATIONS.") throw new Error('{$directive} ran more than ".Syntax::MAX_ITERATIONS." times'); } return \$\$runs; })()";
    }
}
