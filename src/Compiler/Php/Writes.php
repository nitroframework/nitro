<?php

namespace Nitro\Compiler\Php;

use Nitro\Compiler\CompileException;
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
 *  - a write's target is a root and a key path (target()); P.APPEND in the path appends ([])
 *  - keys read and then written ($a[f()] += 1) are evaluated once, into temporaries
 *  - destructuring takes the value first (so [$a, $b] = [$b, $a] swaps), then assigns in order
 *  - an assignment used as a value ($x = $y = 1) keeps the assigned value in a temporary
 *
 * The Nitro details, in target():
 *
 *  - $this->prop writes the component's state ($this.prop, by the browser's name), and only a
 *    public property: a #[Computed] one is worked out, a #[Locked] one only the server changes
 *  - a template cannot assign to variables (it renders; methods change state)
 *  - $x = Nitro::browser()->...: the variable keeps that browser API's class, for the calls on it
 *
 * @internal
 */
final class Writes extends Part
{
    /** JavaScript with no effects and nothing to evaluate: a variable, a number, a string (read later, it is the same). */
    private const SIMPLE = '/^(\$\w+|-?\d+(\.\d+)?|"(?:[^"\\\\]|\\\\.)*")$/';

    /** $x = ...: an assignment; a variable given a Nitro::browser() API keeps its class, for the calls on it. */
    public function assignment(Expr\Assign $expression, bool $valueUsed): string
    {
        if ($expression->var instanceof Expr\Variable && is_string($expression->var->name)) {
            $api = $this->transpiler->component->browserApi($expression->expr);

            if ($api !== null) {
                $this->context->browserVariables[$expression->var->name] = $api;
            } else {
                unset($this->context->browserVariables[$expression->var->name]);
            }
        }

        return $this->assign($expression->var, $this->transpiler->expr($expression->expr), $valueUsed);
    }

    /**
     * What an assignment writes to: a root (`$x` or `$this.prop`) and the key path under it
     * (JS key expressions; P.APPEND appends).
     *
     * @return array{0: string, 1: list<string>}
     */
    public function target(Expr $expression): array
    {
        $context = $this->context;

        if ($expression instanceof Expr\Variable) {
            if (! is_string($expression->name) || $expression->name === 'this') {
                throw $context->error('Cannot assign to this variable in the browser', $expression);
            }

            if ($context->scope->variable !== null && ! isset($context->locals[$expression->name])) {
                throw $context->error('A template cannot assign to variables', $expression);
            }

            $context->locals[$expression->name] = true;

            return ['$'.$expression->name, []];
        }

        if ($expression instanceof Expr\PropertyFetch && $context->isThis($expression->var) && $expression->name instanceof Identifier) {
            $name = $expression->name->toString();

            if ($context->scope->isStore($name)) {
                throw $context->error("\$this->{$name} is a store, shared with other components: change it through its methods (\$this->{$name}->add(...))", $expression);
            }

            if ($context->scope->isComputed($name)) {
                throw $context->error("\$this->{$name} is #[Computed]: it is worked out from the state, change the properties it reads instead", $expression);
            }

            if (! $context->scope->isProperty($name)) {
                throw $context->error("\$this->{$name} is not a public property of the component", $expression);
            }

            if ($context->scope->isLocked($name)) {
                throw $context->error("\$this->{$name} is #[Locked]: only the server (mount() or a #[Server] method) can change it", $expression);
            }

            return ['$this.'.$context->scope->browserProperty($name), []];
        }

        if ($expression instanceof Expr\ArrayDimFetch) {
            [$root, $path] = $this->target($expression->var);
            $path[] = $expression->dim === null ? 'P.APPEND' : $this->transpiler->expr($expression->dim);

            /** $a[$a] = ...: PHP reads the key after null or false became an array, so the key is that array. */
            if ($expression->var instanceof Expr\Variable && $expression->dim instanceof Expr\Variable && $expression->var->name === $expression->dim->name && count($path) === 1) {
                $path = ["P.ownKey({$root})"];
            }

            return [$root, $path];
        }

        if ($expression instanceof Expr\PropertyFetch && $expression->name instanceof Identifier) {
            [$root, $path] = $this->target($expression->var);

            if (! str_starts_with($root, '$this.')) {
                throw $context->error('Assigning to a property of a value only works through a component property ($this->post->title = ...): values are copies in the browser', $expression);
            }

            $path[] = $context->string($expression->name->toString());

            return [$root, $path];
        }

        throw $context->error('Cannot assign to this expression in the browser', $expression);
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

        $temporary = $this->context->temp();

        return "({$root} = P.setIn({$root}, [".implode(', ', $path)."], {$temporary} = {$value}), {$temporary})";
    }

    public function assignOp(Expr\AssignOp $expression, bool $valueUsed): string
    {
        [$root, $path] = $this->target($expression->var);
        [$root, $path, $prefix] = $this->stablePath($root, $path);
        /** ??= reads as isset() does (a missing key or offset gives the default); the others, after PHP's checks of where they write. */
        $read = $expression instanceof Expr\AssignOp\Coalesce ? 'P.peek' : 'P.getForUpdate';
        $current = $path === [] ? $root : "{$read}({$root}, [".implode(', ', $path).'])';
        $operand = $this->transpiler->expr($expression->expr);

        /** ??= writes only where the value is missing: a value that is there is left as it is (a string's byte too). */
        if ($expression instanceof Expr\AssignOp\Coalesce) {
            $temporary = $this->context->temp();

            return "({$prefix}{$temporary} = {$current}, {$temporary} !== null && {$temporary} !== undefined ? {$temporary} : ".$this->write($root, $path, $operand, true).')';
        }

        /**
         * PHP evaluates the right side first, then reads where it writes ($a = 1; $a += ($a = 5) is
         * 10) and checks it (a string offset, a scalar).
         */
        if (! preg_match(self::SIMPLE, $operand)) {
            $temporary = $this->context->temp();
            $prefix .= "{$temporary} = {$operand}, ";
            $operand = $temporary;
        }

        $value = match (true) {
            $expression instanceof Expr\AssignOp\Plus => "P.add({$current}, {$operand})",
            $expression instanceof Expr\AssignOp\Minus => "P.sub({$current}, {$operand})",
            $expression instanceof Expr\AssignOp\Mul => "P.mul({$current}, {$operand})",
            $expression instanceof Expr\AssignOp\Div => "P.div({$current}, {$operand})",
            $expression instanceof Expr\AssignOp\Mod => "P.mod({$current}, {$operand})",
            $expression instanceof Expr\AssignOp\Pow => "P.pow({$current}, {$operand})",
            $expression instanceof Expr\AssignOp\Concat => "P.concat({$current}, {$operand})",
            $expression instanceof Expr\AssignOp\BitwiseAnd => "(P.toInt({$current}) & P.toInt({$operand}))",
            $expression instanceof Expr\AssignOp\BitwiseOr => "(P.toInt({$current}) | P.toInt({$operand}))",
            $expression instanceof Expr\AssignOp\BitwiseXor => "(P.toInt({$current}) ^ P.toInt({$operand}))",
            $expression instanceof Expr\AssignOp\ShiftLeft => "(P.toInt({$current}) << P.toInt({$operand}))",
            $expression instanceof Expr\AssignOp\ShiftRight => "(P.toInt({$current}) >> P.toInt({$operand}))",
            default => throw $this->context->error('Unsupported assignment operator', $expression),
        };

        $write = $this->write($root, $path, $value, $valueUsed);

        return $prefix === '' ? $write : "({$prefix}{$write})";
    }

    public function increment(Expr\PreInc|Expr\PostInc|Expr\PreDec|Expr\PostDec $expression, bool $valueUsed): string
    {
        [$root, $path] = $this->target($expression->var);
        [$root, $path, $prefix] = $this->stablePath($root, $path);
        $current = $path === [] ? $root : "P.getForUpdate({$root}, [".implode(', ', $path).'], true)';
        $fn = $expression instanceof Expr\PreInc || $expression instanceof Expr\PostInc ? 'P.inc' : 'P.dec';
        $post = $expression instanceof Expr\PostInc || $expression instanceof Expr\PostDec;

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

        $temporary = $this->context->temp();

        return "({$root} = P.setIn({$root}, [".implode(', ', $path)."], {$temporary} = {$value}), {$temporary})";
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
            if ($key === 'P.APPEND') {
                throw new CompileException('[] cannot be used with a compound assignment', $this->context->scope->file);
            }

            if (! preg_match(self::SIMPLE, $key)) {
                $temporary = $this->context->temp();
                $prefix .= "{$temporary} = {$key}, ";
                $path[$i] = $temporary;
            }
        }

        return [$root, $path, $prefix];
    }
}
