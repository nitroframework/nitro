<?php

namespace Nitro\Rendering;

use Nitro\Support\Config;
use Nitro\Support\Protocol;

/**
 * The dev overlay (dist/nitro-devtools.js, js/src/devtools): whether it is on, its file, and the
 * script @nitroScripts adds for it. On with nitro.devtools, or when that is null with APP_DEBUG:
 * never in production, where it is neither added to a page nor served.
 *
 * @internal
 */
final class Devtools
{
    public function __construct(private readonly Config $config)
    {
    }

    public function enabled(): bool
    {
        $enabled = $this->config->get('devtools');

        return $enabled === null ? $this->config->debug() : (bool) $enabled;
    }

    public function path(): string
    {
        return dirname(__DIR__, 2).'/dist/nitro-devtools.js';
    }

    public function version(): string
    {
        return substr((string) @md5_file($this->path()), 0, 12);
    }

    /** The overlay's script, after the runtime's (it is a plugin on it); none when it is off. */
    public function script(): string
    {
        if (! $this->enabled()) {
            return '';
        }

        return "\n".'<script src="'.e($this->config->endpoint(Protocol::path('devtools')).'?v='.$this->version()).'" defer></script>';
    }
}
