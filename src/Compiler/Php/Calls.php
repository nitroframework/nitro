<?php

namespace Nitro\Compiler\Php;

use Illuminate\Support\Str;
use Nitro\Compiler\BrowserClasses;
use Nitro\Compiler\Functions as JsFunctions;
use Nitro\Compiler\Globals;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use ReflectionMethod;

/**
 * Calls to functions: PHP's functions, Laravel's helpers and Str::, and a closure in a variable.
 *
 * General PHP:
 *
 *  - a PHP function is P.fns.name(...): those of js/src/php.js (Catalog::FUNCTIONS), and those
 *    of js/functions (Functions::has()), which the bundle includes only when the code calls them
 *    ($uses['functions'])
 *  - a function taking its array by reference (sort($x), array_pop($this->items)) returns
 *    [newArray, result] in the browser; the new array is written back to the argument (P.setIn
 *    along its path), as PHP's reference would
 *  - preg_match()'s output parameter likewise: the function returns [result, matches]
 *  - named arguments, by-reference arguments and first-class callables (strlen(...)) fail to
 *    compile; ...$spread goes through P.spread
 *
 * Laravel (Nitro ships what the code names, so the browser has it):
 *
 *  - route(), __() / trans(), config(): the route name, translation key or config key must be a
 *    string literal; it is recorded in $uses, and the page carries those routes, lines and values
 *  - url() and asset(): the page's base URLs
 *  - Str::slug() and the other Str helpers: ported to js/functions (Str_slug)
 *
 * Nitro: Nitro::browser() is the component's browser APIs ($this.$browser); see
 * ComponentAccess for the calls on it.
 *
 * @internal
 */
final class Calls extends Part
{
    /**
     * Arguments as JavaScript, in order.
     *
     * @param  list<Arg|Node\VariadicPlaceholder>  $args
     * @return list<string>
     */
    public function args(array $args): array
    {
        $out = [];

        foreach ($args as $arg) {
            if (! $arg instanceof Arg) {
                throw $this->context->error('First-class callable syntax is not supported in the browser', $arg);
            }

            if ($arg->name !== null) {
                throw $this->context->error('Named arguments are not supported in the browser', $arg);
            }

            if ($arg->byRef) {
                throw $this->context->error('References are not supported in the browser', $arg);
            }

            $out[] = $arg->unpack ? '...P.spread('.$this->transpiler->expr($arg->value).')' : $this->transpiler->expr($arg->value);
        }

        return $out;
    }

    public function funcCall(Expr\FuncCall $expression): string
    {
        if ($expression->name instanceof Expr\Variable) {
            return $this->transpiler->expr($expression->name).'('.implode(', ', $this->args($expression->args)).')';
        }

        if (! $expression->name instanceof Name) {
            throw $this->context->error('Unsupported function call', $expression);
        }

        $name = Catalog::functionName($expression->name);

        if (in_array($name, Globals::HELPERS, true)) {
            return $this->helper($name, $expression);
        }

        if (in_array($name, Catalog::BY_REF, true)) {
            return $this->byRefCall($expression, true);
        }

        if (isset(Catalog::UNSUPPORTED_REFS[$name]) && count($expression->args) > Catalog::UNSUPPORTED_REFS[$name]) {
            throw $this->context->error("{$name}()'s argument ".(Catalog::UNSUPPORTED_REFS[$name] + 1).' (a reference) is not available in the browser', $expression);
        }

        if (isset(Catalog::OUT_PARAMS[$name])) {
            return $this->outParamCall($expression, $name, Catalog::OUT_PARAMS[$name]);
        }

        if (JsFunctions::has($name)) {
            /** Viewi's: the bundle gets the functions its components call. */
            $this->context->uses['functions'][$name] = true;
        } elseif (! in_array($name, Catalog::FUNCTIONS, true)) {
            throw $this->context->error("The function {$name}() is not available in the browser: move this code to a #[Server] method", $expression);
        }

        return 'P.fns.'.$name.'('.implode(', ', $this->args($expression->args)).')';
    }

    /** sort($x), array_pop($this->items), ...: [newArray, returnValue], assigned back to the target. */
    public function byRefCall(Expr\FuncCall $expression, bool $valueUsed): string
    {
        $name = Catalog::functionName($expression->name);

        if ($expression->args === [] || ! $expression->args[0] instanceof Arg) {
            throw $this->context->error("{$name}() needs an array", $expression);
        }

        [$root, $path] = $this->transpiler->writes->target($expression->args[0]->value);
        $rest = $this->args(array_slice($expression->args, 1));
        $current = $path === [] ? $root : "P.getIn({$root}, [".implode(', ', $path).'])';
        $call = 'P.fns.'.$name.'('.implode(', ', [$current, ...$rest]).')';
        $result = $this->context->temp();
        $write = $path === [] ? "{$root} = {$result}[0]" : "{$root} = P.setIn({$root}, [".implode(', ', $path)."], {$result}[0])";

        return "({$result} = {$call}, {$write}".($valueUsed ? ", {$result}[1])" : ')');
    }

    /**
     * Str::slug($title): Laravel's Str helpers, ported to js/functions (Str_slug). Str is the
     * Illuminate\Support\Str class, as a view (Blade's alias) or a use statement names it.
     * Nitro::browser() is the component's browser APIs. A #[Browser] class's static methods
     * (browserClassCall()).
     */
    public function staticCall(Expr\StaticCall $expression): string
    {
        $class = $expression->class instanceof Name ? ltrim(($expression->class->getAttribute('resolvedName') ?? $expression->class)->toString(), '\\') : null;

        if (in_array($class, Catalog::NITRO, true)) {
            if (! $expression->name instanceof Identifier || $expression->name->toString() !== 'browser' || $expression->args !== []) {
                throw $this->context->error('Nitro:: runs on the server; in the browser, Nitro::browser() has the browser\'s APIs', $expression);
            }

            return '$this.$browser';
        }

        /** In a #[Browser] class's method, self:: and static:: are that class. */
        if ($this->context->class !== null && $expression->class instanceof Name && in_array($expression->class->toLowerString(), ['self', 'static'], true)) {
            $class = $this->context->class;
        }

        if ($class !== null && (BrowserClasses::is($class) || BrowserClasses::isEnum($class))) {
            return $this->browserClassCall($class, $expression);
        }

        if (! in_array($class, ['Str', Str::class], true) || ! $expression->name instanceof Identifier) {
            throw $this->context->error($class !== null && class_exists($class)
                ? "{$class}::{$this->methodName($expression)}() is not a browser method: mark {$class} #[Browser] to use its static methods in the browser, or move this code to a #[Server] method"
                : 'Static calls are not supported in the browser (Str:: and #[Browser] classes are): move this code to a #[Server] method', $expression);
        }

        $function = 'Str_'.$expression->name->toString();

        if (! JsFunctions::has($function)) {
            throw $this->context->error("Str::{$expression->name->toString()}() is not available in the browser: move this code to a #[Server] method", $expression);
        }

        $this->context->uses['functions'][$function] = true;

        return 'P.fns.'.$function.'('.implode(', ', $this->args($expression->args)).')';
    }

    /**
     * Money::format($cents): a static method of a #[Browser] class or enum, compiled with the
     * bundle (ComponentCompiler::compileClasses()) and called as Nitro.classes[class][method]. A
     * private or protected one only from its own class. An enum's cases(), from() and tryFrom()
     * are the runtime's (P.defineEnum()).
     *
     * @param  class-string  $class
     */
    private function browserClassCall(string $class, Expr\StaticCall $expression): string
    {
        if (! $expression->name instanceof Identifier) {
            throw $this->context->error("{$class}'s method must be named, not computed, in the browser", $expression);
        }

        $name = $expression->name->toString();
        $scope = $this->context->scope;
        $target = 'Nitro.classes['.json_encode($scope->browserClass($class), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).']';

        if (BrowserClasses::isEnum($class)) {
            $this->context->uses['classes'][BrowserClasses::definition($class)] = true;

            /** An enum's own: cases(), and a backed enum's from() and tryFrom(). */
            $builtin = strtolower($name);

            if (in_array($builtin, ['cases', 'from', 'tryfrom'], true)) {
                if ($builtin !== 'cases' && ! is_subclass_of($class, \BackedEnum::class)) {
                    throw $this->context->error("{$class} is a pure enum: it has no {$name}()", $expression);
                }

                return $target.'.'.($builtin === 'tryfrom' ? 'tryFrom' : $builtin).'('.implode(', ', $this->args($expression->args)).')';
            }
        }

        if (! method_exists($class, $name)) {
            throw $this->context->error("Call to undefined method {$class}::{$name}()", $expression);
        }

        $method = new ReflectionMethod($class, $name);
        $name = $method->getName();

        if (! $method->isStatic()) {
            throw $this->context->error("{$class}::{$name}() is not static: a #[Browser] class's static methods run in the browser", $expression);
        }

        if (! $method->isPublic() && $this->context->class !== $class) {
            throw $this->context->error("{$class}::{$name}() is not public", $expression);
        }

        $this->context->uses['classes'][BrowserClasses::key($class, $name)] = true;

        return $target.'['.json_encode($scope->browserMethod($name), JSON_THROW_ON_ERROR).']'
            .'('.implode(', ', $this->args($expression->args)).')';
    }

    private function methodName(Expr\StaticCall $expression): string
    {
        return $expression->name instanceof Identifier ? $expression->name->toString() : '...';
    }

    /**
     * route(), url(), asset(), __() / trans(), config(): Laravel's helpers, in blade.js. A route
     * name, a translation key and a config key are literals: the page ships what they name.
     */
    private function helper(string $name, Expr\FuncCall $expression): string
    {
        $literal = function (string $what) use ($name, $expression): string {
            $first = $expression->args[0] ?? null;

            if (! $first instanceof Arg || $first->name !== null || ! $first->value instanceof Scalar\String_) {
                throw $this->context->error("{$name}() in the browser takes the {$what} as a string literal: the page ships what the code names", $expression);
            }

            return $first->value->value;
        };

        $args = $this->args($expression->args);

        switch ($name) {
            case 'route':
                $this->context->uses['routes'][$literal('route name')] = true;

                return 'B.route('.implode(', ', $args).')';
            case '__':
            case 'trans':
                if (count($args) > 2) {
                    throw $this->context->error("{$name}() in the browser uses the page's locale: pass the key and the replacements", $expression);
                }

                $this->context->uses['lang'][$literal('translation key')] = true;

                return 'B.trans('.implode(', ', $args).')';
            case 'config':
                $this->context->uses['config'][$literal('config key')] = true;

                return 'B.config('.implode(', ', $args).')';
            default: // url, asset
                if ($args === []) {
                    throw $this->context->error("{$name}() in the browser needs a path", $expression);
                }

                return 'B.'.$name.'('.implode(', ', $args).')';
        }
    }

    /**
     * preg_match($pattern, $subject, $matches): the function returns [result, output] in the
     * browser; the output is assigned where PHP's reference would write it.
     */
    private function outParamCall(Expr\FuncCall $expression, string $name, int $position): string
    {
        $this->context->uses['functions'][$name] = true;
        $out = $expression->args[$position] ?? null;

        if ($out !== null && (! $out instanceof Arg || $out->name !== null || $out->unpack)) {
            throw $this->context->error("{$name}()'s argument ".($position + 1).' must be a variable or property', $expression);
        }

        $rest = $this->args([...array_slice($expression->args, 0, $position), ...array_slice($expression->args, $position + 1)]);
        $call = 'P.fns.'.$name.'('.implode(', ', $rest).')';

        if ($out === null) {
            return $call.'[0]';
        }

        [$root, $path] = $this->transpiler->writes->target($out->value);
        $result = $this->context->temp();
        $write = $path === [] ? "{$root} = {$result}[1]" : "{$root} = P.setIn({$root}, [".implode(', ', $path)."], {$result}[1])";

        return "({$result} = {$call}, {$write}, {$result}[0])";
    }
}
