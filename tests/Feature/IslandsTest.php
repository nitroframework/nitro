<?php

namespace Nitro\Tests\Feature;

use Nitro\Blade\NitroTags;
use Nitro\Build\Bundle;
use Nitro\Compiler\CompileException;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Islands: components in a Blade view that start later (nitro:hydrate) or never (nitro:static).
 * The browser side is in tests/Browser (the islands scenario).
 */
class IslandsTest extends TestCase
{
    #[DataProvider('frameworks')]
    public function test_islands_are_marked_for_the_browser_and_their_chunks_wait(string $framework): void
    {
        $this->boot($framework);
        $html = (string) $this->get('/islands')->getContent();

        foreach (['idle', 'visible:100px', 'interaction', 'media:(min-width: 1200px)'] as $when) {
            $this->assertStringContainsString('nitro:hydrate="'.$when.'"', $html);
        }

        $chunk = $this->app->make(Bundle::class)->chunkId('islands');
        $this->assertDoesNotMatchRegularExpression('#<script src="[^"]*'.preg_quote(rawurlencode($chunk), '#').'#', $html, 'loaded when an island starts, not with the page');
    }

    #[DataProvider('frameworks')]
    public function test_a_static_island_is_its_html_without_state(string $framework): void
    {
        $this->boot($framework);
        $html = (string) $this->get('/islands')->getContent();

        $this->assertSame(1, preg_match('#<section class="static">(.*?)</section>#s', $html, $static));
        $this->assertStringContainsString('<span class="count">0</span>', $static[1]);
        $this->assertStringNotContainsString('nitro:id', $static[1]);
        $this->assertStringNotContainsString('nitro:id', $static[1]);
        $this->assertStringNotContainsString('nitro:hydrate', $static[1]);
    }

    public static function invalid(): array
    {
        return [
            'no moment' => ['<nitro:counter nitro:hydrate />', 'nitro:hydrate takes when the island starts: .idle, .visible (.visible.200px), .interaction or .media="(max-width: 768px)" (on <nitro:counter>)'],
            'unknown moment' => ['<nitro:counter nitro:hydrate.soon />', 'nitro:hydrate takes when the island starts'],
            'margin not in px' => ['<nitro:counter nitro:hydrate.visible.2em />', 'nitro:hydrate takes when the island starts'],
            'media without a query' => ['<nitro:counter nitro:hydrate.media />', 'nitro:hydrate.media on <nitro:counter> needs a media query'],
            'both' => ['<nitro:counter nitro:hydrate.idle nitro:static />', '<nitro:counter> has one nitro:hydrate or nitro:static'],
            'static with a value' => ['<nitro:counter nitro:static="yes" />', 'nitro:static on <nitro:counter> takes no value'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_the_moment_is_checked_when_the_view_compiles(string $tag, string $message): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage($message);

        NitroTags::compile($tag);
    }
}
