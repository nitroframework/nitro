<?php

namespace Nitro\Fusion\Compiler\Php;

use PhpParser\Node\Name;

/**
 * What of PHP exists in the browser: the functions, constants and parameter types the compiled
 * code can use. The single list of PHP's surface in Fusion: a function or constant missing here
 * is a compile error naming it ("... is not available in the browser").
 *
 * General PHP: the functions implemented in js/src/php.js (FUNCTIONS) and in js/functions
 * (Functions::has()), those taking an array by reference (sort(), array_pop(), ...) or writing an
 * output parameter (preg_match()), the constants with their server values, and the scalar types
 * coerced on entry as PHP does.
 *
 * Fusion: the names Fusion goes by in a view or a use statement, for Fusion::browser().
 *
 * @internal
 */
final class Catalog
{
    /** PHP functions available in the browser (implemented in js/src/php.js `fns`). */
    public const FUNCTIONS = [
        'strlen', 'mb_strlen', 'strtolower', 'strtoupper', 'mb_strtolower', 'mb_strtoupper', 'ucfirst', 'lcfirst', 'ucwords',
        'str_contains', 'str_starts_with', 'str_ends_with', 'trim', 'ltrim', 'rtrim', 'str_repeat', 'str_pad', 'str_replace',
        'str_ireplace', 'substr', 'mb_substr', 'strpos', 'stripos', 'strrpos', 'substr_count', 'strrev', 'str_split', 'nl2br',
        'implode', 'join', 'explode', 'sprintf', 'number_format', 'htmlspecialchars', 'e', 'strval',
        'abs', 'round', 'floor', 'ceil', 'intdiv', 'fmod', 'sqrt', 'pow', 'max', 'min', 'intval', 'floatval', 'boolval',
        'is_numeric', 'is_string', 'is_array', 'is_bool', 'is_null', 'random_int',
        'count', 'array_is_list', 'array_keys', 'array_values', 'array_key_exists', 'array_key_first', 'array_key_last',
        'in_array', 'array_search', 'array_merge', 'array_filter', 'array_map', 'array_reduce', 'array_slice', 'array_reverse',
        'array_sum', 'array_product', 'array_unique', 'array_column', 'array_combine', 'array_flip', 'array_fill',
        'array_pad', 'array_chunk', 'array_diff', 'array_intersect', 'array_diff_key', 'array_intersect_key', 'array_fill_keys',
        'range', 'json_encode', 'json_decode',
        'sort', 'rsort', 'usort', 'uasort', 'uksort', 'asort', 'arsort', 'ksort', 'krsort', 'shuffle',
        'array_push', 'array_pop', 'array_shift', 'array_unshift', 'array_splice',
    ];

    /** Functions taking their first argument by reference: the result is assigned back. */
    public const BY_REF = [
        'sort', 'rsort', 'usort', 'uasort', 'uksort', 'asort', 'arsort', 'ksort', 'krsort', 'shuffle',
        'array_push', 'array_pop', 'array_shift', 'array_unshift', 'array_splice',
    ];

    /** Functions with an output parameter (a reference): its position. Their JavaScript returns [result, output]. */
    public const OUT_PARAMS = ['preg_match' => 2, 'preg_match_all' => 2];

    /** References that are not taken: a function's argument there fails to compile. */
    public const UNSUPPORTED_REFS = ['preg_replace' => 4, 'preg_replace_callback' => 4, 'similar_text' => 2];

    public const ALIASES = ['join' => 'implode'];

    /** Fusion, as a view (Blade's alias) or a use statement names it: Fusion::browser(). */
    public const FUSION = ['Fusion', \Nitro\Fusion\Facades\Fusion::class, \Nitro\Fusion\Fusion::class];

    public const CONSTANTS = [
        /** The server's value: the page is rendered there too, and both renders must match. */
        'PHP_EOL' => PHP_EOL,
        'PHP_INT_MAX' => 9007199254740991,
        'PHP_INT_MIN' => -9007199254740991,
        'PHP_FLOAT_EPSILON' => PHP_FLOAT_EPSILON,
        'M_PI' => M_PI,
        'STR_PAD_RIGHT' => STR_PAD_RIGHT,
        'STR_PAD_LEFT' => STR_PAD_LEFT,
        'STR_PAD_BOTH' => STR_PAD_BOTH,
        'ARRAY_FILTER_USE_KEY' => ARRAY_FILTER_USE_KEY,
        'ARRAY_FILTER_USE_BOTH' => ARRAY_FILTER_USE_BOTH,
        'JSON_PRETTY_PRINT' => JSON_PRETTY_PRINT,
        'JSON_UNESCAPED_SLASHES' => JSON_UNESCAPED_SLASHES,
        'JSON_UNESCAPED_UNICODE' => JSON_UNESCAPED_UNICODE,
        'JSON_THROW_ON_ERROR' => JSON_THROW_ON_ERROR,
        /** The flags of the functions in js/functions. */
        'ENT_COMPAT' => ENT_COMPAT,
        'ENT_QUOTES' => ENT_QUOTES,
        'ENT_NOQUOTES' => ENT_NOQUOTES,
        'ENT_HTML401' => ENT_HTML401,
        'ENT_XML1' => ENT_XML1,
        'ENT_XHTML' => ENT_XHTML,
        'ENT_HTML5' => ENT_HTML5,
        'ENT_SUBSTITUTE' => ENT_SUBSTITUTE,
        'PHP_URL_SCHEME' => PHP_URL_SCHEME,
        'PHP_URL_HOST' => PHP_URL_HOST,
        'PHP_URL_PORT' => PHP_URL_PORT,
        'PHP_URL_USER' => PHP_URL_USER,
        'PHP_URL_PASS' => PHP_URL_PASS,
        'PHP_URL_PATH' => PHP_URL_PATH,
        'PHP_URL_QUERY' => PHP_URL_QUERY,
        'PHP_URL_FRAGMENT' => PHP_URL_FRAGMENT,
        'PHP_QUERY_RFC1738' => PHP_QUERY_RFC1738,
        'PHP_QUERY_RFC3986' => PHP_QUERY_RFC3986,
        'M_E' => M_E,
        'M_SQRT2' => M_SQRT2,
        'MB_CASE_UPPER' => MB_CASE_UPPER,
        'MB_CASE_LOWER' => MB_CASE_LOWER,
        'MB_CASE_TITLE' => MB_CASE_TITLE,
        'CASE_LOWER' => CASE_LOWER,
        'CASE_UPPER' => CASE_UPPER,
        'PREG_PATTERN_ORDER' => PREG_PATTERN_ORDER,
        'PREG_SET_ORDER' => PREG_SET_ORDER,
        'PREG_OFFSET_CAPTURE' => PREG_OFFSET_CAPTURE,
        'PREG_UNMATCHED_AS_NULL' => PREG_UNMATCHED_AS_NULL,
        'PREG_SPLIT_NO_EMPTY' => PREG_SPLIT_NO_EMPTY,
        'PREG_SPLIT_DELIM_CAPTURE' => PREG_SPLIT_DELIM_CAPTURE,
        'PREG_SPLIT_OFFSET_CAPTURE' => PREG_SPLIT_OFFSET_CAPTURE,
        'PREG_GREP_INVERT' => PREG_GREP_INVERT,
    ];

    /** A called function's name as the browser has it: lower case, without a leading \, join() as implode(). */
    public static function functionName(Name $name): string
    {
        $lower = strtolower(ltrim($name->toString(), '\\'));

        return self::ALIASES[$lower] ?? $lower;
    }
}
