<?php

namespace Nitro\Fusion\Compiler;

use Nitro\Fusion\Attributes\Browser;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * The application's #[Browser] classes and enums: which are, and their methods' source for the
 * compiler. The compiled code's uses record what it reaches by key: "Class::method" a method,
 * "Enum::" an enum's definition, "*::method" a method called on a value of unknown type (an enum
 * case's, compiled for the enums the code reaches).
 *
 * @internal
 */
final class BrowserClasses
{
    /** @var array<string, bool> */
    private static array $marked = [];

    /** @var array<string, bool> */
    private static array $enums = [];

    /** @var array<string, list<Stmt>> "file:mtime" => its parsed statements, names resolved (an edited file is parsed again) */
    private static array $parsed = [];

    /** Whether $class is a class marked #[Browser] (not an enum, an interface or a trait). */
    public static function is(string $class): bool
    {
        return self::$marked[$class] ??= class_exists($class) && ! enum_exists($class)
            && (new ReflectionClass($class))->getAttributes(Browser::class) !== [];
    }

    /** Whether $class is an enum marked #[Browser]. */
    public static function isEnum(string $class): bool
    {
        return self::$enums[$class] ??= enum_exists($class) && (new ReflectionClass($class))->getAttributes(Browser::class) !== [];
    }

    /** "Class::method" for a uses key. */
    public static function key(string $class, string $method): string
    {
        return $class.'::'.$method;
    }

    /** "Enum::": the uses key of an enum's definition (its cases, cases(), from(), tryFrom()). */
    public static function definition(string $enum): string
    {
        return $enum.'::';
    }

    /**
     * "*::label@file:line": the uses key of $value->label() on a value of a type not known while
     * compiling (an enum case's method), with where it is written, for the error when no enum has it.
     */
    public static function anyEnum(string $method, ?string $file, ?int $line): string
    {
        return '*::'.$method.'@'.$file.':'.$line;
    }

    /**
     * The method and where it is written, from the method part of an anyEnum() key.
     *
     * @return array{0: string, 1: ?string, 2: ?int}
     */
    public static function calledAt(string $method): array
    {
        [$name, $at] = explode('@', $method, 2) + [1 => ':'];
        $colon = (int) strrpos($at, ':');
        $file = substr($at, 0, $colon);
        $line = substr($at, $colon + 1);

        return [$name, $file === '' ? null : $file, $line === '' ? null : (int) $line];
    }

    /** @return array{0: class-string, 1: string} the class and the method of a uses key */
    public static function split(string $key): array
    {
        /** @var array{0: class-string, 1: string} */
        return explode('::', $key, 2);
    }

    /**
     * The method's syntax tree, names resolved as the file's imports make them.
     *
     * @param  class-string  $class
     */
    public static function methodNode(string $class, string $method): Stmt\ClassMethod
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

    /**
     * The files a class is compiled from: its own, its parents' and their traits'.
     *
     * @param  class-string  $class
     * @return list<string>
     */
    public static function files(string $class): array
    {
        $files = [];

        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            $files[] = (string) $reflection->getFileName();

            foreach ($reflection->getTraits() as $trait) {
                $files[] = (string) $trait->getFileName();
            }
        }

        return array_values(array_unique(array_filter($files)));
    }
}
