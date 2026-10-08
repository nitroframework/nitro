<?php

namespace Nitro\Fusion\Compiler\Template\Parts;

use Nitro\Fusion\Compiler\Globals;
use Nitro\Fusion\Support\Duration;
use Nitro\Fusion\Support\Protocol;

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
        $context = $this->context;
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

            foreach (isset(Globals::ATTRIBUTE_MODIFIERS[$name]) ? $parts : [] as $index => $modifier) {
                /** fusion:navigate.prefetch.60s: how long the prefetched page is kept. */
                if ($name === 'navigate' && ($parts[$index - 1] ?? null) === 'prefetch' && Duration::is($modifier)) {
                    continue;
                }

                if (! in_array($modifier, Globals::ATTRIBUTE_MODIFIERS[$name], true)) {
                    throw $context->error(Globals::ATTRIBUTE_MODIFIERS[$name] === []
                        ? "fusion:{$name} takes no modifiers (.{$modifier})"
                        : "Unknown modifier .{$modifier} on fusion:{$name} (it takes .".implode(', .', Globals::ATTRIBUTE_MODIFIERS[$name]).')');
                }
            }

            if ($name === 'loading') {
                $this->checkLoading($parts);
            }

            if ($name === 'dirty' && ! in_array($parts, Globals::LOADING_FORMS, true)) {
                throw $context->error('fusion:dirty takes nothing or one of .remove, .class, .class.remove, .attr (not .'.implode('.', $parts).')');
            }

            /** Passed through: the runtime reads it from the element (its value may contain {{ }}). */
            $i += strlen(Protocol::namespace().$spec);

            if (preg_match('/\G\s*=\s*(["\'])/', $text, $open, 0, $i)) {
                return $this->compiler->html->openValue(Protocol::namespace().$spec, $open[1], $i + strlen($open[0]));
            }

            $context->tag->attributes[] = ['c' => Protocol::namespace().$spec];

            return $i;
        }

        /**
         * The application's own (fusion.attributes): passed through as written, its value rendered
         * like any attribute's ({{ }} too), any modifiers; the element is marked for the runtime,
         * which runs the Fusion.directive() of that name on it.
         */
        if (in_array($name, $this->compiler->attributes, true)) {
            foreach ($parts as $modifier) {
                if (preg_match('/^[\w\-]+$/', $modifier) !== 1) {
                    throw $context->error("fusion:{$name}: a modifier is a word (fusion:{$name}.top, fusion:{$name}.300ms)");
                }
            }

            $context->tag->attributes[] = ['c' => Protocol::attribute('directive')];
            $i += strlen(Protocol::namespace().$spec);

            if (preg_match('/\G\s*=\s*(["\'])/', $text, $open, 0, $i)) {
                return $this->compiler->html->openValue(Protocol::namespace().$spec, $open[1], $i + strlen($open[0]));
            }

            $context->tag->attributes[] = ['c' => Protocol::namespace().$spec];

            return $i;
        }

        $trigger = Globals::TRIGGERS[$name] ?? null;

        if ($trigger === null && ! Globals::isEvent($name)) {
            throw $context->error("Unknown attribute fusion:{$name}: use an event (fusion:click, fusion:submit, ...), fusion:poll, fusion:init, fusion:visible, one of fusion:".implode(', fusion:', Globals::ATTRIBUTES)
                .', or add it to fusion.attributes and give it a Fusion.directive()');
        }

        foreach ($parts as $modifier) {
            $known = $trigger === null
                ? in_array($modifier, Globals::MODIFIERS, true) || Duration::is($modifier)
                : in_array($modifier, $trigger, true)
                    || ($name === 'poll' && Duration::is($modifier))
                    || ($name === 'visible' && preg_match('/^\d+px$/', $modifier) === 1);

            if (! $known) {
                throw $context->error("Unknown modifier .{$modifier} on fusion:{$name}");
            }
        }

        /** A bare fusion:poll refreshes the component. */
        if ($name === 'poll' && ! preg_match('/\Gfusion:[\w\-.]+\s*=/', $text, $_, 0, $i)) {
            $context->tag->attributes[] = ['c' => Protocol::namespace().$spec, 'on' => ['e' => 'poll', 'm' => $parts, 'code' => $table->handler('$refresh')]];

            return $i + strlen(Protocol::namespace().$spec);
        }

        if (! preg_match('/\Gfusion:[\w\-.]+\s*=\s*("([^"]*)"|\'([^\']*)\')/', $text, $match, 0, $i)) {
            /** An opening quote without its closing one here: Blade ({{ }}, @...) split the value. */
            throw $context->error(preg_match('/\Gfusion:[\w\-.]+\s*=\s*["\']/', $text, $open, 0, $i)
                ? "fusion:{$name} takes a method call such as \"remove(\$item['id'])\", without {{ }} or directives"
                : "fusion:{$name} needs a quoted handler, such as fusion:{$name}=\"save\"");
        }

        $code = $match[2] !== '' ? $match[2] : ($match[3] ?? '');

        if (str_contains($code, '{{') || trim($code) === '') {
            throw $context->error("fusion:{$name} takes a method call such as \"remove(\$item['id'])\", without {{ }}");
        }

        $decoded = html_entity_decode($code, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $context->tag->attributes[] = [
            'c' => Protocol::namespace().$spec,
            /** An obfuscated build leaves the handler's text out of the page: the compiled code is what runs. */
            'h' => $context->scope->names === null ? [['c' => $decoded]] : [],
            'on' => ['e' => $name, 'm' => $parts, 'code' => $table->handler($decoded)],
        ];

        return $i + strlen($match[0]);
    }

    /**
     * fusion:transition's modifiers: Globals::TRANSITION_MODIFIERS, .duration followed by a time
     * (300ms, 1s), .origin by a side.
     *
     * @param  list<string>  $modifiers
     */
    /**
     * fusion:loading's modifiers: .delay (with a time: .delay.500ms) first, if any, then one of
     * Globals::LOADING_FORMS.
     *
     * @param  list<string>  $modifiers
     */
    private function checkLoading(array $modifiers): void
    {
        $form = $modifiers;

        if (($form[0] ?? null) === 'delay') {
            array_shift($form);

            if (Duration::is($form[0] ?? null)) {
                array_shift($form);
            }
        }

        if (! in_array($form, Globals::LOADING_FORMS, true)) {
            throw $this->context->error('fusion:loading takes .delay (.delay.500ms) first if it waits, then nothing or one of .remove, .class, .class.remove, .attr (not .'.implode('.', $modifiers).')');
        }
    }

    private function checkTransition(array $modifiers): void
    {
        $context = $this->context;

        if (in_array('out-in', $modifiers, true) && in_array('in-out', $modifiers, true)) {
            throw $context->error('fusion:transition takes one mode: .out-in or .in-out');
        }

        for ($i = 0; $i < count($modifiers); $i++) {
            $modifier = $modifiers[$i];

            if (! in_array($modifier, Globals::TRANSITION_MODIFIERS, true)) {
                throw $context->error("Unknown modifier .{$modifier} on fusion:transition (it takes .".implode(', .', Globals::TRANSITION_MODIFIERS).')');
            }

            if ($modifier === 'duration' && ! Duration::is($modifiers[$i + 1] ?? null)) {
                throw $context->error('fusion:transition.duration needs a time: .duration.300ms or .duration.1s');
            }

            if ($modifier === 'origin' && ! in_array($modifiers[$i + 1] ?? '', ['top', 'bottom', 'left', 'right', 'center'], true)) {
                throw $context->error('fusion:transition.origin needs a side: .origin.top, .bottom, .left, .right or .center');
            }

            if ($modifier === 'duration' || $modifier === 'origin') {
                $i++;
            }
        }
    }

    /** fusion:model="prop" or "prop.key": a public property that is not #[Locked]. */
    private function checkModel(string $text, int $i, array $modifiers): void
    {
        $context = $this->context;

        foreach ($modifiers as $modifier) {
            if (! in_array($modifier, Globals::MODEL_MODIFIERS, true) && ! Duration::is($modifier)) {
                throw $context->error("Unknown modifier .{$modifier} on fusion:model");
            }
        }

        if (preg_match('/\Gfusion:[\w\-.]+\s*=\s*("([^"]*)"|\'([^\']*)\')/', $text, $match, 0, $i)) {
            $path = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
            $valid = preg_match('/^[A-Za-z_]\w*(\.[\w\-]+)*$/', $path) === 1;
        } elseif (preg_match('/\Gfusion:[\w\-.]+\s*=\s*["\']([A-Za-z_]\w*\.[\w\-.]*)$/', $text, $match, 0, $i)) {
            /** A path built with Blade (fusion:model="items.{{ $i }}.name"): the root is fixed, the rest rendered. */
            $path = $match[1];
            $valid = true;
        } else {
            throw $context->error('fusion:model needs a property name, such as fusion:model="email" or "items.{{ $i }}.name"');
        }

        $root = explode('.', $path)[0];

        if (! $valid || ! $context->scope->isProperty($root)) {
            throw $context->error("fusion:model=\"{$path}\": {$root} is not a public property of the component");
        }

        if ($context->scope->isLocked($root)) {
            throw $context->error("fusion:model=\"{$path}\": {$root} is #[Locked], so the browser cannot change it");
        }
    }
}
