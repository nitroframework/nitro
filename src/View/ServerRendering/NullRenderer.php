<?php

namespace Nitro\Fusion\View\ServerRendering;

/** Server rendering off (fusion.ssr.enabled): every JavaScript view renders in the browser. */
final class NullRenderer implements ServerRenderer
{
    public function render(ViewState $state): ?string
    {
        return null;
    }
}
