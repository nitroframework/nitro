<?php

namespace Nitro\Fusion\Compiler\Template\Parts;

use Closure;
use Nitro\Fusion\Compiler\Template\TemplateNode;

/**
 * The node tree being built, through the open stack: where nodes go now, adding one, turning the
 * static text read so far into a text node, and opening, branching (@elseif / @else) and closing
 * a block. General: what the browser's HTML parser would build, nothing Fusion-specific.
 *
 *  - nodes go into the innermost open element or block; in a @switch, into its current @case
 *  - text becomes a node with character references decoded, as the HTML parser does (not in
 *    <script> / <style>), and a newline right after <pre> / <textarea> is dropped, as it does
 *  - a block is a template node carrying its directive ({t: "template", i: [{c: "if", code}]});
 *    it keeps the template variables in scope when it opened, and restores them when it closes,
 *    so a loop's $item does not outlive the loop
 *  - an element opened inside a block must close inside it: a tree cannot hold anything else,
 *    and the error says which element is still open
 *
 * @internal
 */
final class Tree extends Part
{
    /** Where nodes go now: the innermost open element or block (a @switch: its current @case). */
    public function current(): TemplateNode
    {
        $top = end($this->context->stack);

        if ($top['kind'] === 'switch') {
            if ($top['case'] === null) {
                throw $this->context->error('Only @case and @default can follow @switch');
            }

            return $top['case'];
        }

        return $top['node'];
    }

    public function append(TemplateNode $node): void
    {
        $this->flushText();
        $this->current()->children[] = $node;
    }

    /** The static text read so far, as a text node (character references decoded, as the HTML parser does). */
    public function flushText(): void
    {
        $c = $this->context;

        if ($c->text === '') {
            return;
        }

        $text = $c->text;
        $c->text = '';
        /** A slot's text read so far is out of the buffer: what the slot adds from now starts at 0. */
        $c->slotText = array_map(fn () => 0, $c->slotText);
        $top = end($c->stack);

        /** Between @switch and its first @case: Blade's PHP, where only whitespace belongs. */
        if ($top['kind'] === 'switch' && $top['case'] === null) {
            if (trim($text) !== '') {
                throw $c->error('Only @case and @default can follow @switch');
            }

            return;
        }

        $parent = $this->current();

        if ($parent->children === [] && in_array($parent->fields['c'] ?? '', Syntax::LEADING_NEWLINE, true) && ($parent->fields['t'] ?? '') === 't') {
            $text = (string) preg_replace('/\A\r?\n/', '', $text);
        }

        if ($c->state !== 'raw') {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if ($text === '') {
            return;
        }

        $last = end($parent->children);

        if ($last !== false && ($last->fields['t'] ?? '') === 'x' && ! isset($last->fields['e'])) {
            $last->fields['c'] .= $text;

            return;
        }

        $parent->children[] = new TemplateNode(['t' => 'x', 'c' => $text]);
    }

    /** The innermost open element (a block is not one). */
    public function element(): ?array
    {
        for ($i = count($this->context->stack) - 1; $i >= 0; $i--) {
            if ($this->context->stack[$i]['kind'] === 'element') {
                return $this->context->stack[$i];
            }
        }

        return null;
    }

    /**
     * Open a block: a template node carrying its directive (compiled with the variables in scope
     * before the block's own).
     *
     * @param  array<string, mixed>  $directive
     */
    public function openBlock(string $kind, array $directive, bool $loop = false): TemplateNode
    {
        $c = $this->context;
        $node = new TemplateNode(['t' => 'template', 'i' => [$directive]]);
        $this->append($node);
        $c->stack[] = ['kind' => $kind, 'node' => $node, 'line' => $c->line, 'locals' => $c->locals, 'sources' => $c->sources, 'loop' => $loop];

        return $node;
    }

    /**
     * @elseif / @else: the open branch ends, the next begins (a sibling template node).
     *
     * @param  list<string>  $kinds
     * @param  Closure(): array<string, mixed>  $directive
     */
    public function branch(array $kinds, string $name, Closure $directive): void
    {
        $c = $this->context;
        $this->flushText();
        $top = end($c->stack);

        if (! in_array($top['kind'], $kinds, true)) {
            throw $c->error($top['kind'] === 'element'
                ? "<{$top['tag']}> (line {$top['line']}) must be closed before @{$name}"
                : "@{$name} outside of ".implode('/', array_map(fn ($k) => "@{$k}", $kinds)));
        }

        array_pop($c->stack);
        $c->locals = $top['locals'];
        $c->sources = $top['sources'] ?? $c->sources;

        $node = new TemplateNode(['t' => 'template', 'i' => [$directive()]]);
        $this->current()->children[] = $node;
        $c->stack[] = ['kind' => $top['kind'], 'node' => $node, 'line' => $c->line, 'locals' => $c->locals, 'sources' => $c->sources, 'loop' => false];
    }

    /** @param  list<string>  $kinds */
    public function closeBlock(array $kinds): void
    {
        $c = $this->context;
        $this->flushText();
        $top = end($c->stack);

        if ($top['kind'] === 'element') {
            throw $c->error("<{$top['tag']}> (line {$top['line']}) must be closed before @end{$kinds[0]}: open and close an element inside the same block");
        }

        if (! in_array($top['kind'], $kinds, true)) {
            throw $c->error('Unexpected @end'.$kinds[0].($top['kind'] !== 'root' ? " (@{$top['kind']} from line {$top['line']} is still open)" : ''));
        }

        array_pop($c->stack);
        $c->locals = $top['locals'];
        $c->sources = $top['sources'] ?? $c->sources;
    }
}
