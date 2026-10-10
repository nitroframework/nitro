<?php

namespace Nitro\Compiler;

/**
 * Everything a component can use that it does not declare itself, in one place: the variables
 * templates and handlers see, the actions handlers can call, the component methods Nitro
 * provides, and the nitro: attributes. The compiler reads these tables; js/src/runtime
 * implements each entry.
 */
final class Globals
{
    /**
     * Nitro's own Blade directives a component's view may use: each is registered with Blade for
     * the server's render (NitroServiceProvider) and compiled for the browser (Template\Parts\Directives).
     */
    public const DIRECTIVES = ['teleport', 'endteleport', 'deferred', 'placeholder', 'enddeferred'];

    /**
     * @nitroScript ... @endNitroScript: a component's own JavaScript, run once for each instance as
     * it goes live. Read whole (never Blade, never HTML) by the template's Lexer; never rendered on
     * the server (NitroServiceProvider takes it out before Blade compiles the view).
     */
    public const SCRIPT = ['nitroScript', 'endNitroScript'];

    /**
     * Variables every template can read, and what they compile to.
     *   $errors  the component's validation errors (from its last #[Server] call)
     */
    public const TEMPLATE_VARIABLES = [
        'errors' => '$this.$errors',
    ];

    /**
     * Laravel's helpers, in templates and browser methods (Viewi's #[GlobalEntry], the Laravel
     * way). What they need ships with the page, only for what the compiled code names: route
     * names and translation keys as literals, config keys listed in nitro.public_config.
     */
    public const HELPERS = ['route', 'url', 'asset', '__', 'trans', 'config'];

    /** Methods of $errors, as on Laravel's MessageBag. */
    public const ERROR_METHODS = ['has', 'first', 'get', 'all', 'any', 'count'];

    /** Methods of an Upload (a file a nitro:model file input uploaded) the browser has: what it shows of the file. */
    public const UPLOAD_METHODS = ['temporaryUrl', 'getClientOriginalName', 'getClientOriginalExtension', 'getSize', 'getMimeType'];

    /** Variables a handler (nitro:click="...") can read besides the template's. */
    public const HANDLER_VARIABLES = ['event'];

    /**
     * Actions a handler can call without a method on the component, and their arity.
     *   $set('prop', value)  $toggle('prop')  $refresh  $reload('prop', ...)  $loadMore('prop')  $dispatch('event', ...)
     *   $visit(url): go there, as a nitro:navigate link does
     */
    public const ACTIONS = [
        'set' => 2,
        'toggle' => 1,
        'refresh' => 0,
        'reload' => null,
        'loadMore' => 1,
        'dispatch' => null,
        'dispatchTo' => null,
        'dispatchSelf' => null,
        /** $visit(url): a nitro:navigate visit, from any handler (search on Enter). */
        'visit' => 1,
    ];

    /**
     * Methods Nitro gives every component, usable from browser code:
     *   $this->dispatch('event', ...$params)  $this->reset('prop', ...)  $this->redirect($url)
     *   $this->js('$refs.search.focus()')  $this->shared('auth.user.name', $default)  $this->reload('orders')
     *   $this->flashed('toast')  $this->hasMore('posts')
     */
    public const COMPONENT_METHODS = ['dispatch', 'reset', 'redirect', 'js', 'shared', 'flashed', 'reload', 'hasMore'];

    /** nitro: attributes that are not events (the runtime reads them from the DOM). */
    public const ATTRIBUTES = ['model', 'key', 'loading', 'dirty', 'target', 'navigate', 'prefetch', 'current', 'ignore', 'confirm', 'ref', 'transition'];

    /**
     * nitro:prefetch, on a nitro:navigate link: its page asked for ahead of the click, once the
     * pointer has rested on it (75ms; nitro:prefetch.150ms or .wait.150ms: that long), or with
     * .visible once it comes into view; kept 30 seconds (.keep.60s: that long).
     */
    public const PREFETCH_MODIFIERS = ['visible', 'wait', 'keep'];

    /**
     * nitro:transition's modifiers, as Vue's <Transition> has them: .appear (on the first render
     * too), .in / .out (entering or leaving only); for the built-in effect, .opacity / .scale (one
     * of them only), .duration.300ms, .origin.top (bottom, left, right, center); .out-in / .in-out
     * (Vue's modes) and .move (Vue's <TransitionGroup>: rows slide to their new places).
     */
    /**
     * nitro:loading: shown while loading, or .remove (hidden), .class / .class.remove (classes
     * added or removed), .attr (an attribute set). Any of them can wait first: .delay (200ms),
     * .delay.500ms, .delay.1s. nitro:dirty (changes the server has not seen) takes the same
     * forms, without .delay.
     */
    public const LOADING_FORMS = [[], ['remove'], ['class'], ['class', 'remove'], ['attr']];

    public const TRANSITION_MODIFIERS = ['appear', 'in', 'out', 'opacity', 'scale', 'duration', 'origin', 'out-in', 'in-out', 'move'];

    /** nitro:model's modifiers (a time after .debounce besides). */
    public const MODEL_MODIFIERS = ['live', 'blur', 'change', 'lazy', 'debounce', 'number', 'trim', 'boolean'];

    /**
     * The modifiers these attributes take (checked at build time, like event modifiers):
     * nitro:navigate.keypress starts the visit on mouse down, nitro:navigate.preserve-scroll
     * keeps the scroll position, nitro:navigate.transition changes the page in a View Transition,
     * nitro:current.exact matches the path only, nitro:confirm.prompt asks for a word to be typed.
     * nitro:loading's, nitro:model's, nitro:transition's and nitro:prefetch's are checked by their
     * own rules (LOADING_FORMS, PREFETCH_MODIFIERS); the others take none.
     */
    public const ATTRIBUTE_MODIFIERS = [
        'navigate' => ['keypress', 'preserve-scroll', 'transition'],
        'current' => ['exact'],
        'confirm' => ['prompt'],
        'key' => [],
        'target' => [],
        'ignore' => [],
        'ref' => [],
    ];

    /**
     * Triggers: handlers that no DOM event starts, compiled like nitro:<event> (with the modifiers each
     * takes): nitro:poll.5s="refreshStats" calls it every interval (a bare nitro:poll
     * refreshes), nitro:init="load" once when its element appears, nitro:visible="loadMore"
     * when it comes into view (.once: the first time only; .200px: that far before).
     */
    public const TRIGGERS = [
        'poll' => ['visible', 'keep-alive'],
        'init' => [],
        'visible' => ['once'],
    ];

    /** DOM events usable as nitro:<event>. */
    public const EVENTS = [
        'click', 'dblclick', 'submit', 'input', 'change', 'keydown', 'keyup', 'keypress', 'focus', 'blur',
        'focusin', 'focusout', 'mouseenter', 'mouseleave', 'mouseover', 'mouseout', 'mousedown', 'mouseup',
        'contextmenu', 'scroll', 'wheel', 'pointerdown', 'pointerup', 'touchstart', 'touchend', 'drop',
        'dragstart', 'dragover', 'dragend', 'paste', 'copy', 'cut', 'select', 'reset', 'toggle',
    ];

    /**
     * Event modifiers (nitro:submit.prevent, nitro:keydown.enter, nitro:click.debounce.300ms); a
     * single letter or digit is a key too (nitro:keydown.ctrl.k, nitro:keydown.1): isModifier().
     */
    public const MODIFIERS = [
        'prevent', 'stop', 'self', 'once', 'window', 'document', 'outside', 'passive', 'capture',
        'enter', 'escape', 'esc', 'space', 'tab', 'up', 'down', 'left', 'right', 'delete', 'backspace',
        'shift', 'ctrl', 'alt', 'meta', 'debounce', 'throttle',
    ];

    /** nitro: names the runtime puts on elements itself (and nitro:h-..., its handlers' marks). */
    public const RUNTIME = ['id', 'name', 'page', 'placeholder', 'hydrate', 'static', 'fallback'];

    /** An event modifier: one of MODIFIERS, or a key written as its letter or digit (.k, .1). */
    public static function isModifier(string $modifier): bool
    {
        return in_array($modifier, self::MODIFIERS, true) || preg_match('/^[a-z0-9]$/', $modifier) === 1;
    }

    public static function isAttribute(string $name): bool
    {
        return in_array($name, self::ATTRIBUTES, true);
    }

    /**
     * The application's own nitro: attributes (nitro.attributes): lowercase names, words joined
     * by hyphens, none of Nitro's own.
     *
     * @param  array<mixed>  $names
     * @return list<string>
     *
     * @throws CompileException
     */
    public static function customAttributes(array $names): array
    {
        foreach ($names as $name) {
            if (! is_string($name) || preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $name) !== 1) {
                throw new CompileException('nitro.attributes: '.json_encode($name).' is not an attribute name (lowercase words joined by hyphens, such as "tooltip" or "auto-size")');
            }

            if (self::isAttribute($name) || self::isEvent($name) || isset(self::TRIGGERS[$name]) || in_array($name, self::RUNTIME, true) || str_starts_with($name, 'h-')) {
                throw new CompileException("nitro.attributes: nitro:{$name} is Nitro's own; give yours another name");
            }
        }

        return array_values(array_unique($names));
    }

    public static function isEvent(string $name): bool
    {
        return in_array($name, self::EVENTS, true);
    }
}
