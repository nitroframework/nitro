<?php

namespace Nitro\Fusion\View\Adapters;

use Nitro\Fusion\View\ScriptEngine;
use Nitro\Fusion\View\ServerRendering\NullRenderer;
use Nitro\Fusion\View\ServerRendering\ServerRenderer;

/**
 * Svelte: #[View(svelte: 'cart/badge')] is cart/badge.svelte in resources/views/svelte or
 * resources/js/svelte. A Svelte 5 component; its component's PHP, when it holds it, is a <php>
 * block in it, as in a .vue file. In the browser: nitro-fusion/svelte (js/adapters/svelte.svelte.js).
 */
final class SvelteAdapter extends ScriptEngine
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $extensions
     */
    public function __construct(array $paths, array $extensions = ['svelte'], ServerRenderer $server = new NullRenderer)
    {
        parent::__construct('svelte', $paths, $extensions, $server);
    }

    public function label(): string
    {
        return 'Svelte';
    }

    public function phpBlock(string $source, string $file): ?array
    {
        return self::elementBlock($source);
    }
}
