<?php

namespace Nitro\Compiler\Template\Parts;

use Closure;
use Nitro\Compiler\Template\TemplateNode;
use Nitro\Support\Html;

/**
 * The node tree being built, through the open stack: where nodes go now, adding one, turning the
 * static text read so far into a text node, and opening, branching (@elseif / @else) and closing
 * a block. General: what the browser's HTML parser would build, nothing Nitro-specific.
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

    /**
     * The table elements the HTML parser adds where the view leaves them out (they are optional in
     * HTML): <tbody> around a <tr> in a <table>, <tr> around a <td> or <th>, <colgroup> around a
     * <col>; and those it closes for a tag that ends them (a <tr> after an implied row, an explicit
     * <tbody>, anything but a <col> after an implied <colgroup>). Added to the tree as the parser
     * adds them, so both renders give its DOM. Only where the tag is directly in the element: in a
     * block, the parser adds one only if the block renders it (Nesting refuses that).
     */
    public function implied(string $tag): void
    {
        $context = $this->context;
        $tag = strtolower($tag);

        while (($top = end($context->stack)) !== false && ($top['implied'] ?? false) && in_array($tag, self::IMPLIED_ENDS[$top['tag']], true) !== ($top['tag'] === 'colgroup')) {
            array_pop($context->stack);
        }

        $top = end($context->stack);

        if ($top === false || $top['kind'] !== 'element' || ($top['ns'] ?? 'html') !== 'html') {
            return;
        }

        foreach (self::IMPLIES[$top['tag']][$tag] ?? [] as $wrapper) {
            $node = new TemplateNode(['t' => 't', 'c' => $wrapper]);
            $this->current()->children[] = $node;
            $context->stack[] = ['kind' => 'element', 'node' => $node, 'line' => $context->tagLine, 'locals' => $context->locals, 'tag' => $wrapper, 'ns' => 'html', 'implied' => true];
        }
    }

    /** The implied elements open on top (implied()), left: what closes the element they are in, or a block's end, closes them. */
    public function leaveImplied(): void
    {
        while (($top = end($this->context->stack)) !== false && ($top['implied'] ?? false)) {
            array_pop($this->context->stack);
        }
    }

    /** By the element it is in, then by the tag: the elements the HTML parser adds around it ("in table", "in table body"). */
    private const IMPLIES = [
        'table' => ['tr' => ['tbody'], 'td' => ['tbody', 'tr'], 'th' => ['tbody', 'tr'], 'col' => ['colgroup']],
        'tbody' => ['td' => ['tr'], 'th' => ['tr']],
        'thead' => ['td' => ['tr'], 'th' => ['tr']],
        'tfoot' => ['td' => ['tr'], 'th' => ['tr']],
    ];

    /** By implied element: the tags that end it (an implied <colgroup>: all but these). */
    private const IMPLIED_ENDS = [
        'tbody' => ['tbody', 'thead', 'tfoot', 'caption', 'colgroup', 'col'],
        'tr' => ['tr', 'tbody', 'thead', 'tfoot', 'caption', 'colgroup', 'col'],
        'colgroup' => ['col', 'template'],
    ];

    public function append(TemplateNode $node): void
    {
        $this->flushText();

        /** Anything but a <col> (or whitespace) ends an implied <colgroup>, as the parser's "in column group". */
        if (($top = end($this->context->stack)) !== false && ($top['implied'] ?? false) && $top['tag'] === 'colgroup') {
            array_pop($this->context->stack);
        }

        $parent = $this->current();

        /**
         * <pre>, <textarea>, <listing> whose content starts with what renders (an echo, a block):
         * the HTML parser drops a newline it starts with, which the browser drops as it renders (nl).
         */
        if (self::startsContent($parent)) {
            $parent->fields['nl'] = 1;
        }

        $parent->started = true;
        $parent->children[] = $node;
    }

    /** The static text read so far, as a text node (character references decoded, as the HTML parser does). */
    public function flushText(): void
    {
        $context = $this->context;

        if ($context->text === '') {
            return;
        }

        $text = $context->text;
        $context->text = '';
        /** A slot's text read so far is out of the buffer: what the slot adds from now starts at 0. */
        $context->slotText = array_map(fn () => 0, $context->slotText);
        $top = end($context->stack);

        /** Between @switch and its first @case: Blade's PHP, where only whitespace belongs. */
        if ($top['kind'] === 'switch' && $top['case'] === null) {
            if (trim($text) !== '') {
                throw $context->error('Only @case and @default can follow @switch');
            }

            return;
        }

        $parent = $this->current();

        if (self::startsContent($parent)) {
            $text = (string) preg_replace('/\A\r?\n/', '', $text);
        }

        $parent->started = true;

        $source = $text;

        if ($context->state !== 'raw') {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if ($text === '') {
            return;
        }

        $last = end($parent->children);

        if ($last !== false && ($last->fields['t'] ?? '') === 'x' && ! isset($last->fields['e'])) {
            $last->fields = self::joined($last->fields, self::sourced($text, $source));

            return;
        }

        $parent->children[] = new TemplateNode(['t' => 'x', ...self::sourced($text, $source)]);
    }

    /** What is read now is the first of a <pre>'s, <textarea>'s or <listing>'s content: a newline it starts with, the HTML parser drops. */
    private static function startsContent(TemplateNode $parent): bool
    {
        return ! $parent->started && $parent->children === [] && ($parent->fields['t'] ?? '') === 't' && in_array($parent->fields['c'] ?? '', Html::LEADING_NEWLINE, true);
    }

    /**
     * Static text (a text node's, an attribute value's part): as the HTML parser reads it (c), and
     * as Blade prints it (src) where that differs (its character references): the server's render
     * writes what Blade does (View\TreeRenderer), which Blade's trims trim as Blade's.
     *
     * @return array{c: string, src?: string}
     */
    public static function sourced(string $text, string $source): array
    {
        return $text === $source ? ['c' => $text] : ['c' => $text, 'src' => $source];
    }

    /**
     * Static text after static text: one.
     *
     * @param  array<string, mixed>  $first
     * @param  array{c: string, src?: string}  $then
     * @return array<string, mixed>
     */
    public static function joined(array $first, array $then): array
    {
        if (isset($first['src']) || isset($then['src'])) {
            $first['src'] = ($first['src'] ?? $first['c']).($then['src'] ?? $then['c']);
        }

        $first['c'] .= $then['c'];

        return $first;
    }

    /**
     * The tree as the browser gets it: without Blade's source of its text (src), which only the
     * server's render writes.
     *
     * @param  array<string, mixed>  $tree
     * @return array<string, mixed>
     */
    public static function forBrowser(array $tree): array
    {
        /**
         * Static text's: a slot or an attribute may be named src. Where a character reference
         * gives whitespace at its start or end (&#32;), how much of it Blade's trims can take (the
         * literal whitespace: ls at the start, ts at the end), for the browser's (trim.js).
         */
        if (isset($tree['src'], $tree['c']) && is_string($tree['src']) && is_string($tree['c'])) {
            $start = fn (string $text) => strlen($text) - strlen(ltrim($text));
            $end = fn (string $text) => strlen($text) - strlen(rtrim($text));

            if ($start($tree['src']) < $start($tree['c'])) {
                $tree['ls'] = $start($tree['src']);
            }

            if ($end($tree['src']) < $end($tree['c'])) {
                $tree['ts'] = $end($tree['src']);
            }

            unset($tree['src']);
        }

        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $tree[$key] = self::forBrowser($value);
            }
        }

        return $tree;
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
        $context = $this->context;
        $node = new TemplateNode(['t' => 'template', 'i' => [$directive]]);
        $this->append($node);
        $context->stack[] = ['kind' => $kind, 'node' => $node, 'line' => $context->line, 'locals' => $context->locals, 'sources' => $context->sources, 'frames' => $context->frames, 'loop' => $loop, 'left' => $context->left];

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
        $context = $this->context;
        $this->flushText();
        $this->leaveImplied();
        $top = end($context->stack);

        if (! in_array($top['kind'], $kinds, true)) {
            throw $context->error($top['kind'] === 'element'
                ? "<{$top['tag']}> (line {$top['line']}) must be closed before @{$name}"
                : "@{$name} outside of ".implode('/', array_map(fn ($kind) => "@{$kind}", $kinds)));
        }

        /** After @else, PHP's if has no other branch (Blade's output is a parse error). */
        if ($top['else'] ?? false) {
            throw $context->error("@{$name} after the @else of line {$top['line']}: @else is the last branch");
        }

        array_pop($context->stack);
        $this->restore($top);

        /**
         * What a loop left set in the branch that ends is that branch's: the next one runs only when
         * it did not (PHP's if), so it starts without it; the chain's end has every branch's.
         */
        $branchesLeft = ($top['branchesLeft'] ?? []) + array_diff_key($context->left, $top['left'] ?? []);
        $context->left = $top['left'] ?? $context->left;

        /** @forelse's @empty: the loop's variables are as before it (no iteration set them) until @endforelse. */
        $node = new TemplateNode(['t' => 'template', 'i' => [$directive()]]);
        $this->current()->children[] = $node;
        $context->stack[] = ['kind' => $top['kind'], 'node' => $node, 'line' => $context->line, 'locals' => $context->locals, 'sources' => $context->sources, 'frames' => $context->frames, 'loop' => false, 'leaves' => $top['leaves'] ?? [], 'else' => $name === 'else', 'left' => $context->left, 'branchesLeft' => $branchesLeft];
    }

    /** @param  list<string>  $kinds */
    public function closeBlock(array $kinds): void
    {
        $context = $this->context;
        $this->flushText();
        $this->leaveImplied();
        $top = end($context->stack);

        if ($top['kind'] === 'element') {
            throw $context->error("<{$top['tag']}> (line {$top['line']}) must be closed before @end{$kinds[0]}: open and close an element inside the same block");
        }

        if (! in_array($top['kind'], $kinds, true)) {
            throw $context->error('Unexpected @end'.$kinds[0].($top['kind'] !== 'root' ? " (@{$top['kind']} from line {$top['line']} is still open)" : ''));
        }

        array_pop($context->stack);
        $this->restore($top);
        $context->left += $top['branchesLeft'] ?? [];
        $this->compiler->blocks->ended($top['leaves'] ?? []);
    }

    /**
     * A block's end: the template variables in scope when it opened again, and the frames' (what
     * a view's own variable it renamed in the block read as: a frame opened in a block closes in it).
     *
     * @param  array<string, mixed>  $top
     */
    private function restore(array $top): void
    {
        $context = $this->context;
        $context->locals = $top['locals'];
        $context->sources = $top['sources'] ?? $context->sources;
        $context->frames = $top['frames'] ?? $context->frames;
    }
}
