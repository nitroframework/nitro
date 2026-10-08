<?php

namespace Nitro\Fusion\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A name spelled across several parts of Fusion is a concept without a home. Each such literal
 * is either allowed (allowed-literals.php, with why) or a known break (known-breaks.php, with
 * the TASKS item that fixes it). A new one fails; a fixed break still listed fails too.
 */
final class LiteralsTest extends TestCase
{
    public function test_a_name_spelled_across_fusion_is_allowed_or_a_known_break(): void
    {
        $allowed = require __DIR__.'/allowed-literals.php';
        $known = Baseline::of('literals', 'spread');
        $spread = Literals::spread();

        $new = array_diff_key($spread, $allowed, $known);
        $report = array_map(fn (array $files) => implode(', ', $files), $new);

        $this->assertSame([], $report, 'A name spelled in '.Literals::FILES.'+ files across parts of Fusion: give it one home (or, if it truly is not Fusion\'s concept, allow it with a reason)');
        $this->assertSame([], array_keys(array_diff_key($known, $spread)), 'Fixed: take it out of known-breaks.php');
    }
}
