<?php

namespace Nitro\Tests\Feature;

use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A page's data travels in one block, as Next.js and Nuxt carry theirs: each server-rendered
 * component's signed state by its id, readable JSON, not an attribute per component.
 */
final class PageDataTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_the_states_are_one_block_with_every_component_rendered(string $framework): void
    {
        $this->boot($framework);
        $html = (string) $this->get('/scripts-first')->getContent();

        $this->assertStringNotContainsString('nitro:snapshot', $html, 'no component carries its state as an attribute');
        $this->assertSame(1, substr_count($html, '<script type="application/json" data-nitro-state>'), 'one block');
        $this->assertStringNotContainsString('<!--nitro:state-->', $html, 'the block is filled once the page is rendered');

        /** @nitroScripts is in the <head>: the components after it are in the block all the same. */
        $states = $this->snapshots($html);
        $this->assertCount(1, $states, 'the live component, not the static one');
        $this->assertSame(3, $states[0]['state']['data']['step']);

        preg_match('/<script type="application\/json" data-nitro-state>(.*?)<\/script>/s', $html, $block);
        $this->assertStringNotContainsString('&quot;', $block[1], 'readable JSON: no HTML escaping');
    }

    #[DataProvider('frameworks')]
    public function test_the_page_is_smaller_than_with_a_state_per_attribute(string $framework): void
    {
        $this->boot($framework);
        $html = (string) $this->get('/scripts-first')->getContent();
        $states = $this->snapshots($html);

        /** What an attribute per component would have carried, escaped for HTML. */
        $asAttributes = array_sum(array_map(fn (array $state) => strlen(htmlspecialchars(json_encode($state['envelope'], JSON_UNESCAPED_SLASHES), ENT_QUOTES)), $states));
        preg_match('/<script type="application\/json" data-nitro-state>(.*?)<\/script>/s', $html, $block);

        $this->assertLessThan($asAttributes, strlen($block[1]));
    }
}
