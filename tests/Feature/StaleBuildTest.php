<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A page rendered with another version of the components asks for its code: it gets a reload
 * (Fusion.stale()), not code that does not match its HTML.
 */
class StaleBuildTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_code_for_another_version_is_a_reload(string $framework): void
    {
        $this->boot($framework);
        $bundle = $this->app->make(Bundle::class);
        $version = $bundle->version();

        $current = $this->get('/_fusion/app.js?v='.$version);
        $this->assertStringContainsString('Fusion.define(', (string) $current->getContent(), 'its own version: the code');

        $this->assertStringContainsString('Fusion.define(', (string) $this->get('/_fusion/app.js?v='.$version.'.min')->getContent(), 'the build of the same version matches too');

        foreach (['/_fusion/app.js?v=0123456789abcdef', '/_fusion/app/'.$bundle->chunkId('counter').'.js?v=0123456789abcdef'] as $url) {
            $stale = $this->get($url);
            $this->assertSame(200, $stale->getStatusCode(), $url);
            $this->assertSame('window.Fusion && window.Fusion.stale();', $stale->getContent(), $url);
            $this->assertStringContainsString('no-cache', (string) $stale->headers->get('Cache-Control'), 'never kept: the next page load gets the code');
        }
    }
}
