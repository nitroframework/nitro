<?php

namespace Nitro\Fusion\Compiler\Php;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

/**
 * Expressions that read values: literals, strings with "{$interpolation}", constants, variables,
 * arrays, $array['key'], operators, the ternary, casts, isset() / empty() and match. Mostly
 * general PHP, through js/src/php.js (P) so the results are PHP's:
 *
 *  - arithmetic, comparison and concatenation go through P (P.add, P.eq, P.concat, ...): PHP's
 *    loose comparison, numeric strings, int/float behaviour and string conversion
 *  - a PHP array is a value (a list as a JS array, a map through P.arrayLiteral); reading a key
 *    goes through P.get, with PHP's key rules ("1" and 1 are the same key); a missing key, or a
 *    read from null, is null
 *  - isset() and empty() walk the key path without reading missing keys, as PHP does
 *  - match compares with ===, and an unmatched value throws PHP's UnhandledMatchError
 *  - constants have the server's values (Catalog::CONSTANTS): the page is rendered on both
 *
 * The Fusion details:
 *
 *  - a variable in a template is resolved by the template (Scope::$variable): the component's
 *    public properties and the template's own loop variables; anything else fails to compile
 *  - self::CONSTANT reads the component's constants (Scope::$constants)
 *
 * @internal
 */
final class Expressions extends Part
{
    /** A PHP value known at compile time (constants) as a JS expression. */
    public function literal(mixed $value, Node $node): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => $this->context->float($value),
            is_string($value) => $this->context->string($value),
            is_array($value) => array_is_list($value)
                ? '['.implode(', ', array_map(fn ($v) => $this->literal($v, $node), $value)).']'
                : 'P.arrayLiteral(['.implode(', ', array_map(fn ($k, $v) => '['.$this->literal($k, $node).', '.$this->literal($v, $node).']', array_keys($value), $value)).'])',
            default => throw $this->context->error(sprintf('A constant of type %s cannot be used in the browser', get_debug_type($value)), $node),
        };
    }

    public function interpolated(Scalar\InterpolatedString $e): string
    {
        $parts = [];

        foreach ($e->parts as $part) {
            $parts[] = $part instanceof Node\InterpolatedStringPart ? $this->context->string($part->value) : $this->transpiler->expr($part);
        }

        return 'P.concat('.implode(', ', $parts).')';
    }

    public function constant(Expr\ConstFetch $e): string
    {
        $name = $e->name->toString();

        return match (strtolower($name)) {
            'true' => 'true',
            'false' => 'false',
            'null' => 'null',
            default => array_key_exists(ltrim($name, '\\'), Catalog::CONSTANTS)
                ? $this->literal(Catalog::CONSTANTS[ltrim($name, '\\')], $e)
                : throw $this->context->error("The constant {$name} is not available in the browser", $e),
        };
    }

    /** self::CONSTANT / static::CONSTANT: the component's constant, as its value. */
    public function classConstant(Expr\ClassConstFetch $e): string
    {
        if (! $e->class instanceof Name || ! in_array($e->class->toLowerString(), ['self', 'static'], true) || ! $e->name instanceof Identifier) {
            throw $this->context->error('Only self::CONSTANT and static::CONSTANT of the component can be used in the browser', $e);
        }

        $name = $e->name->toString();

        if (! array_key_exists($name, $this->context->scope->constants)) {
            throw $this->context->error("Undefined constant self::{$name}", $e);
        }

        return $this->literal($this->context->scope->constants[$name], $e);
    }

    /** $name: a local keeps its $ ($total -> $total); in a template, what the template resolves it to. */
    public function variable(Expr\Variable $e): string
    {
        $c = $this->context;

        if (! is_string($e->name)) {
            throw $c->error('Variable variables ($$name) are not supported in the browser', $e);
        }

        if ($e->name === 'this') {
            throw $c->error('$this can only be used as $this->property or $this->method() in the browser', $e);
        }

        if (isset($c->locals[$e->name])) {
            return '$'.$e->name;
        }

        if ($c->scope->variable !== null && ($resolved = ($c->scope->variable)($e->name)) !== null) {
            return $resolved;
        }

        if ($c->scope->variable !== null) {
            throw $c->error("Undefined variable \${$e->name}: a template can use the component's public properties and its own loop variables", $e);
        }

        return '$'.$e->name;
    }

    public function array(Expr\Array_ $e): string
    {
        $simpleList = true;

        foreach ($e->items as $item) {
            if ($item === null) {
                throw $this->context->error('Empty array items are not supported', $e);
            }

            if ($item->byRef) {
                throw $this->context->error('References in arrays are not supported in the browser', $item);
            }

            if ($item->key !== null || $item->unpack) {
                $simpleList = false;
            }
        }

        if ($simpleList) {
            return '['.implode(', ', array_map(fn ($item) => $this->transpiler->expr($item->value), $e->items)).']';
        }

        $items = array_map(fn ($item) => '['.($item->key === null ? 'P.APPEND' : $this->transpiler->expr($item->key)).', '.$this->transpiler->expr($item->value).', '.($item->unpack ? 'true' : 'false').']', $e->items);

        return 'P.arrayLiteral(['.implode(', ', $items).'])';
    }

    public function dimFetch(Expr\ArrayDimFetch $e): string
    {
        if ($e->dim === null) {
            throw $this->context->error('Cannot use [] for reading', $e);
        }

        return 'P.get('.$this->transpiler->expr($e->var).', '.$this->transpiler->expr($e->dim).')';
    }

    public function binary(Expr\BinaryOp $e): string
    {
        $l = fn () => $this->transpiler->expr($e->left);
        $r = fn () => $this->transpiler->expr($e->right);

        return match (true) {
            $e instanceof Expr\BinaryOp\Plus => 'P.add('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Minus => 'P.sub('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Mul => 'P.mul('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Div => 'P.div('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Mod => 'P.mod('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Pow => 'P.pow('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Concat => 'P.concat('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Equal => 'P.eq('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\NotEqual => 'P.neq('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Identical => 'P.identical('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\NotIdentical => 'P.notIdentical('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Smaller => 'P.lt('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\SmallerOrEqual => 'P.lte('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Greater => 'P.gt('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\GreaterOrEqual => 'P.gte('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\Spaceship => 'P.cmp('.$l().', '.$r().')',
            $e instanceof Expr\BinaryOp\BooleanAnd, $e instanceof Expr\BinaryOp\LogicalAnd => '(P.bool('.$l().') && P.bool('.$r().'))',
            $e instanceof Expr\BinaryOp\BooleanOr, $e instanceof Expr\BinaryOp\LogicalOr => '(P.bool('.$l().') || P.bool('.$r().'))',
            $e instanceof Expr\BinaryOp\LogicalXor => '(P.bool('.$l().') !== P.bool('.$r().'))',
            $e instanceof Expr\BinaryOp\Coalesce => 'P.coalesce('.$l().', () => '.$r().')',
            $e instanceof Expr\BinaryOp\BitwiseAnd => '(P.toInt('.$l().') & P.toInt('.$r().'))',
            $e instanceof Expr\BinaryOp\BitwiseOr => '(P.toInt('.$l().') | P.toInt('.$r().'))',
            $e instanceof Expr\BinaryOp\BitwiseXor => '(P.toInt('.$l().') ^ P.toInt('.$r().'))',
            $e instanceof Expr\BinaryOp\ShiftLeft => '(P.toInt('.$l().') << P.toInt('.$r().'))',
            $e instanceof Expr\BinaryOp\ShiftRight => '(P.toInt('.$l().') >> P.toInt('.$r().'))',
            default => throw $this->context->error('Unsupported operator', $e),
        };
    }

    public function ternary(Expr\Ternary $e): string
    {
        if ($e->if === null) {
            $t = $this->context->temp();

            return "(P.bool({$t} = ".$this->transpiler->expr($e->cond).") ? {$t} : ".$this->transpiler->expr($e->else).')';
        }

        return '(P.bool('.$this->transpiler->expr($e->cond).') ? '.$this->transpiler->expr($e->if).' : '.$this->transpiler->expr($e->else).')';
    }

    public function cast(Expr\Cast $e): string
    {
        $inner = $this->transpiler->expr($e->expr);

        return match (true) {
            $e instanceof Expr\Cast\Int_ => "P.toInt({$inner})",
            $e instanceof Expr\Cast\Double => "P.toFloat({$inner})",
            $e instanceof Expr\Cast\String_ => "P.toStr({$inner})",
            $e instanceof Expr\Cast\Bool_ => "P.bool({$inner})",
            $e instanceof Expr\Cast\Array_ => "P.toArr({$inner})",
            default => throw $this->context->error('This cast is not supported in the browser', $e),
        };
    }

    /**
     * isset($a['x']['y'], $this->prop['k']): a base and a key path, checked without reading
     * missing keys.
     */
    public function isset(Expr\Isset_ $e): string
    {
        return '('.implode(' && ', array_map(fn ($var) => $this->existence('P.isset', $var), $e->vars)).')';
    }

    public function empty(Expr\Empty_ $e): string
    {
        return $this->existence('P.empty', $e->expr);
    }

    public function match(Expr\Match_ $e): string
    {
        $subject = $this->context->temp();
        $out = "(({$subject}) => {\n";
        $default = null;

        foreach ($e->arms as $arm) {
            if ($arm->conds === null) {
                $default = $this->transpiler->expr($arm->body);

                continue;
            }

            $conds = array_map(fn ($c) => "P.identical({$subject}, ".$this->transpiler->expr($c).')', $arm->conds);
            $out .= 'if ('.implode(' || ', $conds).') return '.$this->transpiler->expr($arm->body).";\n";
        }

        $out .= $default !== null
            ? "return {$default};\n"
            : "throw new P.PhpError('UnhandledMatchError', 'Unhandled match case ' + P.jsonEncode({$subject}));\n";

        return $out.'})('.$this->transpiler->expr($e->cond).')';
    }

    private function existence(string $fn, Expr $e): string
    {
        $path = [];

        while (true) {
            if ($e instanceof Expr\ArrayDimFetch && $e->dim !== null) {
                array_unshift($path, $this->transpiler->expr($e->dim));
                $e = $e->var;
            } elseif (($e instanceof Expr\PropertyFetch || $e instanceof Expr\NullsafePropertyFetch)
                && $e->name instanceof Identifier && ! $this->context->isThis($e->var)) {
                array_unshift($path, $this->context->string($e->name->toString()));
                $e = $e->var;
            } else {
                break;
            }
        }

        $base = $e instanceof Expr\Variable && is_string($e->name) && ! isset($this->context->locals[$e->name]) && $this->context->scope->variable === null
            ? '$'.$e->name
            : $this->transpiler->expr($e);

        return "{$fn}({$base}, [".implode(', ', $path).'])';
    }
}
