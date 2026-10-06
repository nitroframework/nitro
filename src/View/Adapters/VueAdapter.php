<?php

namespace Nitro\Fusion\View\Adapters;

use Nitro\Fusion\View\ScriptEngine;

/**
 * Vue: #[View(vue: 'cart/badge')] is cart/badge.vue in resources/views/vue or resources/js/vue.
 * A single-file component; its component's PHP, when it holds it, is a <php> block in it. In the
 * browser: nitro-fusion/vue (js/adapters/vue.js).
 */
final class VueAdapter extends ScriptEngine
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $extensions
     */
    public function __construct(array $paths, array $extensions = ['vue'])
    {
        parent::__construct('vue', $paths, $extensions);
    }

    public function label(): string
    {
        return 'Vue';
    }

    public function phpBlock(string $source, string $file): ?array
    {
        return self::elementBlock($source);
    }
}
