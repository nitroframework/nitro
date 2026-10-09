<?php

namespace Nitro;

use InvalidArgumentException;

/**
 * How a nitro:navigate visit shows a page: the one place the modes are named and what each
 * means. A page chooses with #[Navigate] (a case, or its value); nitro.navigation is the
 * default for the others.
 *
 *   Data     the browser waits for the page's data, then renders it complete
 *   Markup   the browser renders the page at once with its defaults; its properties count as
 *            deferred until the data arrives, so @deferred / @placeholder show placeholders
 *   Client   the browser renders the page without asking the server at all (its title and head
 *            worked out at build time): for pages that need nothing from it, with no mount(),
 *            #[Server] methods, #[Url] properties, route parameters or middleware beyond web
 */
enum NavigationMode: string
{
    case Data = 'data';
    case Markup = 'markup';
    case Client = 'client';

    /**
     * nitro.navigation: the mode of pages without #[Navigate]. Not Client: a page renders
     * without the server only when it says so, since it must need nothing from it.
     */
    public static function fromConfig(string $value): self
    {
        $mode = self::tryFrom($value);

        if ($mode === null || ! $mode->mayBeDefault()) {
            throw new InvalidArgumentException('nitro.navigation is '.self::listed(array_filter(self::cases(), fn (self $mode) => $mode->mayBeDefault())).", not '{$value}'");
        }

        return $mode;
    }

    /**
     * The modes, as an error message lists them: 'data', 'markup' or 'client'.
     *
     * @param  list<self>|null  $modes  null: all of them
     */
    public static function listed(?array $modes = null): string
    {
        $names = array_map(fn (self $mode) => "'{$mode->value}'", array_values($modes ?? self::cases()));
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names)." or {$last}";
    }

    /** A visit renders the page at once, before its data (or without asking for it). */
    public function rendersAtOnce(): bool
    {
        return $this !== self::Data;
    }

    /** Whether nitro.navigation may make it every page's. */
    public function mayBeDefault(): bool
    {
        return $this !== self::Client;
    }
}
