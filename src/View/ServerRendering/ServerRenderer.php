<?php

namespace Nitro\View\ServerRendering;

/**
 * Renders a JavaScript view on the server, for the page's first HTML: the
 * browser then hydrates that HTML instead of rendering the view itself.
 *
 * Nitro's are NodeRenderer (the app's render service, nitro:ssr) and NullRenderer (off). A
 * view that cannot be rendered is not an error: the page goes out with the empty element, and
 * the browser renders the view as it does without server rendering.
 */
interface ServerRenderer
{
    /** The view's HTML, from the component's state; null when it is left to the browser. */
    public function render(ViewState $state): ?string;
}
