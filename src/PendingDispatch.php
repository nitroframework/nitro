<?php

namespace Nitro\Fusion;

use Nitro\Fusion\Compiler\Components;
use Nitro\Fusion\Lifecycle\Context;

/**
 * $this->dispatch('saved', ...), as Livewire's: every component listening (#[On('saved')]) and
 * the window get it, unless it is sent to one component:
 *
 *   $this->dispatch('saved')->to(Dashboard::class);   (or ->to('dashboard'))
 *   $this->dispatch('saved')->self();
 */
final class PendingDispatch
{
    public function __construct(private readonly Context $context, private readonly int $index)
    {
    }

    /** Only to the components of this class (or name). */
    public function to(string $component): self
    {
        $this->context->dispatches[$this->index]['to'] = app(Components::class)->meta($component)->name;

        return $this;
    }

    /** Only to the component dispatching it. */
    public function self(): self
    {
        $this->context->dispatches[$this->index]['self'] = true;

        return $this;
    }
}
