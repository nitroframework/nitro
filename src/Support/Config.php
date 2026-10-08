<?php

namespace Nitro\Fusion\Support;

/**
 * Fusion's configuration (config/fusion.php, under the app's own: FusionServiceProvider merges
 * them), and what follows from it.
 */
final class Config
{
    /** @param  array<string, mixed>  $values  config('fusion') */
    public function __construct(private readonly array $values)
    {
    }

    /** A fusion.* value. No default here: config/fusion.php has every one. */
    public function get(string $key): mixed
    {
        return data_get($this->values, $key);
    }

    /** The layout a page renders in when its #[Layout] names none (fusion.layout). */
    public function layout(): string
    {
        return (string) $this->get('layout');
    }

    /** An URL under Fusion's endpoint (fusion.endpoint). */
    public function endpoint(string $path = ''): string
    {
        return rtrim((string) $this->get('endpoint'), '/').($path === '' ? '' : '/'.ltrim($path, '/'));
    }
}
