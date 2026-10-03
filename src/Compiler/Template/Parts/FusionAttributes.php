<?php

namespace Nitro\Fusion\Compiler\Template\Parts;

use Nitro\Fusion\Compiler\Globals;

/**
 * fusion: attributes on HTML elements: Fusion's own syntax, checked when the view compiles so a
 * mistake is an error naming the line rather than a silent no-op in the browser.
 *
 *  - events (fusion:click, fusion:submit.prevent, fusion:keydown.enter, ...) and hooks
 *    (fusion:poll.5s, fusion:init, fusion:visible.200px): the attribute stays as written (the
 *    server renders it so too) and the element's node carries its handler, compiled to an
 *    expression ({e: event, m: modifiers, code}); a handler is a method call, written without
 *    {{ }}; an obfuscated build leaves the handler's text out of the page
 *  - a bare fusion:poll refreshes the component
 *  - the other attributes (Globals::ATTRIBUTES: fusion:model, fusion:loading, fusion:transition,
 *    ...) pass through, read by the runtime from the element; their modifiers are checked
 *  - fusion:model must name a public property that is not #[Locked] (or a path under one,
 *    "items.{{ $i }}.name"); fusion:transition takes one mode and a time after .duration
 *
 * Every name and modifier comes from Globals, the table of what a component can use.
 *
 * @internal
 */
final class FusionAttributes extends Part
{
    /**
     * fusion:click.prevent="save": the attribute stays as written (the server renders it that way
     * too), and the node carries its handler (on: {e: event, m: modifiers, code}).
     */
    public function fusionAttribute(string $text, int $i, string $spec): int
    {
        $c = $this->context;
        $table = $this->compiler->expressionTable;
        $parts = explode('.', $spec);
        $name = array_shift($parts);

        if (Globals::isAttribute($name)) {
            if ($name === 'model') {
                $this->checkModel($text, $i, $parts);
            }

            if ($name === 'transition') {
                $this->checkTransition($parts);
            }

            foreach (isset(Globals::ATTRIBUTE_MODIFIERS[$name]) ? $parts : [] as $modifier) {
                if (! in_array($modifier, Globals::ATTRIBUTE_MODIFIERS[$name], true)) {
                    throw $c->error("Unknown modifier .{$modifier} on fusion:{$name} (it takes .".implode(', .', Globals::ATTRIBUTE_MODIFIERS[$name]).')');
                }
            }

            /** Passed through: the runtime reads it from the element (its value may contain {{ }}). */
            $i += strlen('fusion:'.$spec);

            if (preg_match('/\G\s*=\s*(["\'])/', $text, $open, 0, $i)) {
                return $this->compiler->html->openValue('fusion:'.$spec, $open[1], $i + strlen($open[0]));
            }

            $c->tag->attributes[] = ['c' => 'fusion:'.$spec];

            return $i;
        }

        $hook = Globals::HOOKS[$name] ?? null;

        if ($hook === null && ! Globals::isEvent($name)) {
            throw $c->error("Unknown attribute fusion:{$name}: use an event (fusion:click, fusion:submit, ...), fusion:poll, fusion:init, fusion:visible or one of fusion:".implode(', fusion:', Globals::ATTRIBUTES));
        }

        foreach ($parts as $modifier) {
            $known = $hook === null
                ? in_array($modifier, Globals::MODIFIERS, true) || preg_match('/^\d+m?s$/', $modifier) === 1
                : in_array($modifier, $hook, true)
                    || ($name === 'poll' && preg_match('/^\d+m?s$/', $modifier) === 1)
                    || ($name === 'visible' && preg_match('/^\d+px$/', $modifier) === 1);

            if (! $known) {
                throw $c->error("Unknown modifier .{$modifier} on fusion:{$name}");
            }
        }

        /** A bare fusion:poll refreshes the component. */
        if ($name === 'poll' && ! preg_match('/\Gfusion:[\w\-.]+\s*=/', $text, $_, 0, $i)) {
            $c->tag->attributes[] = ['c' => 'fusion:'.$spec, 'on' => ['e' => 'poll', 'm' => $parts, 'code' => $table->handler('$refresh')]];

            return $i + strlen('fusion:'.$spec);
        }

        if (! preg_match('/\Gfusion:[\w\-.]+\s*=\s*("([^"]*)"|\'([^\']*)\')/', $text, $m, 0, $i)) {
            /** An opening quote without its closing one here: Blade ({{ }}, @...) split the value. */
            throw $c->error(preg_match('/\Gfusion:[\w\-.]+\s*=\s*["\']/', $text, $open, 0, $i)
                ? "fusion:{$name} takes a method call such as \"remove(\$item['id'])\", without {{ }} or directives"
                : "fusion:{$name} needs a quoted handler, such as fusion:{$name}=\"save\"");
        }

        $code = $m[2] !== '' ? $m[2] : ($m[3] ?? '');

        if (str_contains($code, '{{') || trim($code) === '') {
            throw $c->error("fusion:{$name} takes a method call such as \"remove(\$item['id'])\", without {{ }}");
        }

        $decoded = html_entity_decode($code, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $c->tag->attributes[] = [
            'c' => 'fusion:'.$spec,
            /** An obfuscated build leaves the handler's text out of the page: the compiled code is what runs. */
            'h' => $c->scope->names === null ? [['c' => $decoded]] : [],
            'on' => ['e' => $name, 'm' => $parts, 'code' => $table->handler($decoded)],
        ];

        return $i + strlen($m[0]);
    }

    /**
     * fusion:transition's modifiers: Globals::TRANSITION_MODIFIERS, .duration followed by a time
     * (300ms, 1s), .origin by a side.
     *
     * @param  list<string>  $modifiers
     */
    private function checkTransition(array $modifiers): void
    {
        $c = $this->context;

        if (in_array('out-in', $modifiers, true) && in_array('in-out', $modifiers, true)) {
            throw $c->error('fusion:transition takes one mode: .out-in or .in-out');
        }

        for ($i = 0; $i < count($modifiers); $i++) {
            $modifier = $modifiers[$i];

            if (! in_array($modifier, Globals::TRANSITION_MODIFIERS, true)) {
                throw $c->error("Unknown modifier .{$modifier} on fusion:transition (it takes .".implode(', .', Globals::TRANSITION_MODIFIERS).')');
            }

            if ($modifier === 'duration' && preg_match('/^\d+m?s$/', $modifiers[$i + 1] ?? '') !== 1) {
                throw $c->error('fusion:transition.duration needs a time: .duration.300ms or .duration.1s');
            }

            if ($modifier === 'origin' && ! in_array($modifiers[$i + 1] ?? '', ['top', 'bottom', 'left', 'right', 'center'], true)) {
                throw $c->error('fusion:transition.origin needs a side: .origin.top, .bottom, .left, .right or .center');
            }

            if ($modifier === 'duration' || $modifier === 'origin') {
                $i++;
            }
        }
    }

    /** fusion:model="prop" or "prop.key": a public property that is not #[Locked]. */
    private function checkModel(string $text, int $i, array $modifiers): void
    {
        $c = $this->context;

        foreach ($modifiers as $modifier) {
            if (! in_array($modifier, ['live', 'blur', 'change', 'lazy', 'debounce', 'number', 'trim', 'boolean'], true) && ! preg_match('/^\d+m?s$/', $modifier)) {
                throw $c->error("Unknown modifier .{$modifier} on fusion:model");
            }
        }

        if (preg_match('/\Gfusion:[\w\-.]+\s*=\s*("([^"]*)"|\'([^\']*)\')/', $text, $m, 0, $i)) {
            $path = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
            $valid = preg_match('/^[A-Za-z_]\w*(\.[\w\-]+)*$/', $path) === 1;
        } elseif (preg_match('/\Gfusion:[\w\-.]+\s*=\s*["\']([A-Za-z_]\w*\.[\w\-.]*)$/', $text, $m, 0, $i)) {
            /** A path built with Blade (fusion:model="items.{{ $i }}.name"): the root is fixed, the rest rendered. */
            $path = $m[1];
            $valid = true;
        } else {
            throw $c->error('fusion:model needs a property name, such as fusion:model="email" or "items.{{ $i }}.name"');
        }

        $root = explode('.', $path)[0];

        if (! $valid || ! $c->scope->isProperty($root)) {
            throw $c->error("fusion:model=\"{$path}\": {$root} is not a public property of the component");
        }

        if ($c->scope->isLocked($root)) {
            throw $c->error("fusion:model=\"{$path}\": {$root} is #[Locked], so the browser cannot change it");
        }
    }
}
