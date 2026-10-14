<?php

namespace Nitro\Compiler\Template;

use Nitro\Compiler\CompileException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard;

/**
 * PHP inside a view's directives and tags, read for inlining (BladeInliner, AttributeBag): a
 * directive's arguments, a literal array, an expression, and printed back as PHP.
 *
 * @internal
 */
final class PhpSource
{
    /** @return array<string, string> a literal array with string keys: key => the value's PHP */
    public static function literalArray(Expr $array, string $what, ?string $file, int $line): array
    {
        if (! $array instanceof Expr\Array_) {
            throw new CompileException("{$what} must be a literal array (['name' => \$value]) so that it can be compiled for the browser", $file, $line);
        }

        $values = [];

        foreach ($array->items as $item) {
            if ($item === null || ! $item->key instanceof Scalar\String_ || $item->unpack) {
                throw new CompileException("{$what} names each value with a string key", $file, $line);
            }

            $values[$item->key->value] = self::print($item->value);
        }

        return $values;
    }

    /** @return list<Expr> the arguments of a directive or call, parsed */
    public static function arguments(string $code, string $what, ?string $file, int $line): array
    {
        $call = self::expression('f('.$code.')', $file, $line);

        if (! $call instanceof Expr\FuncCall) {
            throw new CompileException("Malformed {$what}", $file, $line);
        }

        return array_map(fn ($arg) => $arg->value, $call->getArgs());
    }

    public static function expression(string $code, ?string $file, int $line): Expr
    {
        try {
            $stmts = (new ParserFactory)->createForVersion(PhpVersion::fromComponents(8, 3))->parse('<?php ('.$code.');') ?? [];
        } catch (\PhpParser\Error $exception) {
            throw new CompileException('PHP syntax: '.$exception->getRawMessage(), $file, $line);
        }

        $stmt = $stmts[0] ?? null;

        if (! $stmt instanceof Node\Stmt\Expression) {
            throw new CompileException('Expected an expression', $file, $line);
        }

        return $stmt->expr;
    }

    public static function print(Expr $expr): string
    {
        return (new Standard)->prettyPrintExpr($expr);
    }

    /**
     * The variables PHP code (a statement, without "<?php") reads, $this aside: what a closure
     * running it takes from the scope around it (use).
     *
     * @return list<string>
     */
    public static function variablesIn(string $statement): array
    {
        $stmts = (new ParserFactory)->createForVersion(PhpVersion::fromComponents(8, 3))->parse('<?php '.$statement) ?? [];
        $visitor = new class extends NodeVisitorAbstract
        {
            /** @var array<string, true> */
            public array $names = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Expr\Variable && is_string($node->name) && $node->name !== 'this') {
                    $this->names[$node->name] = true;
                }

                return null;
            }
        };
        (new NodeTraverser($visitor))->traverse($stmts);

        return array_keys($visitor->names);
    }

    /**
     * An expression as PHP that runs in no namespace and without the view's imports (a server
     * render's compiled view): a name an @use resolved (resolvedName) written out in full.
     */
    public static function printResolved(Expr $expr): string
    {
        $traverser = new NodeTraverser(new CloningVisitor, new class extends NodeVisitorAbstract
        {
            public function leaveNode(Node $node): ?Node
            {
                return $node instanceof Name && $node->getAttribute('resolvedName') instanceof Name\FullyQualified ? $node->getAttribute('resolvedName') : null;
            }
        });
        $resolved = $traverser->traverse([new Node\Stmt\Expression($expr)])[0];
        assert($resolved instanceof Node\Stmt\Expression);

        return self::print($resolved->expr);
    }

    /** Where a "(" is closed, strings and nested parentheses skipped. */
    public static function closing(string $source, int $open, ?string $file, int $line): int
    {
        $depth = 0;

        for ($i = $open, $length = strlen($source); $i < $length; $i++) {
            $context = $source[$i];

            if ($context === '"' || $context === "'") {
                for ($i++; $i < $length && $source[$i] !== $context; $i++) {
                    if ($source[$i] === '\\') {
                        $i++;
                    }
                }
            } elseif ($context === '(') {
                $depth++;
            } elseif ($context === ')' && --$depth === 0) {
                return $i;
            }
        }

        throw new CompileException('A directive\'s "(" is not closed', $file, $line);
    }
}
