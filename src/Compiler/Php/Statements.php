<?php

namespace Nitro\Fusion\Compiler\Php;

use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/**
 * Statements: if / elseif / else, foreach, for, while, do-while, switch, break and continue (with
 * levels), and unset, as JavaScript with PHP's semantics. General PHP, nothing Fusion-specific:
 *
 *  - conditions go through P.bool(), PHP's truthiness ("0" and [] are false)
 *  - foreach iterates a PHP array in its order through P.iter(), over a copy (writes in the loop
 *    do not change what it iterates), and assigns the key and value as PHP does
 *  - switch compares with == (P.eq), as PHP does
 *  - every loop and switch gets a label ($L1, $L2), so `break 2` and `continue 2` reach the right
 *    one; continue inside switch fails to compile (PHP treats it as break, JavaScript would not)
 *  - unset() of a variable makes it undefined; of a key, P.unsetIn() builds the array without it
 *
 * Statements with no faithful equivalent in the browser (echo, try/catch, global, static) are
 * dispatched to errors by Transpiler::stmt().
 *
 * @internal
 */
final class Statements extends Part
{
    public function if(Stmt\If_ $stmt): string
    {
        $out = 'if (P.bool('.$this->transpiler->expr($stmt->cond).")) {\n".$this->transpiler->stmts($stmt->stmts).'}';

        foreach ($stmt->elseifs as $elseif) {
            $out .= ' else if (P.bool('.$this->transpiler->expr($elseif->cond).")) {\n".$this->transpiler->stmts($elseif->stmts).'}';
        }

        if ($stmt->else !== null) {
            $out .= " else {\n".$this->transpiler->stmts($stmt->else->stmts).'}';
        }

        return $out."\n";
    }

    public function foreach(Stmt\Foreach_ $stmt): string
    {
        if ($stmt->byRef) {
            throw $this->context->error('foreach by reference is not supported in the browser: assign to $array[$key] instead', $stmt);
        }

        if (! $stmt->valueVar instanceof Expr\Variable || ! is_string($stmt->valueVar->name)) {
            throw $this->context->error('foreach must assign to a plain variable ($item) in the browser', $stmt);
        }

        if ($stmt->keyVar !== null && (! $stmt->keyVar instanceof Expr\Variable || ! is_string($stmt->keyVar->name))) {
            throw $this->context->error('foreach must assign the key to a plain variable', $stmt);
        }

        $source = $this->transpiler->expr($stmt->expr);

        return $this->loop($stmt, function ($label) use ($stmt, $source) {
            $keyTemporary = $this->context->temp();
            $valueTemporary = $this->context->temp();
            $assign = ($stmt->keyVar !== null ? '$'.$stmt->keyVar->name." = {$keyTemporary}; " : '').'$'.$stmt->valueVar->name." = {$valueTemporary};\n";

            return "{$label}: for (const [{$keyTemporary}_, {$valueTemporary}_] of P.iter({$source})) {\n"
                ."{$keyTemporary} = {$keyTemporary}_; {$valueTemporary} = {$valueTemporary}_; {$assign}".$this->transpiler->stmts($stmt->stmts)."}\n";
        });
    }

    public function for(Stmt\For_ $stmt): string
    {
        $init = implode(', ', array_map(fn ($expression) => $this->transpiler->statementExpr($expression), $stmt->init));
        $cond = $stmt->cond === [] ? 'true' : 'P.bool(('.implode(', ', array_map(fn ($expression) => $this->transpiler->expr($expression), $stmt->cond)).'))';
        $step = implode(', ', array_map(fn ($expression) => $this->transpiler->statementExpr($expression), $stmt->loop));

        return $this->loop($stmt, fn ($label) => "{$label}: for ({$init}; {$cond}; {$step}) {\n".$this->transpiler->stmts($stmt->stmts)."}\n");
    }

    public function while(Stmt\While_ $stmt): string
    {
        return $this->loop($stmt, fn ($label) => "{$label}: while (P.bool(".$this->transpiler->expr($stmt->cond).")) {\n".$this->transpiler->stmts($stmt->stmts)."}\n");
    }

    public function do(Stmt\Do_ $stmt): string
    {
        return $this->loop($stmt, fn ($label) => "{$label}: do {\n".$this->transpiler->stmts($stmt->stmts).'} while (P.bool('.$this->transpiler->expr($stmt->cond)."));\n");
    }

    public function switch(Stmt\Switch_ $stmt): string
    {
        $subject = $this->context->temp();
        $head = "{$subject} = ".$this->transpiler->expr($stmt->cond).";\n";

        return $head.$this->loop($stmt, function ($label) use ($stmt, $subject) {
            $out = "{$label}: switch (true) {\n";

            foreach ($stmt->cases as $case) {
                foreach ($case->stmts as $inner) {
                    if ($inner instanceof Stmt\Continue_ && $inner->num === null) {
                        throw $this->context->error('continue inside switch is not supported in the browser: use break', $inner);
                    }
                }

                $out .= $case->cond === null
                    ? "default:\n"
                    : 'case P.eq('.$subject.', '.$this->transpiler->expr($case->cond)."):\n";
                $out .= $this->transpiler->stmts($case->stmts);
            }

            return $out."}\n";
        });
    }

    /** break / continue, with PHP's levels (break 2): the label of that enclosing loop. */
    public function jump(string $keyword, Stmt\Break_|Stmt\Continue_ $stmt): string
    {
        $levels = 1;

        if ($stmt->num !== null) {
            if (! $stmt->num instanceof Scalar\Int_) {
                throw $this->context->error("{$keyword} needs a constant number of levels", $stmt);
            }

            $levels = $stmt->num->value;
        }

        if ($levels < 1 || $levels > count($this->context->loops)) {
            throw $this->context->error("Cannot '{$keyword} {$levels}': not inside that many loops", $stmt);
        }

        return $keyword.' '.$this->context->loops[count($this->context->loops) - $levels].";\n";
    }

    public function unset(Stmt\Unset_ $stmt): string
    {
        $out = '';

        foreach ($stmt->vars as $var) {
            if ($var instanceof Expr\Variable && is_string($var->name)) {
                $out .= '$'.$var->name." = undefined;\n";

                continue;
            }

            [$root, $path] = $this->transpiler->writes->target($var);

            if ($path === []) {
                throw $this->context->error('A component property cannot be unset; assign null or reset() it', $var);
            }

            $out .= "{$root} = P.unsetIn({$root}, [".implode(', ', $path)."]);\n";
        }

        return $out;
    }

    /** A loop or switch, labelled for break / continue with levels while its body compiles. */
    private function loop(Stmt $stmt, callable $build): string
    {
        $label = '$L'.(++$this->context->labelCounter);
        $this->context->loops[] = $label;

        try {
            return $build($label);
        } finally {
            array_pop($this->context->loops);
        }
    }
}
