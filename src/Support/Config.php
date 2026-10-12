<?php

namespace Nitro\Support;

/**
 * Nitro's configuration (config/nitro.php, under the app's own: underlay()), what follows from it, and whether the application is in development (APP_DEBUG): what
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

    /**
     * A config file's defaults under the app's values: the app's win, key by key at every depth,
     * so a config published before a key existed has it all the same, and the code reads every
     * value from config alone (the files in config/ are the one place its defaults are). A list
     * (middleware, paths, rules) is the app's whole, not merged entry by entry.
     *
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function underlay(array $defaults, array $values): array
    {
        foreach ($values as $name => $value) {
            $default = $defaults[$name] ?? null;
            $defaults[$name] = is_array($value) && is_array($default) && ! array_is_list($value) && ! array_is_list($default)
                ? self::underlay($default, $value)
                : $value;
        }

        return $defaults;
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
