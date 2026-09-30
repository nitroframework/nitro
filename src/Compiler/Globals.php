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

    /** Variables a handler (fusion:click="...") can read besides the template's. */
    public const HANDLER_VARIABLES = ['event'];

    /**
     * Actions a handler can call without a method on the component, and their arity.
     *   $set('prop', value)  $toggle('prop')  $refresh  $dispatch('event', ...)
     */
    public const ACTIONS = [
        'set' => 2,
        'toggle' => 1,
        'refresh' => 0,
        'dispatch' => null,
        'dispatchTo' => null,
        'dispatchSelf' => null,
    ];

    /**
     * Methods Fusion gives every component, usable from browser code:
     *   $this->dispatch('event', ...$params)  $this->reset('prop', ...)  $this->redirect($url)
     *   $this->js('$refs.search.focus()')
     */
    public const COMPONENT_METHODS = ['dispatch', 'reset', 'redirect', 'js'];

    /** fusion: attributes that are not events (the runtime reads them from the DOM). */
    public const ATTRIBUTES = ['model', 'key', 'loading', 'target', 'navigate', 'current', 'ignore', 'confirm', 'ref'];

    /**
     * The modifiers these attributes take (checked at build time, like event modifiers):
     * fusion:navigate.keypress starts the visit on mouse down, fusion:current.exact matches the
     * path only, fusion:confirm.prompt asks for a word to be typed.
     */
    public const ATTRIBUTE_MODIFIERS = [
        'navigate' => ['keypress'],
        'current' => ['exact'],
        'confirm' => ['prompt'],
    ];

    /**
     * Handlers that no DOM event starts, compiled like fusion:<event> (with the modifiers each
     * takes): fusion:poll.5s="refreshStats" calls it every interval (a bare fusion:poll
     * refreshes), fusion:init="load" once when its element appears.
     */
    public const HOOKS = [
        'poll' => ['visible', 'keep-alive'],
        'init' => [],
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

    public static function isAttribute(string $name): bool
    {
        return in_array($name, self::ATTRIBUTES, true);
    }

    public static function isEvent(string $name): bool
    {
        return in_array($name, self::EVENTS, true);
    }
}
