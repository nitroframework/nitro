<?php

namespace Nitro\Fusion\Compiler\Php;

use Nitro\Fusion\Browser\Browser;
use Nitro\Fusion\Compiler\BrowserClasses;
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
 * browser, $this is the live component (js/src/runtime/component/component.js):
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
 *    in, and the browser function (js/functions/browser/_browser_*.js) included in the bundle when called
 *
 * Methods of other objects do not exist in the browser: data from the server arrives as arrays.
 * Every component method named here is recorded in $calledMethods, so the ComponentCompiler
 * compiles it too.
 *
 * @internal
 */
final class ComponentAccess extends Part
{
    public function propertyFetch(Expr\PropertyFetch|Expr\NullsafePropertyFetch $expression): string
    {
        $context = $this->context;

        if (! $expression->name instanceof Identifier) {
            throw $context->error('Dynamic property names are not supported in the browser', $expression);
        }

        $name = $expression->name->toString();

        /** $this->cart->count, or $cart->count in a template: a member of a store the component uses. */
        if (($store = $this->storeOf($expression->var)) !== null) {
            return $this->storeMember($store, $name, $expression);
        }

        /** In an enum's method: the case's ->name and ->value. */
        if ($context->enumCase && $context->isThis($expression->var)) {
            if ($name !== 'name' && ($name !== 'value' || ! is_subclass_of((string) $context->class, \BackedEnum::class))) {
                throw $context->error("Undefined property {$context->class}::\${$name}", $expression);
            }

            return '$this.'.$name;
        }

        if ($context->isThis($expression->var)) {
            /** A store the component uses (public Cart $cart): read as a whole, its members are read through it. */
            if ($context->scope->isStore($name)) {
                return '$this.'.$context->scope->browserProperty($name);
            }

            /** #[Computed]: the method's result, kept until the state changes. */
            if ($context->scope->isComputed($name)) {
                $context->calledMethods[] = $name;

                return '$this.$computed('.$context->string($context->scope->browserMethod($name)).')';
            }

            if (! $context->scope->isProperty($name)) {
                throw $context->error("\$this->{$name} is not a public property of the component", $expression);
            }

            return '$this.'.$context->scope->browserProperty($name);
        }

        return 'P.prop('.$this->transpiler->expr($expression->var).', '.$context->string($name).')';
    }

    public function methodCall(Expr\MethodCall|Expr\NullsafeMethodCall $expression): string
    {
        $context = $this->context;
        $calls = $this->transpiler->calls;

        if (($api = $this->browserApi($expression->var)) !== null) {
            return $this->browserCall($expression, $api);
        }

        /** $this->dispatch(...)->to(Card::class) / ->self(), as Livewire's. */
        if ($expression->var instanceof Expr\MethodCall && $context->isThis($expression->var->var) && $expression->var->name instanceof Identifier && $expression->var->name->toString() === 'dispatch') {
            $dispatch = $this->methodCall($expression->var);

            return match ($expression->name instanceof Identifier ? $expression->name->toString() : '') {
                'self' => $dispatch.'.self()',
                'to' => $dispatch.'.to('.$this->component($expression->args[0] ?? null, $expression).')',
                default => throw $context->error('A dispatch goes ->to(Component::class) or ->self()', $expression),
            };
        }

        /** $slots->has('footer'), $slot->isEmpty(), $slot->isNotEmpty(): the slots the parent passed. */
        foreach (['slots' => ['has'], 'slot' => ['isEmpty', 'isNotEmpty']] as $variable => $methods) {
            if (! $expression->var instanceof Expr\Variable || $expression->var->name !== $variable || $context->scope->variable === null || isset($context->locals[$variable])) {
                continue;
            }

            $method = $expression->name instanceof Identifier ? $expression->name->toString() : '';

            return match (true) {
                $variable === 'slots' && $method === 'has' => '$this.$slots.has('.implode(', ', $calls->args($expression->args)).')',
                $method === 'isEmpty' => '!$this.$slots.has("default")',
                $method === 'isNotEmpty' => '$this.$slots.has("default")',
                default => throw $context->error("\${$variable} has ".implode('(), ', $methods).'() in the browser', $expression),
            };
        }

        if ($context->isErrors($expression->var)) {
            $method = $expression->name instanceof Identifier ? $expression->name->toString() : '';

            if (! in_array($method, Globals::ERROR_METHODS, true)) {
                throw $context->error('$errors supports '.implode('(), ', Globals::ERROR_METHODS).'()', $expression);
            }

            /**
             * A field written out ('form.email'), or built from a start written out ('items.'.$i):
             * its property by the browser's name (an obfuscated build).
             */
            $args = $calls->args($expression->args);
            $field = isset($expression->args[0]) && $expression->args[0] instanceof Arg ? $expression->args[0]->value : null;

            while ($field instanceof Expr\BinaryOp\Concat) {
                $field = $field->left;
            }

            if ($field instanceof Scalar\String_ && $context->scope->names !== null) {
                $start = $field->value;
                $field->value = $context->scope->browserPath($start);
                $args = $calls->args($expression->args);
                $field->value = $start;
            }

            return Globals::TEMPLATE_VARIABLES['errors'].'.'.$method.'('.implode(', ', $args).')';
        }

        /** $photo->temporaryUrl(), ->getClientOriginalName(), ...: an Upload's, checked at run time as PHP would. */
        if (! $context->isThis($expression->var) && $expression->name instanceof Identifier && in_array($expression->name->toString(), Globals::UPLOAD_METHODS, true)) {
            $call = 'P.upload('.$this->transpiler->expr($expression->var).', '.$context->string($expression->name->toString()).', ['.implode(', ', $calls->args($expression->args)).'])';

            return $expression instanceof Expr\NullsafeMethodCall ? '('.$this->transpiler->expr($expression->var).' === null ? null : '.$call.')' : $call;
        }

        /** $this->cart->add($product): a store's method, in the browser. */
        if (($store = $this->storeOf($expression->var)) !== null) {
            return $this->storeCall($store, $expression);
        }

        if (! $expression->name instanceof Identifier) {
            throw $context->error('Dynamic method names are not supported in the browser', $expression);
        }

        $name = $expression->name->toString();

        /**
         * $status->label() on a value: in the browser, only an enum case has methods. The method is
         * compiled for the #[Browser] enums the code reaches, and one of them must have it
         * (ComponentCompiler); data from the server arrives as arrays.
         */
        if (! $context->isThis($expression->var)) {
            $at = $context->error('', $expression);
            $context->uses['classes'][BrowserClasses::anyEnum($name, $at->sourceFile, $at->sourceLine)] = true;
            $call = 'P.enumCall('.$this->transpiler->expr($expression->var).', '.$context->string($context->scope->browserMethod($name)).', ['.implode(', ', $calls->args($expression->args)).'])';

            return $expression instanceof Expr\NullsafeMethodCall ? '('.$this->transpiler->expr($expression->var).' === null ? null : '.$call.')' : $call;
        }

        /** In an enum's method: another of its methods, on the same case. */
        if ($context->enumCase && ! $context->scope->isClientMethod($name)) {
            throw $context->error("Call to undefined method {$context->class}::{$name}()", $expression);
        }

        if ($context->scope->isServerMethod($name)) {
            throw $context->error("\$this->{$name}() runs on the server: call it as a statement, its return value is not available in the browser", $expression);
        }

        if ($context->scope->isClientMethod($name)) {
            $context->calledMethods[] = $name;

            return '$this.'.$context->scope->browserMethod($name).'('.implode(', ', $calls->args($expression->args)).')';
        }

        /** Globals::COMPONENT_METHODS */
        return match ($name) {
            'dispatch' => '$this.$dispatch('.$this->dispatchArgs($expression->args, $expression).')',
            'reset' => '$this.$reset(['.implode(', ', array_map(fn (Arg $argument) => $this->propertyName($argument, $expression, 'reset()'), $expression->args)).'])',
            'reload' => '$this.$reload(['.implode(', ', array_map(fn (Arg $argument) => $this->propertyName($argument, $expression, 'reload()'), $expression->args)).'])',
            'redirect' => '$this.$redirect('.implode(', ', $calls->args($expression->args)).')',
            'js' => '$this.$js('.implode(', ', $this->jsArgs($expression->args)).')',
            'shared' => 'B.shared('.implode(', ', $calls->args($expression->args)).')',
            'flashed' => 'B.flashed('.implode(', ', $calls->args($expression->args)).')',
            'hasMore' => count($expression->args) === 1 && $expression->args[0] instanceof Arg
                ? '$this.$hasMore('.$this->propertyName($expression->args[0], $expression, '$this->hasMore()').')'
                : throw $context->error('$this->hasMore() takes the name of one scroll property', $expression),
            default => throw $context->error("\$this->{$name}() is not a method of the component that can run in the browser", $expression),
        };
    }

    /** $this->save(): a #[Server] method, as a request to the server with the component's signed state. */
    public function serverCall(Expr\MethodCall $expression): string
    {
        /** $this->cart->save(): the store's, with the store's signed state. */
        if (($store = $this->storeOf($expression->var)) !== null) {
            return $this->storeBase($store).'.$call('.$this->context->string($this->context->scope->store($store)->browserMethod($expression->name->toString())).', ['.implode(', ', $this->transpiler->calls->args($expression->args)).'])';
        }

        return '$this.$call('.$this->context->string($this->context->scope->browserMethod($expression->name->toString())).', ['.implode(', ', $this->transpiler->calls->args($expression->args)).'])';
    }

    /**
     * The store property an expression is: $this->cart, or $cart in a template (not a variable of
     * its own); null for anything else.
     */
    public function storeOf(Expr $expression): ?string
    {
        $context = $this->context;

        if (($expression instanceof Expr\PropertyFetch || $expression instanceof Expr\NullsafePropertyFetch) && $context->isThis($expression->var) && $expression->name instanceof Identifier && $context->scope->isStore($expression->name->toString())) {
            return $expression->name->toString();
        }

        if ($expression instanceof Expr\Variable && is_string($expression->name) && $context->scope->variable !== null && ! isset($context->locals[$expression->name]) && $context->scope->isStore($expression->name)) {
            return $expression->name;
        }

        return null;
    }

    /** Whether a call is a store's #[Server] method ($this->cart->save()): a request, as the component's own. */
    public function isStoreServerCall(Expr $expression): bool
    {
        return $expression instanceof Expr\MethodCall && $expression->name instanceof Identifier && ($store = $this->storeOf($expression->var)) !== null
            && $this->context->scope->store($store)->isServerMethod($expression->name->toString());
    }

    /** The store in the browser: the component's property holding it. */
    private function storeBase(string $store): string
    {
        return '$this.'.$this->context->scope->browserProperty($store);
    }

    /** $this->cart->items (a property), $this->cart->count (#[Computed]). */
    private function storeMember(string $store, string $name, Node $expression): string
    {
        $scope = $this->context->scope->store($store);

        if ($scope->isComputed($name)) {
            return $this->storeBase($store).'.$computed('.$this->context->string($scope->browserMethod($name)).')';
        }

        if (! $scope->isProperty($name)) {
            throw $this->context->error("\${$store}->{$name} is not a public property of the store", $expression);
        }

        return $this->storeBase($store).'.'.$scope->browserProperty($name);
    }

    /** $this->cart->add(...): a method of the store, run in the browser (a #[Server] one only as a statement). */
    private function storeCall(string $store, Expr\MethodCall|Expr\NullsafeMethodCall $expression): string
    {
        $context = $this->context;

        if (! $expression->name instanceof Identifier) {
            throw $context->error('Dynamic method names are not supported in the browser', $expression);
        }

        $name = $expression->name->toString();
        $scope = $context->scope->store($store);

        if ($scope->isServerMethod($name)) {
            throw $context->error("\${$store}->{$name}() runs on the server: call it as a statement, its return value is not available in the browser", $expression);
        }

        if (! $scope->isClientMethod($name)) {
            throw $context->error("\${$store}->{$name}() is not a public method of the store", $expression);
        }

        return $this->storeBase($store).'.'.$scope->browserMethod($name).'('.implode(', ', $this->transpiler->calls->args($expression->args)).')';
    }

    /**
     * A component, for ->to() and $dispatchTo: Card::class (its name, from the application's
     * components) or its name.
     */
    public function component(mixed $arg, Node $node): string
    {
        $context = $this->context;

        if (! $arg instanceof Arg) {
            throw $context->error('->to() takes the component: Card::class or its name', $node);
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
            return $value instanceof Scalar\String_ ? $context->string($context->scope->browserComponent($value->value)) : $this->transpiler->expr($value);
        }

        $name = $context->scope->componentName($class) ?? throw $context->error("{$class} is not a Fusion component", $node);

        return $context->string($context->scope->browserComponent($name));
    }

    /**
     * The Fusion::browser() API an expression holds: Fusion::browser() itself, a call on one that
     * returns another (or itself: ->fillRect()->stroke()), or a variable assigned one. Null for
     * anything else.
     *
     * @return class-string|null
     */
    public function browserApi(Expr $expression): ?string
    {
        if ($expression instanceof Expr\StaticCall && $expression->class instanceof Name && $expression->name instanceof Identifier && $expression->name->toString() === 'browser'
            && in_array(ltrim(($expression->class->getAttribute('resolvedName') ?? $expression->class)->toString(), '\\'), Catalog::FUSION, true)) {
            return Browser::class;
        }

        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            return $this->context->browserVariables[$expression->name] ?? null;
        }

        if (($expression instanceof Expr\MethodCall || $expression instanceof Expr\NullsafeMethodCall) && $expression->name instanceof Identifier
            && ($api = $this->browserApi($expression->var)) !== null && method_exists($api, $expression->name->toString())) {
            $type = (new ReflectionMethod($api, $expression->name->toString()))->getReturnType();
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
        $context = $this->context;

        if ($args === []) {
            throw $context->error('dispatch() needs an event name', $node);
        }

        $name = $this->transpiler->expr(array_shift($args)->value);
        $named = [];
        $positional = [];

        foreach ($args as $arg) {
            if ($arg->unpack) {
                throw $context->error('Unpacking is not supported in dispatch()', $arg);
            }

            $arg->name !== null
                ? $named[] = '['.$context->string($arg->name->toString()).', '.$this->transpiler->expr($arg->value).', false]'
                : $positional[] = $this->transpiler->expr($arg->value);
        }

        if ($named !== [] && $positional !== []) {
            throw $context->error('dispatch() takes either named or positional parameters', $node);
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
     * Browser, one of js/functions/browser/_browser_*.js, in the bundle when called; on what that
     * returns, its method. Arguments left out get the PHP method's defaults.
     *
     * @param  class-string  $api
     */
    private function browserCall(Expr\MethodCall|Expr\NullsafeMethodCall $expression, string $api): string
    {
        $context = $this->context;
        $short = 'Fusion::browser()'.($api === Browser::class ? '' : '->'.lcfirst(class_basename($api)).'()');
        $methods = array_map(fn (ReflectionMethod $reflected) => $reflected->getName(), (new ReflectionClass($api))->getMethods(ReflectionMethod::IS_PUBLIC));
        $name = $expression->name instanceof Identifier ? $expression->name->toString() : throw $context->error('Dynamic method names are not supported in the browser', $expression);

        if (! in_array($name, $methods, true)) {
            throw $context->error("{$short}->{$name}() does not exist: it has ".implode('(), ', $methods).'()', $expression);
        }

        $method = new ReflectionMethod($api, $name);

        foreach ($expression->args as $arg) {
            if (! $arg instanceof Arg || $arg->name !== null || $arg->unpack) {
                throw $context->error("{$short}->{$name}() takes its arguments in order", $expression);
            }
        }

        [$required, $total] = [$method->getNumberOfRequiredParameters(), $method->getNumberOfParameters()];

        if (count($expression->args) < $required || count($expression->args) > $total) {
            $params = implode(', ', array_map(fn (ReflectionParameter $parameter) => '$'.$parameter->getName(), $method->getParameters()));
            $count = ($required === $total ? $total : "{$required} to {$total}").($total === 1 ? ' argument' : ' arguments');

            throw $context->error("{$short}->{$name}({$params}) takes {$count}", $expression);
        }

        $args = $this->transpiler->calls->args($expression->args);

        foreach (array_slice($method->getParameters(), count($expression->args)) as $param) {
            $args[] = $this->transpiler->expressions->literal($param->getDefaultValue(), $expression);
        }

        if ($api === Browser::class) {
            $context->uses['functions']['_browser_'.$name] = true;

            return 'P.fns._browser_'.$name.'('.implode(', ', [$this->transpiler->expr($expression->var), ...$args]).')';
        }

        return $this->transpiler->expr($expression->var).'.'.$name.'('.implode(', ', $args).')';
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
        $context = $this->context;
        $compiled = $this->transpiler->calls->args($args);

        if ($context->scope->names !== null && isset($args[0]) && $args[0] instanceof Arg && $args[0]->value instanceof Scalar\String_) {
            $compiled[0] = $context->string($context->scope->names->script($args[0]->value->value, $context->scope->properties, [...$context->scope->clientMethods, ...$context->scope->serverMethods]));
        }

        return $compiled;
    }
}
