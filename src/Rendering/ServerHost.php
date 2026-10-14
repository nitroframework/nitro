<?php

namespace Nitro\Rendering;

use Nitro\View\TreeHost;

/**
 * The page a server render of a compiled view renders in (View\TreeRenderer): its children placed
 * as a Blade view's <nitro:...> tags place them (Placement), its @teleport content kept for its
 * portal (Portals).
 *
 * @internal
 */
final class ServerHost implements TreeHost
{
    public function __construct(private readonly Placement $placement, private readonly Portals $portals)
    {
    }

    public function child(string $name, array $props, string $key, int $number, array $slots, ?string $hydrate): string
    {
        return (string) $this->placement->tag($name, $props, $key, $number, $slots, $hydrate);
    }

    public function teleport(string $to, string $html): void
    {
        $this->portals->startTeleport($to);
        echo $html;
        $this->portals->endTeleport();
    }
}
