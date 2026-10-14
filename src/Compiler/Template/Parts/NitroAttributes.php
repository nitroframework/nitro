<?php

namespace Nitro\Compiler\Template\Parts;

use Nitro\Compiler\Globals;
use Nitro\Support\Duration;
use Nitro\Support\Protocol;

/**
 * nitro: attributes on HTML elements: Nitro's own syntax, checked when the view compiles so a
 * mistake is an error naming the line rather than a silent no-op in the browser.
 *
 *  - events (nitro:click, nitro:submit.prevent, nitro:keydown.enter, ...) and hooks
 *    (nitro:poll.5s, nitro:init, nitro:visible.200px): the attribute stays as written (the
 *    server renders it so too) and the element's node carries its handler, compiled to an
 *    expression ({e: event, m: modifiers, code}); a handler is a method call, written without
 *    {{ }}; an obfuscated build leaves the handler's text out of the page
 *  - a bare nitro:poll refreshes the component
 *  - the other attributes (Globals::ATTRIBUTES: nitro:model, nitro:loading, nitro:transition,
 *    ...) pass through, read by the runtime from the element; their modifiers are checked
 *  - nitro:model must name a public property that is not #[Locked] (or a path under one,
 *    "items.{{ $i }}.name"); nitro:transition takes one mode and a time after .duration
 *
 * Every name and modifier comes from Globals, the table of what a component can use.
 *
 * @internal
 */
final class NitroAttributes extends Part
{
    /**
     * nitro:click.prevent="save": the attribute stays as written (the server renders it that way
     * too), and the node carries its handler (on: {e: event, m: modifiers, code}).
     */
    public function nitroAttribute(string $text, int $i, string $spec): int
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

            if ($name === 'prefetch') {
                $this->checkPrefetch($parts);
            }

            foreach (isset(Globals::ATTRIBUTE_MODIFIERS[$name]) ? $parts : [] as $modifier) {
                if (! in_array($modifier, Globals::ATTRIBUTE_MODIFIERS[$name], true)) {
                    throw $context->error(Globals::ATTRIBUTE_MODIFIERS[$name] === []
                        ? "nitro:{$name} takes no modifiers (.{$modifier})"
                        : "Unknown modifier .{$modifier} on nitro:{$name} (it takes .".implode(', .', Globals::ATTRIBUTE_MODIFIERS[$name]).')');
                }
            }

            if ($name === 'loading') {
                $this->checkLoading($parts);
            }

            if ($name === 'dirty' && ! in_array($parts, Globals::LOADING_FORMS, true)) {
                throw $context->error('nitro:dirty takes nothing or one of .remove, .class, .class.remove, .attr (not .'.implode('.', $parts).')');
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
         * The application's own (nitro.attributes): passed through as written, its value rendered
         * like any attribute's ({{ }} too), any modifiers; the element is marked for the runtime,
         * which runs the Nitro.directive() of that name on it.
         */
        if (in_array($name, $this->compiler->attributes, true)) {
            $this->compiler->runsAppScript = true;

            foreach ($parts as $modifier) {
                if (preg_match('/^[\w\-]+$/', $modifier) !== 1) {
                    throw $context->error("nitro:{$name}: a modifier is a word (nitro:{$name}.top, nitro:{$name}.300ms)");
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
            throw $context->error("Unknown attribute nitro:{$name}: use an event (nitro:click, nitro:submit, ...), nitro:poll, nitro:init, nitro:visible, one of nitro:".implode(', nitro:', Globals::ATTRIBUTES)
                .', or add it to nitro.attributes and give it a Nitro.directive()');
        }

        foreach ($parts as $modifier) {
            $known = $trigger === null
                ? Globals::isModifier($modifier) || Duration::is($modifier)
                : in_array($modifier, $trigger, true)
                    || ($name === 'poll' && Duration::is($modifier))
                    || ($name === 'visible' && preg_match('/^\d+px$/', $modifier) === 1);

            if (! $known) {
                throw $context->error("Unknown modifier .{$modifier} on nitro:{$name}");
            }
        }

        /** A bare nitro:poll refreshes the component. */
        if ($name === 'poll' && ! preg_match('/\Gnitro:[\w\-.]+\s*=/', $text, $_, 0, $i)) {
            $context->tag->attributes[] = ['c' => Protocol::namespace().$spec, 'on' => ['e' => 'poll', 'm' => $parts, 'code' => $table->handler('$refresh')]];

            return $i + strlen(Protocol::namespace().$spec);
        }

        if (! preg_match('/\Gnitro:[\w\-.]+\s*=\s*("([^"]*)"|\'([^\']*)\')/', $text, $match, 0, $i)) {
            /** An opening quote without its closing one here: Blade ({{ }}, @...) split the value. */
            throw $context->error(preg_match('/\Gnitro:[\w\-.]+\s*=\s*["\']/', $text, $open, 0, $i)
                ? "nitro:{$name} takes a method call such as \"remove(\$item['id'])\", without {{ }} or directives"
                : "nitro:{$name} needs a quoted handler, such as nitro:{$name}=\"save\"");
        }

        $code = $match[2] !== '' ? $match[2] : ($match[3] ?? '');

        if (str_contains($code, '{{') || trim($code) === '') {
            throw $context->error("nitro:{$name} takes a method call such as \"remove(\$item['id'])\", without {{ }}");
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
     * nitro:loading's modifiers: .delay (with a time: .delay.500ms) first, if any, then one of
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
            throw $this->context->error('nitro:loading takes .delay (.delay.500ms) first if it waits, then nothing or one of .remove, .class, .class.remove, .attr (not .'.implode('.', $modifiers).')');
        }
    }

    /**
     * nitro:transition's modifiers: Globals::TRANSITION_MODIFIERS, .duration followed by a time
     * (300ms, 1s), .origin by a side.
     *
     * @param  list<string>  $modifiers
     */
    private function checkTransition(array $modifiers): void
    {
        $context = $this->context;

        if (in_array('out-in', $modifiers, true) && in_array('in-out', $modifiers, true)) {
            throw $context->error('nitro:transition takes one mode: .out-in or .in-out');
        }

        for ($i = 0; $i < count($modifiers); $i++) {
            $modifier = $modifiers[$i];

            if (! in_array($modifier, Globals::TRANSITION_MODIFIERS, true)) {
                throw $context->error("Unknown modifier .{$modifier} on nitro:transition (it takes .".implode(', .', Globals::TRANSITION_MODIFIERS).')');
            }

            if ($modifier === 'duration' && ! Duration::is($modifiers[$i + 1] ?? null)) {
                throw $context->error('nitro:transition.duration needs a time: .duration.300ms or .duration.1s');
            }

            if ($modifier === 'origin' && ! in_array($modifiers[$i + 1] ?? '', ['top', 'bottom', 'left', 'right', 'center'], true)) {
                throw $context->error('nitro:transition.origin needs a side: .origin.top, .bottom, .left, .right or .center');
            }

            if ($modifier === 'duration' || $modifier === 'origin') {
                $i++;
            }
        }
    }

    /**
     * nitro:prefetch: on hover, or .visible; a time first or after .wait is the hover wait (none in
     * view), a time after .keep how long the page is kept.
     *
     * @param  list<string>  $modifiers
     */
    private function checkPrefetch(array $modifiers): void
    {
        $context = $this->context;
        $visible = in_array('visible', $modifiers, true);

        for ($i = 0; $i < count($modifiers); $i++) {
            $modifier = $modifiers[$i];
            $wait = ($i === 0 && Duration::is($modifier)) || $modifier === 'wait';

            if ($wait && $visible) {
                throw $context->error('nitro:prefetch.visible has no wait: it asks for the page once the link is in view (.keep.60s sets how long it is kept)');
            }

            if ($i === 0 && Duration::is($modifier)) {
                continue;
            }

            if (! in_array($modifier, Globals::PREFETCH_MODIFIERS, true)) {
                throw $context->error("Unknown modifier .{$modifier} on nitro:prefetch (it takes .".implode(', .', Globals::PREFETCH_MODIFIERS).', or a time first: .150ms)');
            }

            if (($modifier === 'wait' || $modifier === 'keep') && ! Duration::is($modifiers[$i + 1] ?? null)) {
                throw $context->error("nitro:prefetch.{$modifier} needs a time: .{$modifier}.150ms or .{$modifier}.60s");
            }

            if ($modifier === 'wait' || $modifier === 'keep') {
                $i++;
            }
        }
    }

    /** nitro:model="prop" or "prop.key": a public property that is not #[Locked]. */
    private function checkModel(string $text, int $i, array $modifiers): void
    {
        $context = $this->context;

        foreach ($modifiers as $modifier) {
            if (! in_array($modifier, Globals::MODEL_MODIFIERS, true) && ! Duration::is($modifier)) {
                throw $context->error("Unknown modifier .{$modifier} on nitro:model");
            }
        }

        if (preg_match('/\Gnitro:[\w\-.]+\s*=\s*("([^"]*)"|\'([^\']*)\')/', $text, $match, 0, $i)) {
            $path = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
            $valid = preg_match('/^[A-Za-z_]\w*(\.[\w\-]+)*$/', $path) === 1;
        } elseif (preg_match('/\Gnitro:[\w\-.]+\s*=\s*["\']([A-Za-z_]\w*\.[\w\-.]*)$/', $text, $match, 0, $i)) {
            /** A path built with Blade (nitro:model="items.{{ $i }}.name"): the root is fixed, the rest rendered. */
            $path = $match[1];
            $valid = true;
        } else {
            throw $context->error('nitro:model needs a property name, such as nitro:model="email" or "items.{{ $i }}.name"');
        }

        $root = explode('.', $path)[0];

        if (! $valid || ! $context->scope->isProperty($root)) {
            throw $context->error("nitro:model=\"{$path}\": {$root} is not a public property of the component");
        }

        if ($context->scope->isLocked($root)) {
            throw $context->error("nitro:model=\"{$path}\": {$root} is #[Locked], so the browser cannot change it");
        }
    }
}
