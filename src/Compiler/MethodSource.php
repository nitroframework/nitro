<?php

namespace Nitro\Fusion\Compiler;

use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionMethod;
use Throwable;

/**
 * A method's source as the compiler reads it: its syntax tree, names resolved as the file's
 * imports make them (Card::class: its full name), kept as written. A file is parsed once (again
 * when it is edited), whichever of its methods is asked for.
 */
final class MethodSource
{
    /** @var array<string, list<Stmt>> "file:mtime" => its parsed statements, names resolved */
    private static array $parsed = [];

    /** @param  class-string  $class */
    public static function node(string $class, string $method): Stmt\ClassMethod
    {
        $reflection = new ReflectionMethod($class, $method);
        $file = (string) $reflection->getFileName();
        $cached = $file.':'.@filemtime($file);

        if (! isset(self::$parsed[$cached])) {
            try {
                $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? [];
            } catch (Throwable $exception) {
                throw new CompileException("Cannot parse {$class}: ".$exception->getMessage(), $file);
            }

            (new NodeTraverser(new NameResolver(null, ['replaceNodes' => false])))->traverse($ast);
            self::$parsed[$cached] = $ast;
        }

        /** Reflection starts at "function"; a node with attributes starts at its first attribute. */
        $line = $reflection->getStartLine();

        foreach ((new NodeFinder)->findInstanceOf(self::$parsed[$cached], Stmt\ClassMethod::class) as $node) {
            if ($node->name->toString() === $method && $node->getStartLine() <= $line && $line <= $node->getEndLine()) {
                return $node;
            }
        }

        throw new CompileException("Cannot find the source of {$class}::{$method}()", $file, $line);
    }
}
