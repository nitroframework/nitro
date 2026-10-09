<?php

namespace Nitro\Tests\Fixtures\Components;

/**
 * Where fixture #[Server] methods record what they did (not a component: it does not extend
 * Component, so discovery skips it).
 */
final class Store
{
    public static array $saved = [];

    /** The Journal fixture's lifecycle hooks, in the order they ran. */
    public static array $log = [];

    /** How many times the shared countries were resolved (ShareFixtureData: sent once). */
    public static int $onceCalls = 0;
}
