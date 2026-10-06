<?php

namespace Nitro\Fusion\View\Adapters;

use Nitro\Fusion\View\ScriptEngine;

/**
 * React: #[View(react: 'chart')] is chart.jsx (or .tsx) in resources/views/react or
 * resources/js/react. Its component's PHP, when it holds it, is a leading
 * / * <php> ... </php> * / comment. In the browser: nitro-fusion/react (js/adapters/react.js).
 */
final class ReactAdapter extends ScriptEngine
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $extensions
     */
    public function __construct(array $paths, array $extensions = ['jsx', 'tsx'])
    {
        parent::__construct('react', $paths, $extensions);
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
