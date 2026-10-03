<?php

namespace Nitro\Fusion\Compiler\Php;

use Nitro\Fusion\Compiler\CompileException;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;

/**
 * Writes: assignments, compound assignments (+=, .=, ??=, ...), ++ / --, and destructuring
 * ([$a, $b] = ..., ['x' => $x] = ...). The most delicate part of the transpiler, because a PHP
 * array is a value and a JavaScript array is not:
 *
 *  - every write under a key ($a['x'][] = 1, $this->items[$i]['done'] = true) builds a new array
 *    along the path it changes (P.setIn) and assigns it back to its root, the variable or the
 *    component property; nothing else holding the old array sees the change, as in PHP
 *  - a write's target is a root and a key path (target()); null in the path appends ([])
 *  - keys read and then written ($a[f()] += 1) are evaluated once, into temporaries
 *  - destructuring takes the value first (so [$a, $b] = [$b, $a] swaps), then assigns in order
 *  - an assignment used as a value ($x = $y = 1) keeps the assigned value in a temporary
 *
 * The Fusion details, in target():
 *
 *  - $this->prop writes the component's state ($this.prop, by the browser's name), and only a
 *    public property: a #[Computed] one is worked out, a #[Locked] one only the server changes
 *  - a template cannot assign to variables (it renders; methods change state)
 *  - $x = Fusion::browser()->...: the variable keeps that browser API's class, for the calls on it
 *
 * @internal
 */
final class Writes extends Part
{
    /** $x = ...: an assignment; a variable given a Fusion::browser() API keeps its class, for the calls on it. */
    public function assignment(Expr\Assign $e, bool $valueUsed): string
    {
        if ($e->var instanceof Expr\Variable && is_string($e->var->name)) {
            $api = $this->transpiler->component->browserApi($e->expr);

            if ($api !== null) {
                $this->context->browserVariables[$e->var->name] = $api;
            } else {
                unset($this->context->browserVariables[$e->var->name]);
            }
        }

        return $this->assign($e->var, $this->transpiler->expr($e->expr), $valueUsed);
    }

    /**
     * What an assignment writes to: a root (`$x` or `$this.prop`) and the key path under it
     * (JS key expressions; null appends).
     *
     * @return array{0: string, 1: list<string>}
     */
    public function target(Expr $e): array
    {
        $c = $this->context;

        if ($e instanceof Expr\Variable) {
            if (! is_string($e->name) || $e->name === 'this') {
                throw $c->error('Cannot assign to this variable in the browser', $e);
            }

            if ($c->scope->variable !== null && ! isset($c->locals[$e->name])) {
                throw $c->error('A template cannot assign to variables', $e);
            }

            $c->locals[$e->name] = true;

            return ['$'.$e->name, []];
        }

        if ($e instanceof Expr\PropertyFetch && $c->isThis($e->var) && $e->name instanceof Identifier) {
            $name = $e->name->toString();

            if ($c->scope->isComputed($name)) {
                throw $c->error("\$this->{$name} is #[Computed]: it is worked out from the state, change the properties it reads instead", $e);
            }

            if (! $c->scope->isProperty($name)) {
                throw $c->error("\$this->{$name} is not a public property of the component", $e);
            }

            if ($c->scope->isLocked($name)) {
                throw $c->error("\$this->{$name} is #[Locked]: only the server (mount() or a #[Server] method) can change it", $e);
            }

            return ['$this.'.$c->scope->browserProperty($name), []];
        }

        if ($e instanceof Expr\ArrayDimFetch) {
            [$root, $path] = $this->target($e->var);
            $path[] = $e->dim === null ? 'null' : $this->transpiler->expr($e->dim);

            return [$root, $path];
        }

        if ($e instanceof Expr\PropertyFetch && $e->name instanceof Identifier) {
            [$root, $path] = $this->target($e->var);

            if (! str_starts_with($root, '$this.')) {
                throw $c->error('Assigning to a property of a value only works through a component property ($this->post->title = ...): values are copies in the browser', $e);
            }

            $path[] = $c->string($e->name->toString());

            return [$root, $path];
        }

        throw $c->error('Cannot assign to this expression in the browser', $e);
    }

    public function assign(Expr $var, string $value, bool $valueUsed): string
    {
        if ($var instanceof Expr\List_ || $var instanceof Expr\Array_) {
            return $this->destructure($var, $value, $valueUsed);
        }

        [$root, $path] = $this->target($var);

        if ($path === []) {
            return "({$root} = {$value})";
        }

        if (! $valueUsed) {
            return "{$root} = P.setIn({$root}, [".implode(', ', $path)."], {$value})";
        }

        $t = $this->context->temp();

        return "({$root} = P.setIn({$root}, [".implode(', ', $path)."], {$t} = {$value}), {$t})";
    }

    public function assignOp(Expr\AssignOp $e, bool $valueUsed): string
    {
        [$root, $path] = $this->target($e->var);
        [$root, $path, $prefix] = $this->stablePath($root, $path);
        $current = $path === [] ? $root : "P.getIn({$root}, [".implode(', ', $path).'])';
        $operand = $this->transpiler->expr($e->expr);

        $value = match (true) {
            $e instanceof Expr\AssignOp\Plus => "P.add({$current}, {$operand})",
            $e instanceof Expr\AssignOp\Minus => "P.sub({$current}, {$operand})",
            $e instanceof Expr\AssignOp\Mul => "P.mul({$current}, {$operand})",
            $e instanceof Expr\AssignOp\Div => "P.div({$current}, {$operand})",
            $e instanceof Expr\AssignOp\Mod => "P.mod({$current}, {$operand})",
            $e instanceof Expr\AssignOp\Pow => "P.pow({$current}, {$operand})",
            $e instanceof Expr\AssignOp\Concat => "P.concat({$current}, {$operand})",
            $e instanceof Expr\AssignOp\Coalesce => "P.coalesce({$current}, () => {$operand})",
            $e instanceof Expr\AssignOp\BitwiseAnd => "(P.toInt({$current}) & P.toInt({$operand}))",
            $e instanceof Expr\AssignOp\BitwiseOr => "(P.toInt({$current}) | P.toInt({$operand}))",
            $e instanceof Expr\AssignOp\BitwiseXor => "(P.toInt({$current}) ^ P.toInt({$operand}))",
            $e instanceof Expr\AssignOp\ShiftLeft => "(P.toInt({$current}) << P.toInt({$operand}))",
            $e instanceof Expr\AssignOp\ShiftRight => "(P.toInt({$current}) >> P.toInt({$operand}))",
            default => throw $this->context->error('Unsupported assignment operator', $e),
        };

        $write = $this->write($root, $path, $value, $valueUsed);

        return $prefix === '' ? $write : "({$prefix}{$write})";
    }

    public function increment(Expr\PreInc|Expr\PostInc|Expr\PreDec|Expr\PostDec $e, bool $valueUsed): string
    {
        [$root, $path] = $this->target($e->var);
        [$root, $path, $prefix] = $this->stablePath($root, $path);
        $current = $path === [] ? $root : "P.getIn({$root}, [".implode(', ', $path).'])';
        $fn = $e instanceof Expr\PreInc || $e instanceof Expr\PostInc ? 'P.inc' : 'P.dec';
        $post = $e instanceof Expr\PostInc || $e instanceof Expr\PostDec;

        if (! $valueUsed) {
            $write = $this->write($root, $path, "{$fn}({$current})", false);

            return $prefix === '' ? $write : "({$prefix}{$write})";
        }

        $old = $this->context->temp();

        if ($post) {
            return "({$prefix}{$old} = {$current}, ".$this->write($root, $path, "{$fn}({$old})", false).", {$old})";
        }

        return "({$prefix}{$old} = {$fn}({$current}), ".$this->write($root, $path, $old, false).", {$old})";
    }

    /**
     * [$a, $b] = $value, list($a, , $c) = ..., ['x' => $x] = ..., [[$a, $b], $c] = ...: as PHP
     * does it, the value first (so [$a, $b] = [$b, $a] swaps), then each target in order. Its
     * value is the value assigned.
     */
    private function destructure(Expr\List_|Expr\Array_ $var, string $value, bool $valueUsed): string
    {
        $source = $this->context->temp();
        $parts = ["{$source} = {$value}", ...$this->destructureInto($var, $source)];

        if ($valueUsed) {
            $parts[] = $source;
        }

        return '('.implode(', ', $parts).')';
    }

    /** @return list<string> the writes, in order */
    private function destructureInto(Expr\List_|Expr\Array_ $var, string $source): array
    {
        $writes = [];
        $position = 0;

        foreach ($var->items as $item) {
            /** list($a, , $c): a skipped position */
            if ($item === null) {
                $position++;

                continue;
            }

            if ($item->byRef) {
                throw $this->context->error('References are not supported in the browser', $item);
            }

            if ($item->unpack) {
                throw $this->context->error('Spread (...) cannot be used when destructuring', $item);
            }

            $element = 'P.listItem('.$source.', '.($item->key === null ? (string) $position++ : $this->transpiler->expr($item->key)).')';

            if ($item->value instanceof Expr\List_ || $item->value instanceof Expr\Array_) {
                $nested = $this->context->temp();
                $writes[] = "{$nested} = {$element}";
                array_push($writes, ...$this->destructureInto($item->value, $nested));
            } else {
                $writes[] = $this->assign($item->value, $element, false);
            }
        }

        return $writes;
    }

    private function write(string $root, array $path, string $value, bool $valueUsed): string
    {
        if ($path === []) {
            return $valueUsed ? "({$root} = {$value})" : "{$root} = {$value}";
        }

        if (! $valueUsed) {
            return "{$root} = P.setIn({$root}, [".implode(', ', $path)."], {$value})";
        }

        $t = $this->context->temp();

        return "({$root} = P.setIn({$root}, [".implode(', ', $path)."], {$t} = {$value}), {$t})";
    }

    /**
     * Keys read and then written ($a[f()] += 1) are evaluated once, into temporaries.
     *
     * @return array{0: string, 1: list<string>, 2: string}
     */
    private function stablePath(string $root, array $path): array
    {
        $prefix = '';

        foreach ($path as $i => $key) {
            if ($key === 'null') {
                throw new CompileException('[] cannot be used with a compound assignment', $this->context->scope->file);
            }

            if (! preg_match('/^(\$\w+|-?\d+(\.\d+)?|"(?:[^"\\\\]|\\\\.)*")$/', $key)) {
                $t = $this->context->temp();
                $prefix .= "{$t} = {$key}, ";
                $path[$i] = $t;
            }
        }

        return [$root, $path, $prefix];
    }
}
