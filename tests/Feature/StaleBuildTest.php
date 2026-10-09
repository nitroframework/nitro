<?php

namespace Nitro\Tests\Feature;

use Nitro\Build\Bundle;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A page rendered with another version of the components asks for its code: it gets a reload
 * (Nitro.stale()), not code that does not match its HTML.
 */
class StaleBuildTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_code_for_another_version_is_a_reload(string $framework): void
    {
        $this->boot($framework);
        $bundle = $this->app->make(Bundle::class);
        $version = $bundle->version();

        $current = $this->get('/_nitro/app.js?v='.$version);
        $this->assertStringContainsString('Nitro.define(', (string) $current->getContent(), 'its own version: the code');

        $this->assertStringContainsString('Nitro.define(', (string) $this->get('/_nitro/app.js?v='.$version.'.min')->getContent(), 'the build of the same version matches too');

        foreach (['/_nitro/app.js?v=0123456789abcdef', '/_nitro/app/'.$bundle->chunkId('counter').'.js?v=0123456789abcdef'] as $url) {
            $stale = $this->get($url);
            $this->assertSame(200, $stale->getStatusCode(), $url);
            $this->assertSame('window.Nitro && window.Nitro.stale();', $stale->getContent(), $url);
            $this->assertStringContainsString('no-cache', (string) $stale->headers->get('Cache-Control'), 'never kept: the next page load gets the code');
        }
    }
}
