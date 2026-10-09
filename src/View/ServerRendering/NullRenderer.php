<?php

namespace Nitro\View\ServerRendering;

/** Server rendering off (nitro.ssr.enabled): every JavaScript view renders in the browser. */
final class NullRenderer implements ServerRenderer
{
    public function render(ViewState $state): ?string
    {
        return null;
    }
}
