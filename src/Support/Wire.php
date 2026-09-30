<?php

namespace Nitro\Fusion\Support;

use BackedEnum;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonException;

/**
 * Values between PHP and the browser. JSON, where every PHP array that is not a list becomes
 * {"$m": [[key, value], ...]}: plain JSON objects would lose PHP's key order (integer keys are
 * reordered by JavaScript) and the difference between 5 and "5" keys. Mirrors encode()/decode()
 * in js/src/php.js, which decodes these into JavaScript Maps.
 */
final class Wire
{
    public static function encode(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('INF and NAN cannot be sent to the browser.');
            }

            return $value;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::encode(...), $value);
            }

            $pairs = [];

            foreach ($value as $key => $item) {
                $pairs[] = [$key, self::encode($item)];
            }

            return ['$m' => $pairs];
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Arrayable) {
            return self::encode($value->toArray());
        }

        throw new InvalidArgumentException(sprintf('A value of type %s cannot be sent to the browser.', get_debug_type($value)));
    }

    /**
     * @throws InvalidArgumentException on a malformed {"$m": ...} array
     */
    public static function decode(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (count($value) === 1 && isset($value['$m']) && is_array($value['$m']) && array_is_list($value['$m'])) {
            $array = [];

            foreach ($value['$m'] as $pair) {
                if (! is_array($pair) || count($pair) !== 2 || ! array_key_exists(0, $pair) || ! array_key_exists(1, $pair)
                    || ! (is_int($pair[0]) || is_string($pair[0]))) {
                    throw new InvalidArgumentException('Malformed array in payload.');
                }

                $array[$pair[0]] = self::decode($pair[1]);
            }

            return $array;
        }

        return array_map(self::decode(...), $value);
    }

    public static function json(mixed $value): string
    {
        /** Floats keep their ".0" so a float property reads back as a float on the server. */
        return json_encode(self::encode($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @throws JsonException
     * @throws InvalidArgumentException
     */
    public static function fromJson(string $json): mixed
    {
        return self::decode(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }
}
