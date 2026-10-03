<?php

namespace Nitro\Fusion\Compiler\Template\Parts;

use Nitro\Fusion\Compiler\Template\TemplateNode;

/**
 * The expression table: every dynamic part of the view ({{ $x }}, an @if's condition, a loop's
 * data, a handler) becomes a numbered function, and the tree refers to it by number ({e: 1,
 * code: 3}). Fusion's, and the start of its fine-grained rendering:
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
     * template's variables in scope (by position); returns its index.
     */
    public function expression(string $js): int
    {
        $c = $this->context;
        $parameters = implode(', ', ['$this', ...array_map(fn (string $local) => '$'.$local, $c->locals)]);
        $c->expressions[] = "/* ".count($c->expressions)." */ function ({$parameters}) { return {$js}; }";

        /** Viewi's nestedDependencies: an expression reading $item reads what its @foreach reads. */
        $sources = [];

        foreach ($c->locals as $local) {
            if (preg_match('/\$'.preg_quote($local, '/').'\b/', $js) === 1) {
                array_push($sources, ...($c->sources[$local] ?? []));
            }
        }

        $c->expressionSources[] = array_values(array_unique($sources, SORT_REGULAR));

        return count($c->expressions) - 1;
    }

    /** PHP (a Blade expression) compiled to JavaScript. */
    public function expr(string $code): string
    {
        $c = $this->context;
        $js = $c->transpiler->templateExpression($code, $c->scope, $c->locals, $c->line);
        $this->track();

        return $js;
    }

    /** A condition, as PHP reads it, to a JavaScript boolean. */
    public function condition(string $code): int
    {
        return $this->expression('P.bool('.$this->expr($code).')');
    }

    /** A handler: an expression whose value is the function to run ($event => ...). */
    public function handler(string $code): int
    {
        $c = $this->context;
        $c->inHandler = true;

        try {
            $handler = $c->transpiler->handler($code, $c->scope, $c->locals, $c->line);
        } finally {
            $c->inHandler = false;
        }

        $this->track();

        return $this->expression($handler);
    }

    /**
     * Declare a template variable, remembering what its value comes from.
     *
     * @param  list<int|string>  $sources
     */
    public function declareFrom(string $name, array $sources): int
    {
        $this->context->sources[$name] = $sources;

        return $this->declare($name);
    }

    /** The component methods the Transpiler saw called, collected (the compiler compiles those too). */
    public function track(): void
    {
        $c = $this->context;

        foreach ($c->transpiler->calledMethods as $method) {
            $c->calledMethods[$method] = $method;
        }

        $c->transpiler->calledMethods = [];
    }

    /** {{ $x }} (text; the escaping Blade does is what the HTML parser undoes) or {!! $x !!} (HTML). */
    public function echo(string $code, bool $raw): void
    {
        $c = $this->context;
        $tree = $this->compiler->tree;

        /** {{ $slot }}, {{ $slots['footer'] }}: where the parent's slot content goes. */
        if ($c->state === 'data' && preg_match('/^\s*\$slot(?:s\[\s*([\'"])([\w\-]+)\1\s*\])?\s*$/', $code, $slot) && ! in_array('slot', $c->locals, true)) {
            $tree->append(new TemplateNode(['t' => 's', 'c' => $slot[2] ?? 'default']));

            return;
        }

        $value = $this->expr($code);

        match ($c->state) {
            'data' => $tree->append(new TemplateNode(['t' => 'x', 'e' => 1, 'code' => $this->expression('P.toStr('.$value.')'), ...($raw ? ['raw' => 1] : [])])),
            /** In <script> and <style> nothing is decoded: the escaped text is the content. */
            'raw' => $tree->append(new TemplateNode(['t' => 'x', 'e' => 1, 'code' => $this->expression(($raw ? 'P.toStr(' : 'P.e(').$value.')')])),
            'rcdata' => $tree->append(new TemplateNode(['t' => 'x', 'e' => 1, 'code' => $this->expression('P.toStr('.$value.')')])),
            'value' => $this->compiler->html->attributePart(['e' => 1, 'code' => $this->expression('P.toStr('.$value.')')]),
            'tag' => throw $c->error('An echo inside a tag must be an attribute value, such as class="{{ $class }}"'),
            default => throw $c->error('Echoes cannot be used inside an HTML comment or an end tag'),
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
