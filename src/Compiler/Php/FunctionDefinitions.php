<?php

namespace Nitro\Fusion\Compiler\Php;

use Nitro\Fusion\Support\Types;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;

/**
 * Functions: a component method, a closure and an arrow function, as JavaScript functions.
 * General PHP, kept faithful:
 *
 *  - a function has its own variables, declared at its top (`let $a, $b;`), with its temporaries
 *  - declared scalar parameter types coerce the argument as PHP does in coercive typing mode
 *    (P.param()), or reject it with PHP's TypeError
 *  - a closure captures the variables of its `use` by value; an arrow function captures by value
 *    the outer variables it uses; neither sees the others
 *  - by-reference parameters and captures have no equivalent and fail to compile
 *
 * The one Fusion detail: a component method starts with `const $this = this;`, the component
 * (Transpiler::method()); and a closure in a template resolves the template's variables (the
 * component's properties) where it uses them.
 *
 * @internal
 */
final class FunctionDefinitions extends Part
{
    /**
     * @param  list<Param>  $params
     * @param  list<Stmt>  $stmts
     */
    public function functionLike(array $params, array $stmts, string $name, bool $isMethod = false): string
    {
        $context = $this->context;
        [$savedLocals, $savedTemps, $savedLoops, $savedApis] = [$context->locals, $context->temps, $context->loops, $context->browserVariables];
        $context->locals = [];
        $context->browserVariables = [];
        $context->temps = [];
        $context->loops = [];

        $signature = [];
        $coercions = '';

        foreach ($params as $param) {
            $signature[] = $this->param($param);
            $coercions .= $this->coercion($param, $name);
            $context->locals[$param->var->name] = true;
        }

        $paramNames = $context->locals;

        foreach ($this->variablesIn($stmts) as $variable) {
            $context->locals[$variable] = true;
        }

        $body = $this->transpiler->stmts($stmts);
        $declared = array_diff_key($context->locals, $paramNames, ['this' => true]);

        $head = $isMethod ? "const \$this = this;\n" : '';

        if ($declared !== []) {
            $head .= 'let '.implode(', ', array_map(fn ($variable) => '$'.$variable, array_keys($declared))).";\n";
        }

        $head .= $context->declareTemps().$coercions;

        [$context->locals, $context->temps, $context->loops, $context->browserVariables] = [$savedLocals, $savedTemps, $savedLoops, $savedApis];

        return 'function ('.implode(', ', $signature).") {\n{$head}{$body}}";
    }

    public function param(Param $param): string
    {
        if ($param->byRef) {
            throw $this->context->error('By-reference parameters are not supported in the browser', $param);
        }

        if (! $param->var instanceof Expr\Variable || ! is_string($param->var->name)) {
            throw $this->context->error('Unsupported parameter', $param);
        }

        $name = '$'.$param->var->name;

        if ($param->variadic) {
            return '...'.$name;
        }

        return $param->default === null ? $name : $name.' = '.$this->transpiler->expr($param->default);
    }

    /** Coercive typing: a declared scalar type converts (or rejects) the argument, as PHP does. */
    public function coercion(Param $param, string $function): string
    {
        $type = $param->type;
        $nullable = false;

        if ($type instanceof NullableType) {
            $nullable = true;
            $type = $type->type;
        }

        if (! $type instanceof Identifier || ! in_array($type->toLowerString(), Types::SCALARS, true) || $param->variadic) {
            return '';
        }

        if ($param->default instanceof Expr\ConstFetch && $param->default->name->toLowerString() === 'null') {
            $nullable = true;
        }

        $name = $param->var->name;

        return sprintf("\$%s = P.param(\$%s, %s, %s, %s);\n", $name, $name, $this->context->string($type->toLowerString()), $nullable ? 'true' : 'false', $this->context->string($function.'(): Argument $'.$name));
    }

    /**
     * Variables a function body uses, not descending into closures (they have their own scope)
     * except for the variables a closure captures with `use`.
     *
     * @param  array<Node>  $nodes
     * @return list<string>
     */
    public function variablesIn(array $nodes): array
    {
        $found = [];

        $walk = function ($node) use (&$walk, &$found) {
            if (is_array($node)) {
                foreach ($node as $item) {
                    $walk($item);
                }

                return;
            }

            if (! $node instanceof Node) {
                return;
            }

            if ($node instanceof Expr\Closure) {
                foreach ($node->uses as $use) {
                    $found[$use->var->name] = true;
                }

                return;
            }

            if ($node instanceof Expr\ArrowFunction) {
                return;
            }

            if ($node instanceof Expr\Variable && is_string($node->name) && $node->name !== 'this') {
                $found[$node->name] = true;
            }

            foreach ($node->getSubNodeNames() as $sub) {
                $walk($node->$sub);
            }
        };

        $walk($nodes);

        return array_keys($found);
    }

    public function closure(Expr\Closure $expression): string
    {
        $captured = [];

        foreach ($expression->uses as $use) {
            if ($use->byRef) {
                throw $this->context->error('Closures capturing by reference (use (&$x)) are not supported in the browser', $expression);
            }

            $captured[] = '$'.$use->var->name;
        }

        $outerLocals = $this->context->locals;
        $function = $this->functionWithCaptures($expression->params, $expression->stmts, array_map(fn ($context) => substr($context, 1), $captured));
        $this->context->locals = $outerLocals;

        if ($captured === []) {
            return "({$function})";
        }

        $list = implode(', ', $captured);

        return "(({$list}) => {$function})({$list})";
    }

    public function arrowFunction(Expr\ArrowFunction $expression): string
    {
        /** Arrow functions capture the outer variables they use, by value. */
        $params = array_map(fn (Param $parameter) => $parameter->var->name, $expression->params);
        $used = array_diff($this->variablesIn([$expression->expr]), $params);
        $captured = array_values(array_filter($used, fn ($variable) => isset($this->context->locals[$variable])));

        $outerLocals = $this->context->locals;
        $function = $this->functionWithCaptures($expression->params, [new Stmt\Return_($expression->expr)], $captured);
        $this->context->locals = $outerLocals;

        if ($captured === []) {
            return "({$function})";
        }

        $list = implode(', ', array_map(fn ($context) => '$'.$context, $captured));

        return "(({$list}) => {$function})({$list})";
    }

    /**
     * A closure body: its own locals plus the captured variables (parameters of the capturing
     * wrapper), keeping the template's variable resolution.
     *
     * @param  list<Param>  $params
     * @param  list<Stmt>  $stmts
     * @param  list<string>  $captured
     */
    private function functionWithCaptures(array $params, array $stmts, array $captured): string
    {
        $context = $this->context;
        [$savedLocals, $savedTemps, $savedLoops, $savedApis] = [$context->locals, $context->temps, $context->loops, $context->browserVariables];
        $context->locals = array_fill_keys($captured, true);
        /** A captured variable keeps its Fusion::browser() API. */
        $context->browserVariables = array_intersect_key($context->browserVariables, $context->locals);
        $context->temps = [];
        $context->loops = [];

        $signature = [];
        $coercions = '';

        foreach ($params as $param) {
            $signature[] = $this->param($param);
            $coercions .= $this->coercion($param, '{closure}');
            $context->locals[$param->var->name] = true;
        }

        $own = $context->locals;

        foreach ($this->variablesIn($stmts) as $variable) {
            if (! isset($own[$variable]) && $context->scope->variable !== null && ($context->scope->variable)($variable) !== null) {
                continue; // a template variable (component property), resolved when used
            }

            $context->locals[$variable] = true;
        }

        $body = $this->transpiler->stmts($stmts);
        $declared = array_diff_key($context->locals, $own, ['this' => true]);
        $head = ($declared === [] ? '' : 'let '.implode(', ', array_map(fn ($variable) => '$'.$variable, array_keys($declared))).";\n")
            .$context->declareTemps().$coercions;

        [$context->locals, $context->temps, $context->loops, $context->browserVariables] = [$savedLocals, $savedTemps, $savedLoops, $savedApis];

        return 'function ('.implode(', ', $signature).") {\n{$head}{$body}}";
    }
}
