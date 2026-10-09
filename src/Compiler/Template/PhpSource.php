<?php

namespace Nitro\Compiler\Template;

use Nitro\Compiler\CompileException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
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
