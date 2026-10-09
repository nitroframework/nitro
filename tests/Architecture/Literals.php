<?php

namespace Nitro\Tests\Architecture;

/**
 * Name-like literals (identifiers, paths, nitro: names) and where they are spelled: a name
 * written in several parts of Nitro is a concept without a home, unless it is something
 * outside Nitro's choosing (a PHP type, an HTTP method, a DOM property).
 */
final class Literals
{
    /** In this many files at least, across two areas or more, a literal needs a reason. */
    public const FILES = 3;

    /**
     * Each literal spelled in FILES files or more across two areas or more.
     *
     * @return array<string, list<string>> literal => files
     */
    public static function spread(): array
    {
        $files = [];

        foreach (Scanner::code() as $path => $code) {
            foreach (explode("\n", $code) as $line) {
                /** Messages are prose: a word in one is not a name. */
                if (Scanner::isMessage($line)) {
                    continue;
                }

                preg_match_all("/'([A-Za-z_\$:.\/@-][\w\$:.\/@-]{1,39})'/", $line, $matches);

                foreach ($matches[1] as $literal) {
                    $files[$literal][$path] = true;
                }
            }
        }

        $spread = [];

        foreach ($files as $literal => $where) {
            $where = array_keys($where);
            $areas = array_unique(array_map(self::area(...), $where));

            if (count($where) >= self::FILES && count($areas) >= 2) {
                sort($where);
                $spread[(string) $literal] = $where;
            }
        }

        ksort($spread);

        return $spread;
    }

    /** A part of Nitro: src/Compiler, src/Http, ..., js/src/runtime, js/src/php, js/adapters. */
    public static function area(string $path): string
    {
        $parts = explode('/', $path);

        return match (true) {
            $parts[0] === 'src' => count($parts) > 2 ? 'src/'.$parts[1] : 'src',
            $parts[0] === 'js' && $parts[1] === 'src' => 'js/src/'.(count($parts) > 3 ? $parts[2] : ''),
            default => $parts[0].'/'.$parts[1],
        };
    }
}
