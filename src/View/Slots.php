<?php

namespace Nitro\Fusion\View;

use ArrayAccess;
use Illuminate\View\ComponentSlot;
use LogicException;

/**
 * $slots in a component's view: the named slots its parent passed (Livewire's $slots).
 *
 *   {{ $slots['footer'] }}   the slot (empty when not passed)
 *   $slots->has('footer')    whether it was passed, with content (for an if)
 *
 * $slot is the default one, a ComponentSlot ({{ $slot }}, $slot->isEmpty()).
 *
 * @implements ArrayAccess<string, ComponentSlot>
 */
final class Slots implements ArrayAccess
{
    /** @var array<string, ComponentSlot> */
    private array $slots = [];

    /** @param  array<string, string>  $slots  name => HTML */
    public function __construct(array $slots)
    {
        foreach ($slots as $name => $html) {
            $this->slots[$name] = new ComponentSlot($html);
        }
    }

    public function has(string $name): bool
    {
        return isset($this->slots[$name]) && $this->slots[$name]->isNotEmpty();
    }

    public function get(string $name): ComponentSlot
    {
        return $this->slots[$name] ?? new ComponentSlot('');
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): ComponentSlot
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Slots are set by the parent\'s tag, not in the view.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Slots are set by the parent\'s tag, not in the view.');
    }
}
