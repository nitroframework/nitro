<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Compiled Blade views name Fusion's parts (a <fusion:> tag, @fusion, @portal): views compiled by
 * another version of Fusion are dropped once, so an upgrade never runs what the old one compiled.
 */
final class StaleViewsTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_views_compiled_by_another_version_are_compiled_again(string $framework): void
    {
        $compiled = self::APP.'/storage/framework/views';
        @mkdir($compiled, 0777, true);
        file_put_contents($compiled.'/fusion.version', 'another version');
        file_put_contents($compiled.'/stale.php', '<?php echo app(\Nitro\Fusion\Fusion::class)->tag(); ?>');

        $this->boot($framework);

        $this->assertFileDoesNotExist($compiled.'/stale.php', 'a view compiled by another version of Fusion is dropped');
        $this->assertNotSame('another version', file_get_contents($compiled.'/fusion.version'), 'the stamp is this version\'s');

        file_put_contents($compiled.'/current.php', '<?php // compiled by this version');
        $this->boot($framework);

        $this->assertFileExists($compiled.'/current.php', 'views compiled by this version stay');
        @unlink($compiled.'/current.php');
    }
}
