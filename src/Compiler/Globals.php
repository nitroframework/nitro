<?php

namespace Nitro\Fusion\Compiler;

/**
 * Everything a component can use that it does not declare itself, in one place: the variables
 * templates and handlers see, the actions handlers can call, the component methods Fusion
 * provides, and the fusion: attributes. The compiler reads these tables; js/src/runtime
 * implements each entry.
 */
final class Globals
{
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
     * names and translation keys as literals, config keys listed in fusion.public_config.
     */
    public const HELPERS = ['route', 'url', 'asset', '__', 'trans', 'config'];

    /** Methods of $errors, as on Laravel's MessageBag. */
    public const ERROR_METHODS = ['has', 'first', 'get', 'all', 'any', 'count'];

    /** Methods of an Upload (a file a fusion:model file input uploaded) the browser has: what it shows of the file. */
    public const UPLOAD_METHODS = ['temporaryUrl', 'getClientOriginalName', 'getClientOriginalExtension', 'getSize', 'getMimeType'];

    /** Variables a handler (fusion:click="...") can read besides the template's. */
    public const HANDLER_VARIABLES = ['event'];

    /**
     * Actions a handler can call without a method on the component, and their arity.
     *   $set('prop', value)  $toggle('prop')  $refresh  $reload('prop', ...)  $loadMore('prop')  $dispatch('event', ...)
     *   $visit(url): go there, as a fusion:navigate link does
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
        /** $visit(url): a fusion:navigate visit, from any handler (search on Enter). */
        'visit' => 1,
    ];

    /**
     * Methods Fusion gives every component, usable from browser code:
     *   $this->dispatch('event', ...$params)  $this->reset('prop', ...)  $this->redirect($url)
     *   $this->js('$refs.search.focus()')  $this->shared('auth.user.name', $default)  $this->reload('orders')
     *   $this->flashed('toast')  $this->hasMore('posts')
     */
    public const COMPONENT_METHODS = ['dispatch', 'reset', 'redirect', 'js', 'shared', 'flashed', 'reload', 'hasMore'];

    /** fusion: attributes that are not events (the runtime reads them from the DOM). */
    public const ATTRIBUTES = ['model', 'key', 'loading', 'dirty', 'target', 'navigate', 'current', 'ignore', 'confirm', 'ref', 'transition'];

    /**
     * fusion:transition's modifiers, as Vue's <Transition> has them: .appear (on the first render
     * too), .in / .out (entering or leaving only); for the built-in effect, .opacity / .scale (one
     * of them only), .duration.300ms, .origin.top (bottom, left, right, center); .out-in / .in-out
     * (Vue's modes) and .move (Vue's <TransitionGroup>: rows slide to their new places).
     */
    /**
     * fusion:loading: shown while loading, or .remove (hidden), .class / .class.remove (classes
     * added or removed), .attr (an attribute set). Any of them can wait first: .delay (200ms),
     * .delay.500ms, .delay.1s. fusion:dirty (changes the server has not seen) takes the same
     * forms, without .delay.
     */
    public const LOADING_FORMS = [[], ['remove'], ['class'], ['class', 'remove'], ['attr']];

    public const TRANSITION_MODIFIERS = ['appear', 'in', 'out', 'opacity', 'scale', 'duration', 'origin', 'out-in', 'in-out', 'move'];

    /**
     * The modifiers these attributes take (checked at build time, like event modifiers):
     * fusion:navigate.keypress starts the visit on mouse down, fusion:navigate.preserve-scroll
     * keeps the scroll position, fusion:navigate.prefetch asks for the page while the pointer rests
     * on the link (.prefetch.60s: kept that long), fusion:current.exact matches the path only,
     * fusion:confirm.prompt asks for a word to be typed. fusion:loading's, fusion:model's and
     * fusion:transition's are checked by their own rules (LOADING_FORMS); the others take none.
     */
    public const ATTRIBUTE_MODIFIERS = [
        'navigate' => ['keypress', 'preserve-scroll', 'prefetch'],
        'current' => ['exact'],
        'confirm' => ['prompt'],
        'key' => [],
        'target' => [],
        'ignore' => [],
        'ref' => [],
    ];

    /**
     * Handlers that no DOM event starts, compiled like fusion:<event> (with the modifiers each
     * takes): fusion:poll.5s="refreshStats" calls it every interval (a bare fusion:poll
     * refreshes), fusion:init="load" once when its element appears, fusion:visible="loadMore"
     * when it comes into view (.once: the first time only; .200px: that far before).
     */
    public const HOOKS = [
        'poll' => ['visible', 'keep-alive'],
        'init' => [],
        'visible' => ['once'],
    ];

    /** DOM events usable as fusion:<event>. */
    public const EVENTS = [
        'click', 'dblclick', 'submit', 'input', 'change', 'keydown', 'keyup', 'keypress', 'focus', 'blur',
        'focusin', 'focusout', 'mouseenter', 'mouseleave', 'mouseover', 'mouseout', 'mousedown', 'mouseup',
        'contextmenu', 'scroll', 'wheel', 'pointerdown', 'pointerup', 'touchstart', 'touchend', 'drop',
        'dragstart', 'dragover', 'dragend', 'paste', 'copy', 'cut', 'select', 'reset', 'toggle',
    ];

    /** Event modifiers (fusion:submit.prevent, fusion:keydown.enter, fusion:click.debounce.300ms). */
    public const MODIFIERS = [
        'prevent', 'stop', 'self', 'once', 'window', 'document', 'outside', 'passive', 'capture',
        'enter', 'escape', 'esc', 'space', 'tab', 'up', 'down', 'left', 'right', 'delete', 'backspace',
        'shift', 'ctrl', 'alt', 'meta', 'debounce', 'throttle',
    ];

    /** fusion: names the runtime puts on elements itself (and fusion:h-..., its handlers' marks). */
    public const RUNTIME = ['id', 'name', 'page', 'snapshot', 'placeholder', 'hydrate', 'static'];

    public static function isAttribute(string $name): bool
    {
        return in_array($name, self::ATTRIBUTES, true);
    }

    /**
     * The application's own fusion: attributes (fusion.attributes): lowercase names, words joined
     * by hyphens, none of Fusion's own.
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
                throw new CompileException('fusion.attributes: '.json_encode($name).' is not an attribute name (lowercase words joined by hyphens, such as "tooltip" or "auto-size")');
            }

            if (self::isAttribute($name) || self::isEvent($name) || isset(self::HOOKS[$name]) || in_array($name, self::RUNTIME, true) || str_starts_with($name, 'h-')) {
                throw new CompileException("fusion.attributes: fusion:{$name} is Fusion's own; give yours another name");
            }
        }

        return array_values(array_unique($names));
    }

    public static function isEvent(string $name): bool
    {
        return in_array($name, self::EVENTS, true);
    }
}
