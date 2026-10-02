<?php

namespace Nitro\Fusion\Browser;

/**
 * localStorage or sessionStorage. Values are stored as JSON, so arrays keep their keys; a value
 * another script stored as plain text is read as a string. Where storage is blocked (a private
 * window), get() returns the default and set() does nothing.
 */
final class Storage
{
    public function get(string $key, mixed $default = null): mixed
    {
        throw BrowserOnlyException::make();
    }

    public function set(string $key, mixed $value): void
    {
        throw BrowserOnlyException::make();
    }

    public function has(string $key): bool
    {
        throw BrowserOnlyException::make();
    }

    public function forget(string $key): void
    {
        throw BrowserOnlyException::make();
    }
}
