<?php

namespace Nitro\Compiler\Template\Parts;

use Nitro\Compiler\Template\PhpSource;
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
    /**
     * $loopName: the variable its loop state is in: $loop for Blade's loops; another for @each's
     * iterations (its renderEach() is PHP's foreach: no $loop, and the view's loops' parent is the
     * loop around the @each, as Blade's loop stack has it).
     */
    public function foreach(string $args, bool $forelse, string $loopName = 'loop'): void
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

        $data = $table->expression($table->expr($iteratee), $table->php($iteratee));
        /** $loop->parent is the loop around it (where its $loop is): its state comes from that loop's data too. */
        $parent = $table->visibleLoop();
        $parentLoop = $parent[0] ?? null;
        /** A Blade component's view's outermost loop: its parent is the caller's $loop, which the call signals (calls.js). */
        $parentSources = $parent !== null ? $context->sources[$parent[1]] ?? [] : ($this->compiler->componentView ? [Protocol::signal('callerLoop')] : []);
        $loopSources = [$data, ...$parentSources];
        $node = $this->compiler->tree->openBlock($forelse ? 'forelse' : 'foreach', [], $loopName === 'loop');

        $this->declares(array_filter([$keyVar, $valueVar]));
        $key = $keyVar !== null ? $table->declareFrom($keyVar, [$data]) : null;
        $item = $table->declareFrom($valueVar, [$data]);
        $node->fields['i'] = [array_filter([
            'c' => 'foreach',
            'forData' => $data,
            'key' => $key,
            'item' => $item,
            'loop' => $table->declareFrom($loopName, $loopSources),
            'parentLoop' => $parentLoop,
        ], fn ($value) => $value !== null)];
        $this->leaves($node, ($keyVar === null ? [] : [$keyVar => (int) $key]) + [$valueVar => $item], [$data], true);
    }

    /**
     * The variables a loop's PHP leaves set when it ends (foreach's, for's), by where the loop
     * has them: read after the loop, they have their last value (leftRead()).
     *
     * @param  array<string, int>  $positions  name => its position in the loop
     * @param  list<int|string>  $sources  what the loop's iterations come from
     * @param  bool  $kept  the variable keeps the value it had when the loop runs no iteration (foreach's; for's are set before it runs)
     */
    private function leaves(TemplateNode $node, array $positions, array $sources, bool $kept): void
    {
        $leaves = [];

        foreach ($positions as $name => $position) {
            $leaves[$name] = ['node' => $node, 'from' => $position, 'sources' => $sources, 'kept' => $kept];
        }

        $this->context->stack[array_key_last($this->context->stack)]['leaves'] = $leaves;
    }

    /**
     * A loop ended (Tree): the variables it leaves are known as such in this view, and in the
     * views it includes, from here on.
     *
     * @param  array<string, array{node: TemplateNode, from: int, sources: list<int|string>, kept: bool}>  $leaves
     */
    public function ended(array $leaves): void
    {
        foreach ($leaves as $name => $left) {
            $this->context->left[$name] = $left + ['depth' => count($this->context->frames), 'blocks' => $this->openBlocks()];
        }
    }

    /**
     * The blocks open here, outermost first: a loop sets what it leaves in the scope it renders in,
     * which the nodes after it in those blocks share (a loop's iteration, an @error branch, are
     * scopes of their own).
     *
     * @return list<int>
     */
    private function openBlocks(): array
    {
        return array_values(array_map(fn (array $frame) => spl_object_id($frame['node']), array_filter($this->context->stack, fn (array $frame) => $frame['kind'] !== 'element')));
    }

    /**
     * The variables a loop before left set that the code reads, where they are not the
     * template's: each declared here, and the loop told to set it as it ends (its directive's
     * left: [its position in the loop, here, and what it was before the loop]), as PHP's loop
     * leaves it. Not where a view opened since gives the name (givenAt: @include's data is merged
     * over the variables around it, Factory::make()).
     */
    public function leftRead(string $code): void
    {
        $context = $this->context;

        if ($context->left === [] || ! preg_match_all('/\$(\w+)/', $code, $names)) {
            return;
        }

        foreach (array_unique($names[1]) as $name) {
            $left = $context->left[$name] ?? null;
            $givenAt = $this->compiler->inlinedViews->givenAt($name);

            if ($left === null || ($givenAt !== null && $givenAt > $left['depth'])) {
                continue;
            }

            /** Read after a block the loop is in ended (an @if's branch, an iteration): the value is that block's, which may not have run. */
            if (array_slice($this->openBlocks(), 0, count($left['blocks'])) !== $left['blocks']) {
                throw $context->error("\${$name} is a loop's variable read after the block the loop is in: PHP keeps the value it had there, if the block ran, which the browser does not. Read it inside that block, or set it outside the loop");
            }

            $table = $this->compiler->expressionTable;
            $position = array_search($name, $context->locals, true);

            /** Already a variable of the template's (an outer loop's of that name, an earlier read's): the loop sets it; no iteration keeps it. */
            if ($position !== false) {
                $entry = [$left['from'], $position];
            } else {
                /** What it was before the loop, for a loop that runs no iteration: read as it is here (a property, the view's data); null when it was nothing. */
                $before = null;

                if ($left['kept']) {
                    unset($context->left[$name]);

                    try {
                        $before = $table->expression($table->expr('$'.$name), $table->php('$'.$name));
                    } catch (\Nitro\Compiler\CompileException) {
                        $before = null;
                    } finally {
                        $context->left[$name] = $left;
                    }
                }

                $position = $table->declareFrom($name, [...$left['sources'], ...($before === null ? [] : [$before])]);
                $entry = $left['kept'] ? [$left['from'], $position, $before] : [$left['from'], $position];
            }

            if (! in_array($entry, $left['node']->fields['i'][0]['left'] ?? [], true)) {
                $left['node']->fields['i'][0]['left'][] = $entry;
            }
        }
    }

    /**
     * A loop declares its variables: while it runs, they are its own, not what a loop before left.
     *
     * @param  list<string>  $names
     */
    private function declares(array $names): void
    {
        foreach ($names as $name) {
            unset($this->context->left[$name]);
        }
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

        $this->leftRead($args);
        [$head, $variables, $php] = $context->transpiler->templateFor($args, $context->scope, $context->locals, $context->line);
        $table->track();
        $declare = $variables === [] ? '' : 'let '.implode(', ', array_map(fn ($variable) => '$'.$variable, $variables)).'; ';
        $values = '['.implode(', ', array_map(fn ($variable) => '$'.$variable, $variables)).']';

        $code = $table->expression($this->iterations($declare.$head, $values, '@for'), self::phpIterations($php, $values, '@for', $variables));
        $node = $this->compiler->tree->openBlock('for', [], true);
        $this->declares($variables);
        $vars = array_map(fn ($variable) => $table->declareFrom($variable, [$code]), $variables);
        $node->fields['i'] = [['c' => 'for', 'code' => $code, 'vars' => $vars]];
        $this->leaves($node, array_combine($variables, $vars), [$code], false);
    }

    public function while(string $args): void
    {
        $table = $this->compiler->expressionTable;
        $code = $table->expression($this->iterations('while (P.bool('.$table->expr($args).'))', '[]', '@while'), self::phpIterations('while ('.$table->php($args).')', '[]', '@while', []));
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
        $this->compiler->tree->openBlock('switch', ['c' => 'switch', 'code' => $table->expression($table->expr($args), $table->php($args))], true);
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

    /**
     * Blade's @error('field'): the component's validation message for the field, as $message.
     * The field by the browser's name, as $errors->has() reads it (Transpiler::errorField()).
     */
    public function errorBlock(string $args): void
    {
        $context = $this->context;
        $table = $this->compiler->expressionTable;
        /** The server's: Blade's [field, bag] (CompilesErrors: $errors->getBag($bag ?? 'default')). */
        $this->leftRead($args);
        $field = $table->expression($context->transpiler->errorField($args, $context->scope, $context->locals, $context->line), $table->php('['.$args.']'));
        $table->track();
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

    /** A loop's iterations ({runs, after}): the values its variables have in each, and after it (what PHP's loop leaves them). */
    private function iterations(string $loop, string $values, string $directive): string
    {
        return "(() => { const \$\$runs = []; {$loop} { \$\$runs.push({$values}); if (\$\$runs.length > ".Syntax::MAX_ITERATIONS.") throw new Error('{$directive} ran more than ".Syntax::MAX_ITERATIONS." times'); } return { runs: \$\$runs, after: {$values} }; })()";
    }

    /**
     * iterations() for the server's render, in PHP: the loop run in a closure of its own, which
     * takes the variables around it it reads (not those its loop declares).
     *
     * @param  list<string>  $declared
     */
    private static function phpIterations(string $loop, string $values, string $directive, array $declared): string
    {
        $uses = array_diff(PhpSource::variablesIn($loop.' {}'), $declared);
        $use = $uses === [] ? '' : ' use ('.implode(', ', array_map(fn (string $name) => '$'.$name, $uses)).')';

        return "(function (){$use} { \$__runs = []; {$loop} { \$__runs[] = {$values}; if (count(\$__runs) > ".Syntax::MAX_ITERATIONS.") { throw new \\RuntimeException('{$directive} ran more than ".Syntax::MAX_ITERATIONS." times'); } } return ['runs' => \$__runs, 'after' => {$values}]; })()";
    }
}
