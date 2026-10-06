<?php

namespace Nitro\Fusion\Compiler\Php;

use Nitro\Fusion\Compiler\Globals;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

/**
 * Event handlers and template expressions: Fusion's view code, not general PHP.
 *
 * An event handler (fusion:click="save", fusion:submit="add($title)") is a list of calls:
 *
 *  - a component method: "save" and "remove($id)" are $this->save() and $this->remove($id); a
 *    #[Server] method becomes a request to the server
 *  - $parent->method(): a method of the component around this one
 *  - the magic actions (Globals::ACTIONS): $set('prop.key', value), $toggle('prop'), $refresh,
 *    $reload('prop'), $loadMore('prop'), $dispatch('event'), $dispatchTo(Card::class, 'event'),
 *    $dispatchSelf('event'); a property named in them must be a public property, written out
 *  - a Fusion::browser() call (->storage()->forget('draft'))
 *
 * A template expression ({{ $total }}, @if ($open)) renders: forbidWrites() rejects assignments,
 * increments and #[Server] calls in it before it compiles.
 *
 * @internal
 */
final class Handlers extends Part
{
    /** One call of an event handler. */
    public function handlerCall(Expr $expression): string
    {
        $context = $this->context;
        $component = $this->transpiler->component;

        /** A bare name ("save") is a method with no arguments. */
        if ($expression instanceof Expr\ConstFetch) {
            $expression = new Expr\MethodCall(new Expr\Variable('this'), new Identifier($expression->name->toString()), [], $expression->getAttributes());
        }

        if ($expression instanceof Expr\FuncCall && $expression->name instanceof Expr\Variable && is_string($expression->name->name)) {
            return $this->magicAction($expression->name->name, $expression);
        }

        /** $parent->remove($id): a method of the component around this one (in the browser or on the server). */
        if ($expression instanceof Expr\MethodCall && $expression->var instanceof Expr\Variable && $expression->var->name === 'parent' && ! isset($context->locals['parent'])) {
            if (! $expression->name instanceof Identifier) {
                throw $context->error('$parent takes a method name', $expression);
            }

            return '$this.$parent('.$context->string($context->scope->browserMethod($expression->name->toString())).', ['.implode(', ', $this->transpiler->calls->args($expression->args)).'])';
        }

        /** Fusion::browser()->storage()->forget('draft'): a browser API, called from the view. */
        if (($expression instanceof Expr\MethodCall || $expression instanceof Expr\NullsafeMethodCall) && $component->browserApi($expression->var) !== null) {
            return $this->transpiler->expr($expression);
        }

        if ($expression instanceof Expr\Variable && $expression->name === 'refresh') {
            return '$this.$refresh()';
        }

        if ($expression instanceof Expr\Variable && $expression->name === 'reload') {
            return '$this.$reload([])';
        }

        if ($expression instanceof Expr\FuncCall && $expression->name instanceof Name && ! str_contains($expression->name->toString(), '\\')) {
            $expression = new Expr\MethodCall(new Expr\Variable('this'), new Identifier($expression->name->toString()), $expression->args, $expression->getAttributes());
        }

        /** $cart->remove($i): a method of a store the component uses (a #[Server] one, a request). */
        if ($expression instanceof Expr\MethodCall && $component->storeOf($expression->var) !== null) {
            return $component->isStoreServerCall($expression) ? $component->serverCall($expression) : $component->methodCall($expression);
        }

        if (! $expression instanceof Expr\MethodCall || ! $context->isThis($expression->var) || ! $expression->name instanceof Identifier) {
            throw $context->error('An event handler is a method call, such as "save" or "remove($id)"', $expression);
        }

        $name = $expression->name->toString();

        if ($context->scope->isServerMethod($name)) {
            return $component->serverCall($expression);
        }

        return $component->methodCall($expression);
    }

    /** A template expression renders: no assignments, no increments, no #[Server] calls. */
    public function forbidWrites(Expr $expr): void
    {
        $walk = function ($node) use (&$walk) {
            if (is_array($node)) {
                array_walk($node, $walk);

                return;
            }

            if (! $node instanceof Node || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
                return;
            }

            if ($node instanceof Expr\Assign || $node instanceof Expr\AssignOp || $node instanceof Expr\PreInc
                || $node instanceof Expr\PostInc || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec) {
                throw $this->context->error('A template expression cannot assign or increment: change state in a component method', $node);
            }

            if ($node instanceof Expr\MethodCall && $this->context->isThis($node->var) && $node->name instanceof Identifier
                && $this->context->scope->isServerMethod($node->name->toString())) {
                throw $this->context->error("\$this->{$node->name}() runs on the server and cannot be called while rendering", $node);
            }

            if ($node instanceof Expr\MethodCall && $this->transpiler->component->isStoreServerCall($node)) {
                throw $this->context->error("{$node->name}() is a store's #[Server] method: it runs on the server and cannot be called while rendering", $node);
            }

            foreach ($node->getSubNodeNames() as $sub) {
                $walk($node->$sub);
            }
        };

        $walk($expr);
    }

    /** $set, $toggle, $refresh, $reload, $loadMore, $dispatch (Globals::ACTIONS). */
    private function magicAction(string $name, Expr\FuncCall $expression): string
    {
        $context = $this->context;
        $component = $this->transpiler->component;

        if (! array_key_exists($name, Globals::ACTIONS)) {
            throw $context->error("Unknown action \${$name}: handlers can use \$".implode(', $', array_keys(Globals::ACTIONS)), $expression);
        }

        $arity = Globals::ACTIONS[$name];

        if ($arity !== null && count($expression->args) !== $arity) {
            throw $context->error("\${$name} takes {$arity} argument(s)", $expression);
        }

        /** The actions read their arguments by position; only the dispatches pass named parameters on. */
        if (! in_array($name, ['dispatch', 'dispatchTo', 'dispatchSelf'], true)) {
            foreach ($expression->args as $arg) {
                if (! $arg instanceof Arg || $arg->name !== null || $arg->unpack) {
                    throw $context->error("\${$name} takes its arguments in order: named arguments and ... are not accepted", $expression);
                }
            }
        }

        /** $dispatchTo('card', 'event', ...), $dispatchSelf('event', ...): Livewire's. */
        if ($name === 'dispatchTo') {
            $to = $component->component($expression->args[0] ?? null, $expression);

            return '$this.$dispatch('.$component->dispatchArgs(array_slice($expression->args, 1), $expression).').to('.$to.')';
        }

        if ($name === 'dispatchSelf') {
            return '$this.$dispatch('.$component->dispatchArgs($expression->args, $expression).').self()';
        }

        /** $visit(url): a fusion:navigate visit there (the runtime's $redirect, as $this->redirect() in a method). */
        if ($name === 'visit') {
            return '$this.$redirect('.implode(', ', $this->transpiler->calls->args($expression->args)).')';
        }

        $args = match ($name) {
            'set' => [$this->propertyPath($expression->args[0], $expression), $this->transpiler->expr($expression->args[1]->value)],
            'toggle' => [$this->propertyPath($expression->args[0], $expression)],
            'reload' => ['['.implode(', ', array_map(fn (Arg $argument) => $component->propertyName($argument, $expression, '$reload'), $expression->args)).']'],
            'loadMore' => [$component->propertyName($expression->args[0], $expression, '$loadMore')],
            'dispatch' => [$component->dispatchArgs($expression->args, $expression)],
            default => [],
        };

        return '$this.$'.$name.'('.implode(', ', $args).')';
    }

    /** 'prop' or 'prop.key.key' naming a public property (and keys under it). */
    private function propertyPath(Arg $arg, Node $node): string
    {
        if (! $arg->value instanceof Scalar\String_) {
            throw $this->context->error('The property must be a string literal', $node);
        }

        $root = explode('.', $arg->value->value)[0];

        if (! $this->context->scope->isProperty($root)) {
            throw $this->context->error("{$root} is not a public property of the component", $node);
        }

        return $this->context->string($this->context->scope->browserPath($arg->value->value));
    }
}
