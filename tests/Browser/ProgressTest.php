<?php

namespace Nitro\Fusion\Tests\Browser;

use Nitro\Fusion\Tests\Support\Node;
use PHPUnit\Framework\TestCase;

/**
 * The progress bar (nitro-progress) through visits of every length, one after another as fast as
 * a visitor clicks: it never goes back while shown, a bar starts from its start, and none is left
 * over. With a minimum duration, a bar that shows runs at least that long and reaches 100%.
 */
class ProgressTest extends TestCase
{
    /** @return array<string, array{int}> */
    public static function minimums(): array
    {
        return ['finishing as soon as the page is there' => [0], 'running its minimum duration' => [(require dirname(__DIR__, 2).'/config/nprogress.php')['minimum_duration']]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('minimums')]
    public function test_the_bar_goes_forward_through_visits_one_after_another(int $minimum): void
    {
        $options = require dirname(__DIR__, 2).'/config/nprogress.php';

        $failures = Node::run('js/test/support/run-progress.mjs', [
            'options' => [
                'serverCalls' => false, 'delay' => $options['delay'], 'minimumDuration' => $minimum, 'color' => 'red', 'height' => '3px', 'position' => 'top',
                'glow' => true, 'glowWidth' => '100px', 'spinner' => false, 'spinnerSize' => '18px', 'zIndex' => 1031,
                'minimum' => $options['minimum'], 'maximum' => $options['maximum'], 'easing' => $options['easing'],
                'speed' => $options['speed'], 'fadeSpeed' => $options['fade_speed'],
                'trickle' => $options['trickle'], 'trickleSpeed' => $options['trickle_speed'],
            ],
            'scenarios' => [
                'faster than the delay' => [[0, 'fusion:navigating'], [10, 'fusion:navigated']],
                'a quick visit' => [[0, 'fusion:navigating'], [60, 'fusion:navigated']],
                'a slow visit' => [[0, 'fusion:navigating'], [900, 'fusion:navigated']],
                'visits back to back' => [[0, 'fusion:navigating'], [300, 'fusion:navigated'], [20, 'fusion:navigating'], [300, 'fusion:navigated']],
                'a click while the bar fades' => [[0, 'fusion:navigating'], [200, 'fusion:navigated'], [250, 'fusion:navigating'], [300, 'fusion:navigated']],
                'a click as the bar finishes' => [[0, 'fusion:navigating'], [200, 'fusion:navigated'], [5, 'fusion:navigating'], [500, 'fusion:navigated']],
                'clicks in a row' => [[0, 'fusion:navigating'], [80, 'fusion:navigated'], [40, 'fusion:navigating'], [80, 'fusion:navigated'], [40, 'fusion:navigating'], [80, 'fusion:navigated']],
            ],
        ]);

        $this->assertSame([], $failures);
    }
}
