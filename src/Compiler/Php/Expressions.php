<?php

namespace Nitro\Compiler\Php;

use Nitro\Compiler\BrowserClasses;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use ReflectionClass;
use ReflectionClassConstant;
use UnitEnum;

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
 * The Nitro details:
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
                ? '['.implode(', ', array_map(fn ($item) => $this->literal($item, $node), $value)).']'
                : 'P.arrayLiteral(['.implode(', ', array_map(fn ($itemKey, $item) => '['.$this->literal($itemKey, $node).', '.$this->literal($item, $node).']', array_keys($value), $value)).'])',
            $value instanceof UnitEnum => $this->enumCase($value, $node),
            default => throw $this->context->error(sprintf('A constant of type %s cannot be used in the browser', get_debug_type($value)), $node),
        };
    }

    /** Status::Paid: the case, one object per case in the browser (Nitro.classes[enum].$cases). */
    public function enumCase(UnitEnum $case, Node $node): string
    {
        $enum = $case::class;

        if (! BrowserClasses::isEnum($enum)) {
            throw $this->context->error("{$enum}::{$case->name} is a case of {$enum}: mark {$enum} #[Browser] to use its cases in the browser", $node);
        }

        $this->context->uses['classes'][BrowserClasses::definition($enum)] = true;

        return 'Nitro.classes['.json_encode($this->context->scope->browserClass($enum), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).'].$cases['.json_encode($case->name, JSON_THROW_ON_ERROR).']';
    }

    public function interpolated(Scalar\InterpolatedString $expression): string
    {
        $parts = [];

        foreach ($expression->parts as $part) {
            $parts[] = $part instanceof Node\InterpolatedStringPart ? $this->context->string($part->value) : $this->transpiler->expr($part);
        }

        return 'P.concat('.implode(', ', $parts).')';
    }

    public function constant(Expr\ConstFetch $expression): string
    {
        $name = $expression->name->toString();

        return match (strtolower($name)) {
            'true' => 'true',
            'false' => 'false',
            'null' => 'null',
            default => array_key_exists(ltrim($name, '\\'), Catalog::CONSTANTS)
                ? $this->literal(Catalog::CONSTANTS[ltrim($name, '\\')], $expression)
                : throw $this->context->error("The constant {$name} is not available in the browser", $expression),
        };
    }

    /** self::CONSTANT / static::CONSTANT: the component's constant, as its value. */
    public function classConstant(Expr\ClassConstFetch $expression): string
    {
        $class = $expression->class instanceof Name ? ltrim(($expression->class->getAttribute('resolvedName') ?? $expression->class)->toString(), '\\') : null;

        /** A #[Browser] class's constant (Money::CURRENCY): its value. An enum's case (Status::Paid): the case. */
        if ($class !== null && $expression->name instanceof Identifier && ! in_array(strtolower($class), ['self', 'static'], true) && (BrowserClasses::is($class) || BrowserClasses::isEnum($class))) {
            $name = $expression->name->toString();

            if (! (new ReflectionClass($class))->hasConstant($name)) {
                throw $this->context->error("Undefined constant {$class}::{$name}", $expression);
            }

            if (! (new ReflectionClassConstant($class, $name))->isPublic() && $this->context->class !== $class) {
                throw $this->context->error("{$class}::{$name} is not public", $expression);
            }

            return $this->literal(constant($class.'::'.$name), $expression);
        }

        if (! $expression->class instanceof Name || ! in_array($expression->class->toLowerString(), ['self', 'static'], true) || ! $expression->name instanceof Identifier) {
            throw $this->context->error($class !== null && class_exists($class)
                ? "{$class}'s ".(enum_exists($class) ? 'cases' : 'constants')." are not in the browser: mark {$class} #[Browser], or move this code to a #[Server] method"
                : 'Only self::CONSTANT of the component and the constants of #[Browser] classes can be used in the browser', $expression);
        }

        $name = $expression->name->toString();

        if (! array_key_exists($name, $this->context->scope->constants)) {
            throw $this->context->error("Undefined constant self::{$name}", $expression);
        }

        return $this->literal($this->context->scope->constants[$name], $expression);
    }

    /** $name: a local keeps its $ ($total -> $total); in a template, what the template resolves it to. */
    public function variable(Expr\Variable $expression): string
    {
        $context = $this->context;

        if (! is_string($expression->name)) {
            throw $context->error('Variable variables ($$name) are not supported in the browser', $expression);
        }

        if ($expression->name === 'this') {
            /** In an enum's method, $this is the case: a value like any other (match ($this) {...}). */
            if ($context->enumCase) {
                return '$this';
            }

            throw $context->error('$this can only be used as $this->property or $this->method() in the browser', $expression);
        }

        if (isset($context->locals[$expression->name])) {
            return '$'.$expression->name;
        }

        if ($context->scope->variable !== null && ($resolved = ($context->scope->variable)($expression->name)) !== null) {
            return $resolved;
        }

        if ($context->scope->variable !== null) {
            throw $context->error("Undefined variable \${$expression->name}: a template can use the component's public properties and its own loop variables", $expression);
        }

        return '$'.$expression->name;
    }

    public function array(Expr\Array_ $expression): string
    {
        $simpleList = true;

        foreach ($expression->items as $item) {
            if ($item === null) {
                throw $this->context->error('Empty array items are not supported', $expression);
            }

            if ($item->byRef) {
                throw $this->context->error('References in arrays are not supported in the browser', $item);
            }

            if ($item->key !== null || $item->unpack) {
                $simpleList = false;
            }
        }

        if ($simpleList) {
            return '['.implode(', ', array_map(fn ($item) => $this->transpiler->expr($item->value), $expression->items)).']';
        }

        $items = array_map(fn ($item) => '['.($item->key === null ? 'P.APPEND' : $this->transpiler->expr($item->key)).', '.$this->transpiler->expr($item->value).', '.($item->unpack ? 'true' : 'false').']', $expression->items);

        return 'P.arrayLiteral(['.implode(', ', $items).'])';
    }

    public function dimFetch(Expr\ArrayDimFetch $expression): string
    {
        if ($expression->dim === null) {
            throw $this->context->error('Cannot use [] for reading', $expression);
        }

        return 'P.get('.$this->transpiler->expr($expression->var).', '.$this->transpiler->expr($expression->dim).')';
    }

    public function binary(Expr\BinaryOp $expression): string
    {
        $left = fn () => $this->transpiler->expr($expression->left);
        $right = fn () => $this->transpiler->expr($expression->right);

        return match (true) {
            $expression instanceof Expr\BinaryOp\Plus => 'P.add('.$left().', '.$right().')',
            $expression instanceof Expr\BinaryOp\Minus => 'P.sub('.$left().', '.$right().')',
            $expression instanceof Expr\BinaryOp\Mul => 'P.mul('.$left().', '.$right().')',
            $expression instanceof Expr\BinaryOp\Div => 'P.div('.$left().', '.$right().')',
            $expression instanceof Expr\BinaryOp\Mod => 'P.mod('.$left().', '.$right().')',
            $expression instanceof Expr\BinaryOp\Pow => 'P.pow('.$left().', '.$right().')',
            $expression instanceof Expr\BinaryOp\Concat => 'P.concat('.$left().', '.$right().')',
            $expression instanceof Expr\BinaryOp\Equal => 'P.eq('.$left().', '.$right().')',
            $expression instanceof Expr\BinaryOp\NotEqual => 'P.neq('.$left().', '.$right().')',
            $expression instanceof Expr\BinaryOp\Identical => 'P.identical('.$left().', '.$right().')',
            $expression instanceof Expr\BinaryOp\NotIdentical => 'P.notIdentical('.$left().', '.$right().')',
            $expression instanceof Expr\BinaryOp\Smaller => 'P.lt('.$left().', '.$right().')',
            $expression instanceof Expr\BinaryOp\SmallerOrEqual => 'P.lte('.$left().', '.$right().')',
            $expression instanceof Expr\BinaryOp\Greater => 'P.gt('.$left().', '.$right().')',
            $expression instanceof Expr\BinaryOp\GreaterOrEqual => 'P.gte('.$left().', '.$right().')',
            $expression instanceof Expr\BinaryOp\Spaceship => 'P.cmp('.$left().', '.$right().')',
            $expression instanceof Expr\BinaryOp\BooleanAnd, $expression instanceof Expr\BinaryOp\LogicalAnd => '(P.bool('.$left().') && P.bool('.$right().'))',
            $expression instanceof Expr\BinaryOp\BooleanOr, $expression instanceof Expr\BinaryOp\LogicalOr => '(P.bool('.$left().') || P.bool('.$right().'))',
            $expression instanceof Expr\BinaryOp\LogicalXor => '(P.bool('.$left().') !== P.bool('.$right().'))',
            $expression instanceof Expr\BinaryOp\Coalesce => 'P.coalesce('.($this->isKeyRead($expression->left) ? $this->existence('P.peek', $expression->left) : $left()).', () => '.$right().')',
            $expression instanceof Expr\BinaryOp\BitwiseAnd => '(P.toInt('.$left().') & P.toInt('.$right().'))',
            $expression instanceof Expr\BinaryOp\BitwiseOr => '(P.toInt('.$left().') | P.toInt('.$right().'))',
            $expression instanceof Expr\BinaryOp\BitwiseXor => '(P.toInt('.$left().') ^ P.toInt('.$right().'))',
            $expression instanceof Expr\BinaryOp\ShiftLeft => '(P.toInt('.$left().') << P.toInt('.$right().'))',
            $expression instanceof Expr\BinaryOp\ShiftRight => '(P.toInt('.$left().') >> P.toInt('.$right().'))',
            default => throw $this->context->error('Unsupported operator', $expression),
        };
    }

    public function ternary(Expr\Ternary $expression): string
    {
        if ($expression->if === null) {
            $temporary = $this->context->temp();

            return "(P.bool({$temporary} = ".$this->transpiler->expr($expression->cond).") ? {$temporary} : ".$this->transpiler->expr($expression->else).')';
        }

        return '(P.bool('.$this->transpiler->expr($expression->cond).') ? '.$this->transpiler->expr($expression->if).' : '.$this->transpiler->expr($expression->else).')';
    }

    public function cast(Expr\Cast $expression): string
    {
        $inner = $this->transpiler->expr($expression->expr);

        return match (true) {
            $expression instanceof Expr\Cast\Int_ => "P.toInt({$inner})",
            $expression instanceof Expr\Cast\Double => "P.toFloat({$inner})",
            $expression instanceof Expr\Cast\String_ => "P.toStr({$inner})",
            $expression instanceof Expr\Cast\Bool_ => "P.bool({$inner})",
            $expression instanceof Expr\Cast\Array_ => "P.toArr({$inner})",
            default => throw $this->context->error('This cast is not supported in the browser', $expression),
        };
    }

    /**
     * isset($a['x']['y'], $this->prop['k']): a base and a key path, checked without reading
     * missing keys.
     */
    public function isset(Expr\Isset_ $expression): string
    {
        return '('.implode(' && ', array_map(fn ($var) => $this->existence('P.isset', $var), $expression->vars)).')';
    }

    public function empty(Expr\Empty_ $expression): string
    {
        return $this->existence('P.empty', $expression->expr);
    }

    public function match(Expr\Match_ $expression): string
    {
        $subject = $this->context->temp();
        $out = "(({$subject}) => {\n";
        $default = null;

        foreach ($expression->arms as $arm) {
            if ($arm->conds === null) {
                $default = $this->transpiler->expr($arm->body);

                continue;
            }

            $conds = array_map(fn ($context) => "P.identical({$subject}, ".$this->transpiler->expr($context).')', $arm->conds);
            $out .= 'if ('.implode(' || ', $conds).') return '.$this->transpiler->expr($arm->body).";\n";
        }

        $out .= $default !== null
            ? "return {$default};\n"
            : "throw P.unhandledMatch({$subject});\n";

        return $out.'})('.$this->transpiler->expr($expression->cond).')';
    }

    /** $a['k'] or $post->title: read as isset() reads it by ?? (a missing key or offset gives the default). */
    private function isKeyRead(Expr $expression): bool
    {
        return ($expression instanceof Expr\ArrayDimFetch && $expression->dim !== null)
            || (($expression instanceof Expr\PropertyFetch || $expression instanceof Expr\NullsafePropertyFetch) && $expression->name instanceof Identifier && ! $this->context->isThis($expression->var));
    }

    private function existence(string $fn, Expr $expression): string
    {
        $path = [];

        while (true) {
            if ($expression instanceof Expr\ArrayDimFetch && $expression->dim !== null) {
                array_unshift($path, $this->transpiler->expr($expression->dim));
                $expression = $expression->var;
            } elseif (($expression instanceof Expr\PropertyFetch || $expression instanceof Expr\NullsafePropertyFetch)
                && $expression->name instanceof Identifier && ! $this->context->isThis($expression->var)) {
                array_unshift($path, $this->context->string($expression->name->toString()));
                $expression = $expression->var;
            } else {
                break;
            }
        }

        $base = $expression instanceof Expr\Variable && is_string($expression->name) && ! isset($this->context->locals[$expression->name]) && $this->context->scope->variable === null
            ? '$'.$expression->name
            : $this->transpiler->expr($expression);

        return "{$fn}({$base}, [".implode(', ', $path).'])';
    }
}
