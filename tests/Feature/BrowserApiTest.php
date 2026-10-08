<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Browser\BrowserOnlyException;
use Nitro\Fusion\Facades\Fusion;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fusion::browser(): compiled to JavaScript in browser methods (tests/Browser), never run on the
 * server. Its page renders there as any other.
 */
class BrowserApiTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_it_throws_on_the_server(string $framework): void
    {
        $this->boot($framework);

        $this->expectException(BrowserOnlyException::class);
        $this->expectExceptionMessage('Fusion::browser() runs in the browser');

        Fusion::browser()->clipboard()->write('x');
    }

    #[DataProvider('frameworks')]
    public function test_a_page_that_uses_it_renders_on_the_server(string $framework): void
    {
        $this->boot($framework);
        $response = $this->get('/devices');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<canvas fusion:ref="chart"', (string) $response->getContent());
        $this->assertStringContainsString('not copied', (string) $response->getContent());
    }
}
