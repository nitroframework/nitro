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
    public function handlerCall(Expr $e): string
    {
        $c = $this->context;
        $component = $this->transpiler->component;

        /** A bare name ("save") is a method with no arguments. */
        if ($e instanceof Expr\ConstFetch) {
            $e = new Expr\MethodCall(new Expr\Variable('this'), new Identifier($e->name->toString()), [], $e->getAttributes());
        }

        if ($e instanceof Expr\FuncCall && $e->name instanceof Expr\Variable && is_string($e->name->name)) {
            return $this->magicAction($e->name->name, $e);
        }

        /** $parent->remove($id): a method of the component around this one (in the browser or on the server). */
        if ($e instanceof Expr\MethodCall && $e->var instanceof Expr\Variable && $e->var->name === 'parent' && ! isset($c->locals['parent'])) {
            if (! $e->name instanceof Identifier) {
                throw $c->error('$parent takes a method name', $e);
            }

            return '$this.$parent('.$c->string($c->scope->browserMethod($e->name->toString())).', ['.implode(', ', $this->transpiler->calls->args($e->args)).'])';
        }

        /** Fusion::browser()->storage()->forget('draft'): a browser API, called from the view. */
        if (($e instanceof Expr\MethodCall || $e instanceof Expr\NullsafeMethodCall) && $component->browserApi($e->var) !== null) {
            return $this->transpiler->expr($e);
        }

        if ($e instanceof Expr\Variable && $e->name === 'refresh') {
            return '$this.$refresh()';
        }

        if ($e instanceof Expr\Variable && $e->name === 'reload') {
            return '$this.$reload([])';
        }

        if ($e instanceof Expr\FuncCall && $e->name instanceof Name && ! str_contains($e->name->toString(), '\\')) {
            $e = new Expr\MethodCall(new Expr\Variable('this'), new Identifier($e->name->toString()), $e->args, $e->getAttributes());
        }

        if (! $e instanceof Expr\MethodCall || ! $c->isThis($e->var) || ! $e->name instanceof Identifier) {
            throw $c->error('An event handler is a method call, such as "save" or "remove($id)"', $e);
        }

        $name = $e->name->toString();

        if ($c->scope->isServerMethod($name)) {
            return $component->serverCall($e);
        }

        return $component->methodCall($e);
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

            foreach ($node->getSubNodeNames() as $sub) {
                $walk($node->$sub);
            }
        };

        $walk($expr);
    }

    /** $set, $toggle, $refresh, $reload, $loadMore, $dispatch (Globals::ACTIONS). */
    private function magicAction(string $name, Expr\FuncCall $e): string
    {
        $c = $this->context;
        $component = $this->transpiler->component;

        if (! array_key_exists($name, Globals::ACTIONS)) {
            throw $c->error("Unknown action \${$name}: handlers can use \$".implode(', $', array_keys(Globals::ACTIONS)), $e);
        }

        $arity = Globals::ACTIONS[$name];

        if ($arity !== null && count($e->args) !== $arity) {
            throw $c->error("\${$name} takes {$arity} argument(s)", $e);
        }

        /** $dispatchTo('card', 'event', ...), $dispatchSelf('event', ...): Livewire's. */
        if ($name === 'dispatchTo') {
            $to = $component->component($e->args[0] ?? null, $e);

            return '$this.$dispatch('.$component->dispatchArgs(array_slice($e->args, 1), $e).').to('.$to.')';
        }

        if ($name === 'dispatchSelf') {
            return '$this.$dispatch('.$component->dispatchArgs($e->args, $e).').self()';
        }

        /** $visit(url): a fusion:navigate visit there (the runtime's $redirect, as $this->redirect() in a method). */
        if ($name === 'visit') {
            return '$this.$redirect('.implode(', ', $this->transpiler->calls->args($e->args)).')';
        }

        $args = match ($name) {
            'set' => [$this->propertyPath($e->args[0], $e), $this->transpiler->expr($e->args[1]->value)],
            'toggle' => [$this->propertyPath($e->args[0], $e)],
            'reload' => ['['.implode(', ', array_map(fn (Arg $a) => $component->propertyName($a, $e, '$reload'), $e->args)).']'],
            'loadMore' => [$component->propertyName($e->args[0], $e, '$loadMore')],
            'dispatch' => [$component->dispatchArgs($e->args, $e)],
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
