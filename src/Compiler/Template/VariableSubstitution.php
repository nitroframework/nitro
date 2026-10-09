<?php

namespace Nitro\Compiler\Template;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * The variables of an inlined view (BladeInliner) replaced by what they were given: in an
 * include such as @include('row', ['cake' => $item]), $cake in row.blade.php becomes $item. A closure's or arrow
 * function's own parameters (and a closure's use()) are left alone, and so is what a replacement
 * brings (it is already read where the view is included).
 */
final class VariableSubstitution extends NodeVisitorAbstract
{
    /** @var list<array<string, true>> the names closures around the node bind */
    private array $bound = [];

    /** @param  Closure(string): ?Expr  $replacement  a variable's replacement, or null to keep it */
    private function __construct(private readonly Closure $replacement)
    {
    }

    /**
     * @param  list<Node\Stmt>  $stmts
     * @param  Closure(string): ?Expr  $replacement
     * @return list<Node\Stmt>
     */
    public static function apply(array $stmts, Closure $replacement): array
    {
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new self($replacement));

        /** @var list<Node\Stmt> */
        return $traverser->traverse($stmts);
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Expr\ArrowFunction || $node instanceof Expr\Closure) {
            $names = [];

            foreach ($node->params as $param) {
                if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                    $names[$param->var->name] = true;
                }
            }

            foreach ($node instanceof Expr\Closure ? $node->uses : [] as $use) {
                if (is_string($use->var->name)) {
                    $names[$use->var->name] = true;
                }
            }

            $this->bound[] = $names;
        }

        /** A closure's use($x) and parameters stay variables (no expression can stand there). */
        if ($node instanceof Node\ClosureUse || $node instanceof Node\Param) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($node instanceof Expr\ArrowFunction || $node instanceof Expr\Closure) {
            array_pop($this->bound);

            return null;
        }

        if (! $node instanceof Expr\Variable || ! is_string($node->name)) {
            return null;
        }

        foreach ($this->bound as $names) {
            if (isset($names[$node->name])) {
                return null;
            }
        }

        $replacement = ($this->replacement)($node->name);

        if ($replacement === null) {
            return null;
        }

        /** A copy (the tree keeps its precedence: no parentheses needed), at the variable's line. */
        $statement = (new NodeTraverser(new CloningVisitor))->traverse([new Node\Stmt\Expression($replacement)])[0];
        assert($statement instanceof Node\Stmt\Expression);
        $copy = $statement->expr;
        $copy->setAttribute('startLine', $node->getStartLine());

        return $copy;
    }
}
