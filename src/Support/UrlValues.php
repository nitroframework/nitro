<?php

namespace Nitro\Support;

/**
 * A typed value from a URL (a #[Url] property's, a route parameter's): the one rule set, which
 * the browser applies too (js/protocol.json's urlValues), so a URL means the same on both sides.
 *
 *   int     digits with an optional sign and no leading zero ("7", "-3"; not "007", "1.0", " 7"),
 *           within what both PHP and JavaScript hold exactly (±2^53 − 1)
 *   float   a finite decimal number ("1.5", "-.5", "2e3"; not "0x10", "1,5", " 1", "1e400")
 *   bool    "1" / "true", "0" / "false" / ""
 *   string  as it is
 */
final class UrlValues
{
    /** The types a route parameter (a URL segment) can fill: a segment is never empty, so never a bool. */
    public const SEGMENT_TYPES = ['int', 'float', 'string'];

    /**
     * The value of the type the text is, or [false, null] when it is not one.
     *
     * @return array{0: bool, 1: mixed}
     */
    public static function read(string $type, mixed $raw): array
    {
        if ($type === 'array') {
            return [is_array($raw), $raw];
        }

        if (! is_string($raw)) {
            return [false, null];
        }

        $rules = Protocol::get('urlValues');

        return match ($type) {
            'int' => self::int($raw, $rules),
            'float' => preg_match('/'.$rules['float'].'/', $raw) === 1 && is_finite((float) $raw) ? [true, (float) $raw] : [false, null],
            'bool' => match (true) {
                in_array(strtolower($raw), $rules['true'], true) => [true, true],
                in_array(strtolower($raw), $rules['false'], true) => [true, false],
                default => [false, null],
            },
            'string' => [true, $raw],
            default => [false, null],
        };
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{0: bool, 1: ?int}
     */
    private static function int(string $raw, array $rules): array
    {
        if (preg_match('/'.$rules['int'].'/', $raw) !== 1 || abs((float) $raw) > $rules['maxInt']) {
            return [false, null];
        }

        return [true, (int) $raw];
    }
}
