<?php

namespace Nitro\Fusion\Support;

/**
 * The layout a page renders in, as the browser compares it (fusion:page): its view, and a hash of
 * its data when it has any (the title aside, which a visit sets). A markup-first visit renders
 * the page at once only into the same one.
 */
final class LayoutKey
{
    /** @param  array<string, mixed>  $data */
    public static function of(string $layout, array $data): string
    {
        unset($data['title']);

        return $data === [] ? $layout : $layout.'#'.substr(md5(serialize($data)), 0, 12);
    }
}
