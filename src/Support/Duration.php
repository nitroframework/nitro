<?php

namespace Nitro\Fusion\Support;

/**
 * A time written as a modifier (.debounce.300ms, .delay.1s, .prefetch.60s, .duration.150ms):
 * whole milliseconds or seconds. The compiler checks it here; the runtime reads it with the
 * same form (duration() in js/src/runtime/dom/events.js).
 */
final class Duration
{
    /** Whether the modifier is a time. */
    public static function is(?string $modifier): bool
    {
        return $modifier !== null && preg_match('/^\d+(ms|s)$/', $modifier) === 1;
    }
}
