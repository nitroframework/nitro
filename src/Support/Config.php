<?php

namespace Nitro\Support;

/**
 * Nitro's configuration (config/nitro.php, under the app's own: NitroServiceProvider merges
 * them), what follows from it, and whether the application is in development (APP_DEBUG): what
 * Nitro shows or hides there (fallbacks, the dev overlay, compile errors) asks here.
 */
final class Config
{
    /** @param  array<string, mixed>  $values  config('nitro') */
    public function __construct(
        private readonly array $values,
        private readonly bool $debug = false,
    ) {
    }

    /** Whether the application is in development (app.debug). */
    public function debug(): bool
    {
        return $this->debug;
    }

    /** A nitro.* value. No default here: config/nitro.php has every one. */
    public function get(string $key): mixed
    {
        return data_get($this->values, $key);
    }

    /** The layout a page renders in when its #[Layout] names none (nitro.layout). */
    public function layout(): string
    {
        return (string) $this->get('layout');
    }

    /** An URL under Nitro's endpoint (nitro.endpoint). */
    public function endpoint(string $path = ''): string
    {
        return rtrim((string) $this->get('endpoint'), '/').($path === '' ? '' : '/'.ltrim($path, '/'));
    }
}
