<?php

namespace Nitro\Fusion\Compiler\Php;

use Illuminate\Support\Str;
use Nitro\Fusion\Compiler\Functions as JsFunctions;
use Nitro\Fusion\Compiler\Globals;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

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
 * Laravel (Fusion ships what the code names, so the browser has it):
 *
 *  - route(), __() / trans(), config(): the route name, translation key or config key must be a
 *    string literal; it is recorded in $uses, and the page carries those routes, lines and values
 *  - url() and asset(): the page's base URLs
 *  - Str::slug() and the other Str helpers: ported to js/functions (Str_slug)
 *
 * Fusion: Fusion::browser() is the component's browser APIs ($this.$browser); see
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

    public function funcCall(Expr\FuncCall $e): string
    {
        if ($e->name instanceof Expr\Variable) {
            return $this->transpiler->expr($e->name).'('.implode(', ', $this->args($e->args)).')';
        }

        if (! $e->name instanceof Name) {
            throw $this->context->error('Unsupported function call', $e);
        }

        $name = Catalog::functionName($e->name);

        if (in_array($name, Globals::HELPERS, true)) {
            return $this->helper($name, $e);
        }

        if (in_array($name, Catalog::BY_REF, true)) {
            return $this->byRefCall($e, true);
        }

        if (isset(Catalog::UNSUPPORTED_REFS[$name]) && count($e->args) > Catalog::UNSUPPORTED_REFS[$name]) {
            throw $this->context->error("{$name}()'s argument ".(Catalog::UNSUPPORTED_REFS[$name] + 1).' (a reference) is not available in the browser', $e);
        }

        if (isset(Catalog::OUT_PARAMS[$name])) {
            return $this->outParamCall($e, $name, Catalog::OUT_PARAMS[$name]);
        }

        if (JsFunctions::has($name)) {
            /** Viewi's: the bundle gets the functions its components call. */
            $this->context->uses['functions'][$name] = true;
        } elseif (! in_array($name, Catalog::FUNCTIONS, true)) {
            throw $this->context->error("The function {$name}() is not available in the browser: move this code to a #[Server] method", $e);
        }

        return 'P.fns.'.$name.'('.implode(', ', $this->args($e->args)).')';
    }

    /** sort($x), array_pop($this->items), ...: [newArray, returnValue], assigned back to the target. */
    public function byRefCall(Expr\FuncCall $e, bool $valueUsed): string
    {
        $name = Catalog::functionName($e->name);

        if ($e->args === [] || ! $e->args[0] instanceof Arg) {
            throw $this->context->error("{$name}() needs an array", $e);
        }

        [$root, $path] = $this->transpiler->writes->target($e->args[0]->value);
        $rest = $this->args(array_slice($e->args, 1));
        $current = $path === [] ? $root : "P.getIn({$root}, [".implode(', ', $path).'])';
        $call = 'P.fns.'.$name.'('.implode(', ', [$current, ...$rest]).')';
        $result = $this->context->temp();
        $write = $path === [] ? "{$root} = {$result}[0]" : "{$root} = P.setIn({$root}, [".implode(', ', $path)."], {$result}[0])";

        return "({$result} = {$call}, {$write}".($valueUsed ? ", {$result}[1])" : ')');
    }

    /**
     * Str::slug($title): Laravel's Str helpers, ported to js/functions (Str_slug). Str is the
     * Illuminate\Support\Str class, as a view (Blade's alias) or a use statement names it.
     * Fusion::browser() is the component's browser APIs.
     */
    public function staticCall(Expr\StaticCall $e): string
    {
        $class = $e->class instanceof Name ? ltrim(($e->class->getAttribute('resolvedName') ?? $e->class)->toString(), '\\') : null;

        if (in_array($class, Catalog::FUSION, true)) {
            if (! $e->name instanceof Identifier || $e->name->toString() !== 'browser' || $e->args !== []) {
                throw $this->context->error('Fusion:: runs on the server; in the browser, Fusion::browser() has the browser\'s APIs', $e);
            }

            return '$this.$browser';
        }

        if (! in_array($class, ['Str', Str::class], true) || ! $e->name instanceof Identifier) {
            throw $this->context->error('Static calls are not supported in the browser (Str:: is): move this code to a #[Server] method', $e);
        }

        $function = 'Str_'.$e->name->toString();

        if (! JsFunctions::has($function)) {
            throw $this->context->error("Str::{$e->name->toString()}() is not available in the browser: move this code to a #[Server] method", $e);
        }

        $this->context->uses['functions'][$function] = true;

        return 'P.fns.'.$function.'('.implode(', ', $this->args($e->args)).')';
    }

    /**
     * route(), url(), asset(), __() / trans(), config(): Laravel's helpers, in blade.js. A route
     * name, a translation key and a config key are literals: the page ships what they name.
     */
    private function helper(string $name, Expr\FuncCall $e): string
    {
        $literal = function (string $what) use ($name, $e): string {
            $first = $e->args[0] ?? null;

            if (! $first instanceof Arg || $first->name !== null || ! $first->value instanceof Scalar\String_) {
                throw $this->context->error("{$name}() in the browser takes the {$what} as a string literal: the page ships what the code names", $e);
            }

            return $first->value->value;
        };

        $args = $this->args($e->args);

        switch ($name) {
            case 'route':
                $this->context->uses['routes'][$literal('route name')] = true;

                return 'B.route('.implode(', ', $args).')';
            case '__':
            case 'trans':
                if (count($args) > 2) {
                    throw $this->context->error("{$name}() in the browser uses the page's locale: pass the key and the replacements", $e);
                }

                $this->context->uses['lang'][$literal('translation key')] = true;

                return 'B.trans('.implode(', ', $args).')';
            case 'config':
                $this->context->uses['config'][$literal('config key')] = true;

                return 'B.config('.implode(', ', $args).')';
            default: // url, asset
                if ($args === []) {
                    throw $this->context->error("{$name}() in the browser needs a path", $e);
                }

                return 'B.'.$name.'('.implode(', ', $args).')';
        }
    }

    /**
     * preg_match($pattern, $subject, $matches): the function returns [result, output] in the
     * browser; the output is assigned where PHP's reference would write it.
     */
    private function outParamCall(Expr\FuncCall $e, string $name, int $position): string
    {
        $this->context->uses['functions'][$name] = true;
        $out = $e->args[$position] ?? null;

        if ($out !== null && (! $out instanceof Arg || $out->name !== null || $out->unpack)) {
            throw $this->context->error("{$name}()'s argument ".($position + 1).' must be a variable or property', $e);
        }

        $rest = $this->args([...array_slice($e->args, 0, $position), ...array_slice($e->args, $position + 1)]);
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
