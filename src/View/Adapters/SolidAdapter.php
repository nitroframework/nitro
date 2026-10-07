<?php

namespace Nitro\Fusion\View\Adapters;

use Nitro\Fusion\View\ScriptEngine;
use Nitro\Fusion\View\ServerRendering\NullRenderer;
use Nitro\Fusion\View\ServerRendering\ServerRenderer;

/**
 * Solid: #[View(solid: 'clock')] is clock.jsx (or .tsx) in resources/views/solid or
 * resources/js/solid. Its component's PHP, when it holds it, is a leading
 * / * <php> ... </php> * / comment. In the browser: nitro-fusion/solid (js/adapters/solid.js).
 */
final class SolidAdapter extends ScriptEngine
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $extensions
     */
    public function __construct(array $paths, array $extensions = ['jsx', 'tsx'], ServerRenderer $server = new NullRenderer)
    {
        parent::__construct('solid', $paths, $extensions, $server);
    }

    public function label(): string
    {
        return 'Solid';
    }

    public function phpBlock(string $source, string $file): ?array
    {
        return self::commentBlock($source);
    }
}
