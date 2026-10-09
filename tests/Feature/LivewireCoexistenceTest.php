<?php

namespace Nitro\Tests\Feature;

use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Livewire is installed in the fixture app for every test; here both render on one page, each
 * with its own tags, attributes, state and scripts.
 */
class LivewireCoexistenceTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_livewire_and_nitro_components_share_a_page(string $framework): void
    {
        $this->boot($framework);

        $response = $this->get('/both');
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode(), $html);

        /** Livewire's component, rendered by Livewire. */
        $this->assertStringContainsString('<span class="ticks">0</span>', $html);
        $this->assertStringContainsString('wire:snapshot=', $html);
        $this->assertStringContainsString('wire:click="tick"', $html);

        /** Nitro's component, rendered by Nitro. */
        $this->assertStringContainsString('<span class="count">0</span>', $html);
        $this->assertNotSame([], $this->snapshots($html));
        $this->assertStringContainsString('nitro:click="increment"', $html);

        /** Neither tag compiler touched the other's tags; both scripts are on the page. */
        $this->assertStringNotContainsString('<livewire:', $html);
        $this->assertStringNotContainsString('<nitro:', $html);
        $this->assertMatchesRegularExpression('/<script[^>]+livewire/', $html);
        $this->assertStringContainsString('/_nitro/nitro.js', $html);

        /** Nitro compiles only its own components. */
        $bundle = (string) $this->get('/_nitro/app.js')->getContent();
        $this->assertStringNotContainsString('ticker', $bundle);
    }
}
