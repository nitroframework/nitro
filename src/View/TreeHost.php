<?php

namespace Nitro\View;

/**
 * What a server render of a compiled view (TreeRenderer) asks of the page it renders in: the
 * child components it places and where its @teleport content goes. Rendering\ServerHost is it;
 * in the browser, the View's host (runtime/view/view.js) is.
 *
 * @internal
 */
interface TreeHost
{
    /**
     * A child component placed in the view (<nitro:name>), rendered: its HTML.
     *
     * @param  array<string, mixed>  $props
     * @param  array<string, string>  $slots  name => its HTML
     */
    public function child(string $name, array $props, string $key, int $number, array $slots, ?string $hydrate): string;

    /** The content of a @teleport('name'), rendered: for its portal. */
    public function teleport(string $to, string $html): void;
}
