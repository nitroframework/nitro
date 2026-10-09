<?php

namespace Nitro\Attributes;

use Attribute;

/**
 * A class whose static methods and constants browser methods and views may use: its methods are
 * compiled to JavaScript, under the same rules as a component's browser methods (no database, no
 * clock, no server), and run in the browser. Its constants travel as their values.
 *
 *     #[Browser]
 *     final class Money
 *     {
 *         public static function format(int $cents): string { ... }
 *     }
 *
 * Only the methods the browser reaches are compiled. The class still works as any PHP class on the
 * server.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Browser
{
}
