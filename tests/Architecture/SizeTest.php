<?php

namespace Nitro\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A file that keeps growing is a class doing more than one job. Over the limits, a file is
 * either allowed (one job that is big by nature, with why) or a known break to split.
 */
final class SizeTest extends TestCase
{
    public const LINES = 500;

    public const PUBLIC_METHODS = 30;

    /** One job each, big by nature. */
    private const ALLOWED = [
        'src/Browser/Canvas.php' => 'the canvas 2D context, one method for each of the browser\'s',
        'js/src/php/functions.js' => 'the catalog of PHP\'s functions in the browser',
        'src/Compiler/Template/BladeInliner.php' => 'Blade\'s @include and component tags, worked out as Blade does (the user: "dont split")',
    ];

    public function test_a_file_stays_one_job_in_size(): void
    {
        $over = [];

        foreach (Scanner::code() as $path => $code) {
            $lines = substr_count((string) file_get_contents(Scanner::root().'/'.$path), "\n");
            $methods = preg_match_all('/^\s+(final\s+)?public\s+(static\s+)?function\s/m', $code);

            if ($lines > self::LINES || $methods > self::PUBLIC_METHODS) {
                $over[$path] = "{$lines} lines, {$methods} public methods";
            }
        }

        $known = Baseline::of('size', 'over');
        $new = array_diff_key($over, self::ALLOWED, $known);

        $this->assertSame([], $new, 'Over '.self::LINES.' lines or '.self::PUBLIC_METHODS.' public methods: split it by job');
        $this->assertSame([], array_keys(array_diff_key($known, $over)), 'Within the limits now: take it out of known-breaks.php');
    }
}
