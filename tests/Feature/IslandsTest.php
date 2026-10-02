<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Blade\FusionTags;
use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Islands: components in a Blade view that start later (fusion:hydrate) or never (fusion:static).
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
            $this->assertStringContainsString('fusion:hydrate="'.$when.'"', $html);
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
        $this->assertStringNotContainsString('fusion:snapshot', $static[1]);
        $this->assertStringNotContainsString('fusion:id', $static[1]);
        $this->assertStringNotContainsString('fusion:hydrate', $static[1]);
    }

    public static function invalid(): array
    {
        return [
            'no moment' => ['<fusion:counter fusion:hydrate />', 'fusion:hydrate takes when the island starts: .idle, .visible (.visible.200px), .interaction or .media="(max-width: 768px)" (on <fusion:counter>)'],
            'unknown moment' => ['<fusion:counter fusion:hydrate.soon />', 'fusion:hydrate takes when the island starts'],
            'margin not in px' => ['<fusion:counter fusion:hydrate.visible.2em />', 'fusion:hydrate takes when the island starts'],
            'media without a query' => ['<fusion:counter fusion:hydrate.media />', 'fusion:hydrate.media on <fusion:counter> needs a media query'],
            'both' => ['<fusion:counter fusion:hydrate.idle fusion:static />', '<fusion:counter> has one fusion:hydrate or fusion:static'],
            'static with a value' => ['<fusion:counter fusion:static="yes" />', 'fusion:static on <fusion:counter> takes no value'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_the_moment_is_checked_when_the_view_compiles(string $tag, string $message): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage($message);

        FusionTags::compile($tag);
    }
}
