<?php

namespace Nitro\Fusion\Compiler\Template\Parts;

use Nitro\Fusion\Compiler\Template\ChildTag;
use Nitro\Fusion\Compiler\Template\TemplateNode;

/**
 * Child components in a component's view: <fusion:name ... /> and <fusion:slot>. Fusion's
 * component model:
 *
 *  - a child tag is a component node ({t: "c"}) with its props as one expression, keyed as the
 *    server keys it (ChildTag: its number in the view, plus the indexes of the @foreach loops
 *    around it, or its :key); without a :key, the loops around it are marked positional, so the
 *    runtime renders an iteration again rather than moving it
 *  - fusion:model on a child binds its #[Modelable] property to this component's property, which
 *    must be public and not #[Locked]
 *  - <fusion:dynamic-component :is="$name">: the component :is names, worked out as it renders
 *  - a child's content is its default slot, and <fusion:slot name="footer"> a named one; slots
 *    are this view's nodes (this component's state, variables and handlers), rendered inside the
 *    child where its view puts {{ $slot }}
 *  - children belong in the component's own view, not in an inlined @include or Blade component
 *    (the server numbers them per view file); fusion:hydrate / fusion:static are for islands only
 *  - an obfuscated build names the child and its props by the browser's names
 *
 * @internal
 */
final class ChildComponents extends Part
{
    /**
     * <fusion:name ... />: a child component. It is keyed like on the server (ChildTag::key: its
     * number and the indexes of the @foreach loops around it, or its :key).
     */
    public function child(string $text, int $at): int
    {
        $context = $this->context;
        $table = $this->compiler->expressionTable;

        /** Children are numbered by the server per view file: inlined, the numbers would not match. */
        if ($context->frames !== []) {
            throw $context->error("<fusion:...> inside {$context->frames[count($context->frames) - 1]['label']}: put Fusion children in the component's own view, not in an included view or a Blade component");
        }

        $tag = ChildTag::parse($text, $at, $context->children++, $context->file, $context->line);

        if ($tag->hydrate !== null) {
            throw $context->error("fusion:hydrate and fusion:static are for islands, components placed in Blade views: <fusion:{$tag->name}> is a child of this component, and starts with it");
        }

        /**
         * Keyed by the indexes of the loops around it: in those loops an iteration is bound to its
         * position (the runtime renders it again when it moves, rather than moving it).
         */
        if ($tag->key === null) {
            foreach ($context->stack as $frame) {
                if (in_array($frame['kind'], ['foreach', 'forelse'], true)) {
                    $frame['node']->fields['i'][0]['positional'] = 1;
                }
            }
        }

        if ($tag->model !== null) {
            $root = $tag->modelPath[0];

            if (! $context->scope->isProperty($root) || $context->scope->isLocked($root)) {
                throw $context->error("fusion:model on <fusion:{$tag->name}> binds \"{$tag->model}\": {$root} must be a public property of this component that the browser can change");
            }
        }

        /** Livewire's <fusion:dynamic-component :is="$name">, Viewi's tag named by an expression: the component :is names. */
        $dynamic = $tag->name === 'dynamic-component';

        if ($dynamic && ! isset($tag->props['is'])) {
            throw $context->error('<fusion:dynamic-component> needs :is, the name of the component to render (as in <fusion:name>)');
        }

        $props = [];

        foreach ($tag->props as $name => $expression) {
            if ($dynamic && $name === 'is') {
                continue;
            }

            /** The child's properties by the browser's names for them (an obfuscated build); "$model" is the runtime's. */
            $props[] = json_encode($name === '$model' || $dynamic ? $name : $context->scope->names?->property($name) ?? $name).': '.$table->expr($expression);
        }

        $node = new TemplateNode(array_filter([
            't' => 'c',
            'c' => $dynamic ? null : $context->scope->browserComponent($tag->name),
            'is' => $dynamic ? $table->expression('P.toStr('.$table->expr($tag->props['is']).')') : null,
            'n' => $tag->number,
            'key' => $tag->key !== null ? $table->expression('P.toStr('.$table->expr($tag->key).')') : null,
            'props' => $table->expression('{'.implode(', ', $props).'}'),
            'model' => $tag->model === null ? null : $this->modelPath($tag->modelPath),
        ], fn ($value) => $value !== null));
        $this->compiler->tree->append($node);

        /**
         * <fusion:name> with content: its slots. They are this template's nodes (this component's
         * state, variables and handlers), rendered inside the child where its view puts $slot.
         */
        if (! $tag->selfClosing) {
            $node->slots['default'] = new TemplateNode(['t' => 'r']);
            $context->stack[] = ['kind' => 'child', 'node' => $node->slots['default'], 'child' => $node, 'tag' => $tag->name, 'line' => $context->line, 'locals' => $context->locals, 'sources' => $context->sources];
        }

        return $at + $tag->length;
    }

    /**
     * A child's fusion:model path in the browser: the path, or, with {{ }} segments, the index of
     * the expression that builds it as the view renders.
     *
     * @param  list<string|array{php: string}>  $segments
     */
    private function modelPath(array $segments): string|int
    {
        $path = $this->context->scope->browserProperty(array_shift($segments));
        $parts = [];

        foreach ($segments as $segment) {
            if (is_string($segment)) {
                $path .= '.'.$segment;

                continue;
            }

            $parts[] = json_encode($path.'.');
            $parts[] = 'P.toStr('.$this->compiler->expressionTable->expr($segment['php']).')';
            $path = '';
        }

        if ($parts === []) {
            return $path;
        }

        if ($path !== '') {
            $parts[] = json_encode($path);
        }

        return $this->compiler->expressionTable->expression(implode(' + ', $parts));
    }

    /** <fusion:slot name="footer">: a named slot of the child tag around it. */
    public function slotOpen(string $text, int $at): int
    {
        $context = $this->context;
        $this->compiler->tree->flushText();

        if (! preg_match(substr(ChildTag::SLOT_PATTERN, 0, 1).'\G'.substr(ChildTag::SLOT_PATTERN, 1), $text, $match, 0, $at)) {
            throw $context->error('<fusion:slot> needs a name: <fusion:slot name="footer">');
        }

        $top = end($context->stack);

        if ($top['kind'] !== 'child') {
            throw $context->error('<fusion:slot> belongs directly inside a <fusion:...> tag'.($top['kind'] === 'element' ? " (<{$top['tag']}> from line {$top['line']} is still open)" : ''));
        }

        $name = $match[1] !== '' ? $match[1] : $match[2];
        $slot = $top['child']->slots[$name] = new TemplateNode(['t' => 'r']);
        $context->stack[] = ['kind' => 'slot', 'node' => $slot, 'name' => $name, 'line' => $context->line, 'locals' => $context->locals, 'sources' => $context->sources];

        return $at + strlen($match[0]);
    }

    /** </fusion:slot> or </fusion:name>: the slot, or the child tag, ends. */
    public function childClose(string $text, int $at): int
    {
        $context = $this->context;
        $this->compiler->tree->flushText();

        if (! preg_match('/\G<\/fusion:([a-z0-9][a-z0-9\-.]*)\s*>/i', $text, $match, 0, $at)) {
            throw $context->error('Malformed </fusion:...> tag');
        }

        $name = strtolower($match[1]);
        $top = end($context->stack);
        $expected = $name === 'slot' ? 'slot' : 'child';

        if ($top['kind'] !== $expected || ($expected === 'child' && $top['tag'] !== $name)) {
            throw $context->error("</fusion:{$name}> closes no <fusion:{$name}>".match ($top['kind']) {
                'element' => " (<{$top['tag']}> from line {$top['line']} is still open)",
                'child' => " (<fusion:{$top['tag']}> from line {$top['line']} is open)",
                'slot' => " (<fusion:slot name=\"{$top['name']}\"> from line {$top['line']} is open)",
                'root' => '',
                default => " (@{$top['kind']} from line {$top['line']} is open)",
            });
        }

        array_pop($context->stack);
        $context->locals = $top['locals'];
        $context->sources = $top['sources'];

        return $at + strlen($match[0]);
    }
}
