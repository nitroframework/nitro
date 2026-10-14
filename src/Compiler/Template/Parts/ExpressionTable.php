<?php

namespace Nitro\Compiler\Template\Parts;

use Nitro\Compiler\Template\PhpSource;
use Nitro\Compiler\Template\TemplateNode;

/**
 * The expression table: every dynamic part of the view ({{ $x }}, an @if's condition, a loop's
 * data, a handler) becomes a numbered function, and the tree refers to it by number ({e: 1,
 * code: 3}). Nitro's, and the start of its fine-grained rendering:
 *
 *  - an expression is `function ($this, $item, $loop, ...) { return ...; }`: the component and
 *    the template variables in scope, by argument position, so a render can call it for any
 *    iteration of the loops around it
 *  - for each expression, the table also records what its template variables come from
 *    (expressionSources): $item reads what its @foreach's data reads. With the component state
 *    each expression reads (Subscriptions), that is how the browser re-runs only the expressions
 *    a change affects, rather than re-rendering the view (Viewi's nestedDependencies)
 *  - PHP goes through the Transpiler (templateExpression(), handler(), templateFor()); the
 *    component methods it calls are collected in $calledMethods, so they are compiled too
 *  - {{ }} is text (P.toStr: the escaping Blade does, the HTML parser undoes), {!! !!} HTML; in
 *    <script> / <style> an escaped echo stays escaped, as the server's output is
 *  - {{ $slot }} / {{ $slots['footer'] }}: where the parent's slot content goes (a slot node)
 *
 * @internal
 */
final class ExpressionTable extends Part
{
    /**
     * Add a JavaScript expression to the table, as a function of the component and the
     * template's variables in scope (by position); returns its index. $php: its twin for the
     * server's render (the same index), what the server prints or reads there; null for what
     * runs in the browser only (a handler).
     */
    public function expression(string $js, ?string $php): int
    {
        $context = $this->context;
        $parameters = implode(', ', ['$this', ...array_map(fn (string $local) => '$'.$local, $context->locals)]);
        $context->expressions[] = "/* ".count($context->expressions)." */ function ({$parameters}) { return {$js}; }";
        $context->phpExpressions[] = $php === null ? null : ['locals' => $context->locals, 'php' => $php];

        /** Viewi's nestedDependencies: an expression reading $item reads what its @foreach reads. */
        $sources = [];

        foreach ($context->locals as $local) {
            if (preg_match('/\$'.preg_quote($local, '/').'\b/', $js) === 1) {
                array_push($sources, ...($context->sources[$local] ?? []));
            }
        }

        $context->expressionSources[] = array_values(array_unique($sources, SORT_REGULAR));

        return count($context->expressions) - 1;
    }

    /** PHP (a Blade expression) compiled to JavaScript. */
    public function expr(string $code): string
    {
        $context = $this->context;
        $this->compiler->blocks->leftRead($code);
        $js = $context->transpiler->templateExpression($code, $context->scope, $context->locals, $context->line);
        $this->track();

        return $js;
    }

    /**
     * A Blade expression as the server's render runs it: parsed as expr() parses it (an inlined
     * view's variables replaced, renamed loop variables), names its @use resolved written out.
     */
    public function php(string $code): string
    {
        return PhpSource::printResolved($this->context->transpiler->parseExpression($code, $this->context->line));
    }

    /** A condition, as PHP reads it, to a JavaScript boolean. */
    public function condition(string $code): int
    {
        return $this->expression('P.bool('.$this->expr($code).')', '(bool) ('.$this->php($code).')');
    }

    /** A handler: an expression whose value is the function to run ($event => ...). */
    public function handler(string $code): int
    {
        $context = $this->context;
        $this->compiler->blocks->leftRead($code);
        $context->inHandler = true;

        try {
            $handler = $context->transpiler->handler($code, $context->scope, $context->locals, $context->line);
        } finally {
            $context->inHandler = false;
        }

        $this->track();

        return $this->expression($handler, null);
    }

    /**
     * Declare a template variable, remembering what its value comes from.
     *
     * @param  list<int|string>  $sources
     */
    public function declareFrom(string $name, array $sources): int
    {
        $name = $this->own($name);
        $this->context->sources[$name] = $sources;

        return $this->declare($name);
    }

    /**
     * The name a variable an inlined view declares is held under. The view's own loop variable
     * named as one around the view ($row in a view included in the page's loop over $row) is a
     * variable of its own: the view reads it under another name (until the block declaring it
     * ends: Tree). $loop stays one in an included view (it sees the variables around it); in a view
     * that sees only its own (@each's), its loops' $loop is its own, the loop around it their
     * parent (visibleLoop()), as Blade's one loop stack has it.
     */
    private function own(string $name): string
    {
        $context = $this->context;
        $frame = array_key_last($context->frames);
        $position = array_search($name, $context->locals, true);
        $mark = $name === 'loop' ? $this->isolatedMark() : ($frame === null ? 0 : $context->frames[$frame]['mark']);

        if ($frame === null || $position === false || $position >= $mark) {
            return $name;
        }

        $own = '__'.$name.'_'.count($context->locals);
        $context->frames[$frame]['vars'][$name] = new \PhpParser\Node\Expr\Variable($own);

        return $own;
    }

    /** Where the innermost view that sees only its own variables begins (0: none). */
    private function isolatedMark(): int
    {
        foreach (array_reverse($this->context->frames) as $frame) {
            if ($frame['isolated']) {
                return $frame['mark'];
            }
        }

        return 0;
    }

    /**
     * The $loop a loop opening here has as its parent: the template variable $loop reads where
     * it opens (renamed in a view: own()), seen through the views around it as Blade's one loop
     * stack sees it; null when no loop is around.
     *
     * @return array{0: int, 1: string}|null its position and name
     */
    public function visibleLoop(): ?array
    {
        $context = $this->context;
        $name = 'loop';

        foreach (array_reverse($context->frames) as $frame) {
            if (($frame['vars']['loop'] ?? null) instanceof \PhpParser\Node\Expr\Variable && is_string($frame['vars']['loop']->name)) {
                $name = $frame['vars']['loop']->name;

                break;
            }
        }

        $position = array_search($name, $context->locals, true);

        return $position === false ? null : [$position, $name];
    }

    /** The component methods the Transpiler saw called, collected (the compiler compiles those too). */
    public function track(): void
    {
        $context = $this->context;

        foreach ($context->transpiler->calledMethods as $method) {
            $context->calledMethods[$method] = $method;
        }

        $context->transpiler->calledMethods = [];
    }

    /** {{ $x }} (text; the escaping Blade does is what the HTML parser undoes) or {!! $x !!} (HTML). */
    public function echo(string $code, bool $raw): void
    {
        $context = $this->context;
        $tree = $this->compiler->tree;

        /** {{ $slot }}, {{ $slots['footer'] }}: where the parent's slot content goes. */
        if ($context->state === 'data' && preg_match('/^\s*\$slot(?:s\[\s*([\'"])([\w\-]+)\1\s*\])?\s*$/', $code, $slot) && ! in_array('slot', $context->locals, true)) {
            $tree->append(new TemplateNode(['t' => 's', 'c' => $slot[2] ?? 'default']));
            $this->compiler->nesting->slotPlaced($slot[2] ?? 'default');

            return;
        }

        $value = $this->expr($code);
        $php = $this->php($code);

        if ($raw) {
            $this->rawOutput('P.toStr('.$value.')', $php);

            return;
        }

        /**
         * {{ }}: Blade's e(), which the HTML parser undoes (in <script> and <style> nothing is
         * decoded: the escaped text is the content). The server prints e()'s HTML, as Blade's.
         */
        match ($context->state) {
            'data', 'rcdata' => $tree->append(new TemplateNode(['t' => 'x', 'e' => 1, 'code' => $this->expression('P.toStr('.$value.')', "e({$php})")])),
            'raw' => $tree->append(new TemplateNode(['t' => 'x', 'e' => 1, 'code' => $this->expression('P.e('.$value.')', "e({$php})")])),
            'value' => $this->compiler->html->attributePart(['e' => 1, 'code' => $this->expression('P.toStr('.$value.')', "e({$php})")]),
            'tag' => throw $context->error('An echo inside a tag must be an attribute value, such as class="{{ $class }}"'),
            default => throw $context->error('Echoes cannot be used inside an HTML comment or an end tag'),
        };
    }

    /**
     * Output Blade prints as it is ({!! !!}, @json, @lang, @choice: $js, a string; $php, what the
     * server prints), read as the HTML parser reads it where it is: HTML in the page; text in
     * <script> and <style>; text with its character references decoded (d) in <textarea>,
     * <title> and an attribute's value.
     */
    public function rawOutput(string $js, string $php): void
    {
        $context = $this->context;
        $tree = $this->compiler->tree;
        $code = $this->expression($js, $php);

        match ($context->state) {
            'data' => $tree->append(new TemplateNode(['t' => 'x', 'e' => 1, 'raw' => 1, 'code' => $code])),
            'raw' => $tree->append(new TemplateNode(['t' => 'x', 'e' => 1, 'code' => $code])),
            'rcdata' => $tree->append(new TemplateNode(['t' => 'x', 'e' => 1, 'd' => 1, 'code' => $code])),
            'value' => $this->compiler->html->attributePart(['e' => 1, 'd' => 1, 'code' => $code]),
            'tag' => throw $context->error('Output inside a tag must be an attribute value, such as title="{!! $title !!}"'),
            default => throw $context->error('Output cannot be used inside an HTML comment or an end tag'),
        };
    }

    /** A template variable's argument position, declaring it (after those in scope) if it is new. */
    private function declare(string $name): int
    {
        $position = array_search($name, $this->context->locals, true);

        if ($position === false) {
            $this->context->locals[] = $name;

            return count($this->context->locals) - 1;
        }

        return $position;
    }
}
