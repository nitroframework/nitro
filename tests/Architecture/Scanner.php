<?php

namespace Nitro\Fusion\Tests\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Fusion's own code, as the architecture guards read it: every PHP file under src/ and every
 * JavaScript file of the runtime and adapters, with comments taken out (prose may name
 * anything; code may not). Paths are relative to the package, with forward slashes.
 */
final class Scanner
{
    /** What the guards read: the PHP library, the browser runtime, the adapters, the Vite plugin. */
    public const SOURCES = ['src', 'js/src', 'js/adapters', 'js/vite.js'];

    /** @var array<string, string>|null path => code */
    private static ?array $code = null;

    public static function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    /**
     * Every file's code, comments out.
     *
     * @return array<string, string> path => code
     */
    public static function code(): array
    {
        if (self::$code !== null) {
            return self::$code;
        }

        self::$code = [];

        foreach (self::SOURCES as $source) {
            $full = self::root().'/'.$source;
            $files = is_file($full) ? [$full] : iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, RecursiveDirectoryIterator::SKIP_DOTS)), false);

            foreach ($files as $file) {
                $path = substr(str_replace('\\', '/', (string) $file), strlen(self::root()) + 1);

                if (preg_match('/\.(php|js|mjs)$/', $path) === 1) {
                    self::$code[$path] = self::withoutComments((string) file_get_contents((string) $file));
                }
            }
        }

        ksort(self::$code);

        return self::$code;
    }

    /**
     * The files whose code matches the pattern (a file's lines, one at a time; or the whole file
     * with $multiline).
     *
     * @return list<string>
     */
    public static function filesMatching(string $pattern, bool $multiline = false): array
    {
        $found = [];

        foreach (self::code() as $path => $code) {
            if ($multiline ? preg_match($pattern, $code) === 1 : self::anyLine($pattern, $code)) {
                $found[] = $path;
            }
        }

        return $found;
    }

    public static function isPhp(string $path): bool
    {
        return str_ends_with($path, '.php');
    }

    /** A line that builds an error message: prose, which may name anything. */
    public static function isMessage(string $line): bool
    {
        return preg_match('/Exception\(|new Error\(|->error\(|->warn\(|components->(error|warn|info)\(/', $line) === 1;
    }

    private static function anyLine(string $pattern, string $code): bool
    {
        foreach (explode("\n", $code) as $line) {
            if (! self::isMessage($line) && preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Block and line comments out, line breaks kept (so line numbers stay the file's). */
    private static function withoutComments(string $code): string
    {
        $code = (string) preg_replace_callback('#/\*.*?\*/#s', fn (array $match) => str_repeat("\n", substr_count($match[0], "\n")), $code);

        return (string) preg_replace('#^\s*(//|\#(?!\[)).*$#m', '', $code);
    }
}
