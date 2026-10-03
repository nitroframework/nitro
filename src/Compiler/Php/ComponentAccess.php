<?php

namespace Nitro\Fusion\Compiler\Php;

use Nitro\Fusion\Browser\Browser;
use Nitro\Fusion\Compiler\Globals;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * The component, from its compiled code: Fusion's component model, not general PHP. In the
 * browser, $this is the live component (js/src/runtime/component.js):
 *
 *  - $this->prop reads the component's state ($this.prop), by the browser's name of an
 *    obfuscated build (Scope::browserProperty()); a #[Computed] property is $this.$computed(),
 *    kept until the state it reads changes
 *  - $this->method() calls a method compiled for the browser; a #[Server] method is a request to
 *    the server ($this.$call(), the state signed), allowed only as a statement since its result
 *    arrives later
 *  - the component methods with a browser side (Globals::COMPONENT_METHODS): dispatch(), reset(),
 *    reload(), redirect(), js(), shared(), flashed()
 *  - $this->dispatch('event')->to(Card::class) / ->self(): Livewire's targeted events
 *  - in a template: $errors (the validation errors), $slots->has() and $slot->isEmpty() (the
 *    slots the parent passed), and an Upload's methods ($photo->temporaryUrl())
 *  - Fusion::browser() and what it returns (src/Browser: clipboard, storage, canvas, sockets):
 *    each call checked against the PHP class's method and its parameters, the PHP defaults filled
 *    in, and the browser function (js/functions/_browser_*.js) included in the bundle when called
 *
 * Methods of other objects do not exist in the browser: data from the server arrives as arrays.
 * Every component method named here is recorded in $calledMethods, so the ComponentCompiler
 * compiles it too.
 *
 * @internal
 */
final class ComponentAccess extends Part
{
    public function propertyFetch(Expr\PropertyFetch|Expr\NullsafePropertyFetch $e): string
    {
        $c = $this->context;

        if (! $e->name instanceof Identifier) {
            throw $c->error('Dynamic property names are not supported in the browser', $e);
        }

        $name = $e->name->toString();

        if ($c->isThis($e->var)) {
            /** #[Computed]: the method's result, kept until the state changes. */
            if ($c->scope->isComputed($name)) {
                $c->calledMethods[] = $name;

                return '$this.$computed('.$c->string($c->scope->browserMethod($name)).')';
            }

            if (! $c->scope->isProperty($name)) {
                throw $c->error("\$this->{$name} is not a public property of the component", $e);
            }

            return '$this.'.$c->scope->browserProperty($name);
        }

        return 'P.prop('.$this->transpiler->expr($e->var).', '.$c->string($name).')';
    }

    public function methodCall(Expr\MethodCall|Expr\NullsafeMethodCall $e): string
    {
        $c = $this->context;
        $calls = $this->transpiler->calls;

        if (($api = $this->browserApi($e->var)) !== null) {
            return $this->browserCall($e, $api);
        }

        /** $this->dispatch(...)->to(Card::class) / ->self(), as Livewire's. */
        if ($e->var instanceof Expr\MethodCall && $c->isThis($e->var->var) && $e->var->name instanceof Identifier && $e->var->name->toString() === 'dispatch') {
            $dispatch = $this->methodCall($e->var);

            return match ($e->name instanceof Identifier ? $e->name->toString() : '') {
                'self' => $dispatch.'.self()',
                'to' => $dispatch.'.to('.$this->component($e->args[0] ?? null, $e).')',
                default => throw $c->error('A dispatch goes ->to(Component::class) or ->self()', $e),
            };
        }

        /** $slots->has('footer'), $slot->isEmpty(), $slot->isNotEmpty(): the slots the parent passed. */
        foreach (['slots' => ['has'], 'slot' => ['isEmpty', 'isNotEmpty']] as $variable => $methods) {
            if (! $e->var instanceof Expr\Variable || $e->var->name !== $variable || $c->scope->variable === null || isset($c->locals[$variable])) {
                continue;
            }

            $method = $e->name instanceof Identifier ? $e->name->toString() : '';

            return match (true) {
                $variable === 'slots' && $method === 'has' => '$this.$slots.has('.implode(', ', $calls->args($e->args)).')',
                $method === 'isEmpty' => '!$this.$slots.has("default")',
                $method === 'isNotEmpty' => '$this.$slots.has("default")',
                default => throw $c->error("\${$variable} has ".implode('(), ', $methods).'() in the browser', $e),
            };
        }

        if ($c->isErrors($e->var)) {
            $method = $e->name instanceof Identifier ? $e->name->toString() : '';

            if (! in_array($method, Globals::ERROR_METHODS, true)) {
                throw $c->error('$errors supports '.implode('(), ', Globals::ERROR_METHODS).'()', $e);
            }

            /**
             * A field written out ('form.email'), or built from a start written out ('items.'.$i):
             * its property by the browser's name (an obfuscated build).
             */
            $args = $calls->args($e->args);
            $field = isset($e->args[0]) && $e->args[0] instanceof Arg ? $e->args[0]->value : null;

            while ($field instanceof Expr\BinaryOp\Concat) {
                $field = $field->left;
            }

            if ($field instanceof Scalar\String_ && $c->scope->names !== null) {
                $start = $field->value;
                $field->value = $c->scope->browserPath($start);
                $args = $calls->args($e->args);
                $field->value = $start;
            }

            return Globals::TEMPLATE_VARIABLES['errors'].'.'.$method.'('.implode(', ', $args).')';
        }

        /** $photo->temporaryUrl(), ->getClientOriginalName(), ...: an Upload's, checked at run time as PHP would. */
        if (! $c->isThis($e->var) && $e->name instanceof Identifier && in_array($e->name->toString(), Globals::UPLOAD_METHODS, true)) {
            $call = 'P.upload('.$this->transpiler->expr($e->var).', '.$c->string($e->name->toString()).', ['.implode(', ', $calls->args($e->args)).'])';

            return $e instanceof Expr\NullsafeMethodCall ? '('.$this->transpiler->expr($e->var).' === null ? null : '.$call.')' : $call;
        }

        if (! $c->isThis($e->var)) {
            throw $c->error('Only methods of the component ($this->method()) can be called in the browser, and an Upload\'s ('.implode('(), ', Globals::UPLOAD_METHODS).'()); data from the server arrives as arrays', $e);
        }

        if (! $e->name instanceof Identifier) {
            throw $c->error('Dynamic method names are not supported in the browser', $e);
        }

        $name = $e->name->toString();

        if ($c->scope->isServerMethod($name)) {
            throw $c->error("\$this->{$name}() runs on the server: call it as a statement, its return value is not available in the browser", $e);
        }

        if ($c->scope->isClientMethod($name)) {
            $c->calledMethods[] = $name;

            return '$this.'.$c->scope->browserMethod($name).'('.implode(', ', $calls->args($e->args)).')';
        }

        /** Globals::COMPONENT_METHODS */
        return match ($name) {
            'dispatch' => '$this.$dispatch('.$this->dispatchArgs($e->args, $e).')',
            'reset' => '$this.$reset(['.implode(', ', array_map(fn (Arg $a) => $this->propertyName($a, $e, 'reset()'), $e->args)).'])',
            'reload' => '$this.$reload(['.implode(', ', array_map(fn (Arg $a) => $this->propertyName($a, $e, 'reload()'), $e->args)).'])',
            'redirect' => '$this.$redirect('.implode(', ', $calls->args($e->args)).')',
            'js' => '$this.$js('.implode(', ', $this->jsArgs($e->args)).')',
            'shared' => 'B.shared('.implode(', ', $calls->args($e->args)).')',
            'flashed' => 'B.flashed('.implode(', ', $calls->args($e->args)).')',
            default => throw $c->error("\$this->{$name}() is not a method of the component that can run in the browser", $e),
        };
    }

    /** $this->save(): a #[Server] method, as a request to the server with the component's signed state. */
    public function serverCall(Expr\MethodCall $e): string
    {
        return '$this.$call('.$this->context->string($this->context->scope->browserMethod($e->name->toString())).', ['.implode(', ', $this->transpiler->calls->args($e->args)).'])';
    }

    /**
     * A component, for ->to() and $dispatchTo: Card::class (its name, from the application's
     * components) or its name.
     */
    public function component(mixed $arg, Node $node): string
    {
        $c = $this->context;

        if (! $arg instanceof Arg) {
            throw $c->error('->to() takes the component: Card::class or its name', $node);
        }

        $value = $arg->value;
        $class = match (true) {
            $value instanceof Expr\ClassConstFetch && $value->class instanceof Name && $value->name instanceof Identifier
                && $value->name->toLowerString() === 'class' => ($value->class->getAttribute('resolvedName') ?? $value->class)->toString(),
            $value instanceof Scalar\String_ && str_contains($value->value, '\\') => $value->value,
            default => null,
        };

        /** A name written out is the browser's name for it (an obfuscated build); one worked out as it runs is not. */
        if ($class === null) {
            return $value instanceof Scalar\String_ ? $c->string($c->scope->browserComponent($value->value)) : $this->transpiler->expr($value);
        }

        $name = $c->scope->componentName($class) ?? throw $c->error("{$class} is not a Fusion component", $node);

        return $c->string($c->scope->browserComponent($name));
    }

    /**
     * The Fusion::browser() API an expression holds: Fusion::browser() itself, a call on one that
     * returns another (or itself: ->fillRect()->stroke()), or a variable assigned one. Null for
     * anything else.
     *
     * @return class-string|null
     */
    public function browserApi(Expr $e): ?string
    {
        if ($e instanceof Expr\StaticCall && $e->class instanceof Name && $e->name instanceof Identifier && $e->name->toString() === 'browser'
            && in_array(ltrim(($e->class->getAttribute('resolvedName') ?? $e->class)->toString(), '\\'), Catalog::FUSION, true)) {
            return Browser::class;
        }

        if ($e instanceof Expr\Variable && is_string($e->name)) {
            return $this->context->browserVariables[$e->name] ?? null;
        }

        if (($e instanceof Expr\MethodCall || $e instanceof Expr\NullsafeMethodCall) && $e->name instanceof Identifier
            && ($api = $this->browserApi($e->var)) !== null && method_exists($api, $e->name->toString())) {
            $type = (new ReflectionMethod($api, $e->name->toString()))->getReturnType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : null;

            return match (true) {
                $name === 'static', $name === 'self' => $api,
                $name !== null && str_starts_with($name, 'Nitro\\Fusion\\Browser\\') && class_exists($name) => $name,
                default => null,
            };
        }

        return null;
    }

    /** $this->dispatch('event', ...$args or named: arguments). */
    public function dispatchArgs(array $args, Node $node): string
    {
        $c = $this->context;

        if ($args === []) {
            throw $c->error('dispatch() needs an event name', $node);
        }

        $name = $this->transpiler->expr(array_shift($args)->value);
        $named = [];
        $positional = [];

        foreach ($args as $arg) {
            if ($arg->unpack) {
                throw $c->error('Unpacking is not supported in dispatch()', $arg);
            }

            $arg->name !== null
                ? $named[] = '['.$c->string($arg->name->toString()).', '.$this->transpiler->expr($arg->value).', false]'
                : $positional[] = $this->transpiler->expr($arg->value);
        }

        if ($named !== [] && $positional !== []) {
            throw $c->error('dispatch() takes either named or positional parameters', $node);
        }

        return $name.', '.($named !== [] ? 'P.arrayLiteral(['.implode(', ', $named).'])' : '['.implode(', ', $positional).']');
    }

    /** A public property's name written out (reset('name'), $reload('items')), as the browser names it. */
    public function propertyName(Arg $arg, Node $node, string $method): string
    {
        if (! $arg->value instanceof Scalar\String_ || ! $this->context->scope->isProperty($arg->value->value)) {
            throw $this->context->error("{$method} takes the names of public properties", $node);
        }

        return $this->context->string($this->context->scope->browserProperty($arg->value->value));
    }

    /**
     * A call on a Fusion::browser() API, checked against its PHP class (src/Browser): on the
     * Browser, one of js/functions/_browser_*.js, in the bundle when called; on what that
     * returns, its method. Arguments left out get the PHP method's defaults.
     *
     * @param  class-string  $api
     */
    private function browserCall(Expr\MethodCall|Expr\NullsafeMethodCall $e, string $api): string
    {
        $c = $this->context;
        $short = 'Fusion::browser()'.($api === Browser::class ? '' : '->'.lcfirst(class_basename($api)).'()');
        $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), (new ReflectionClass($api))->getMethods(ReflectionMethod::IS_PUBLIC));
        $name = $e->name instanceof Identifier ? $e->name->toString() : throw $c->error('Dynamic method names are not supported in the browser', $e);

        if (! in_array($name, $methods, true)) {
            throw $c->error("{$short}->{$name}() does not exist: it has ".implode('(), ', $methods).'()', $e);
        }

        $method = new ReflectionMethod($api, $name);

        foreach ($e->args as $arg) {
            if (! $arg instanceof Arg || $arg->name !== null || $arg->unpack) {
                throw $c->error("{$short}->{$name}() takes its arguments in order", $e);
            }
        }

        [$required, $total] = [$method->getNumberOfRequiredParameters(), $method->getNumberOfParameters()];

        if (count($e->args) < $required || count($e->args) > $total) {
            $params = implode(', ', array_map(fn (ReflectionParameter $p) => '$'.$p->getName(), $method->getParameters()));
            $count = ($required === $total ? $total : "{$required} to {$total}").($total === 1 ? ' argument' : ' arguments');

            throw $c->error("{$short}->{$name}({$params}) takes {$count}", $e);
        }

        $args = $this->transpiler->calls->args($e->args);

        foreach (array_slice($method->getParameters(), count($e->args)) as $param) {
            $args[] = $this->transpiler->expressions->literal($param->getDefaultValue(), $e);
        }

        if ($api === Browser::class) {
            $c->uses['functions']['_browser_'.$name] = true;

            return 'P.fns._browser_'.$name.'('.implode(', ', [$this->transpiler->expr($e->var), ...$args]).')';
        }

        return $this->transpiler->expr($e->var).'.'.$name.'('.implode(', ', $args).')';
    }

    /**
     * $this->js('...')'s code: written out, it reads $fusion.<name> by the browser's names (an
     * obfuscated build).
     *
     * @param  list<Arg|Node\VariadicPlaceholder>  $args
     * @return list<string>
     */
    private function jsArgs(array $args): array
    {
        $c = $this->context;
        $compiled = $this->transpiler->calls->args($args);

        if ($c->scope->names !== null && isset($args[0]) && $args[0] instanceof Arg && $args[0]->value instanceof Scalar\String_) {
            $compiled[0] = $c->string($c->scope->names->script($args[0]->value->value, $c->scope->properties, [...$c->scope->clientMethods, ...$c->scope->serverMethods]));
        }

        return $compiled;
    }
}
