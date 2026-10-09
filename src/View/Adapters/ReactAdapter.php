<?php

namespace Nitro\View\Adapters;

use Nitro\View\ScriptEngine;
use Nitro\View\ServerRendering\NullRenderer;
use Nitro\View\ServerRendering\ServerRenderer;

/**
 * React: #[View(react: 'chart')] is chart.jsx (or .tsx) in resources/views/react or
 * resources/js/react. Its component's PHP, when it holds it, is a leading
 * / * <php> ... </php> * / comment. In the browser: nitro/react (js/adapters/react.js).
 */
final class ReactAdapter extends ScriptEngine
{
    use SharedCore;

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $extensions
     */
    public function __construct(array $paths, array $extensions, ServerRenderer $server = new NullRenderer)
    {
        parent::__construct('react', $paths, $extensions, $server);
    }

    public function label(): string
    {
        return 'React';
    }

    public function phpBlock(string $source, string $file): ?array
    {
        return self::commentBlock($source);
    }
}
