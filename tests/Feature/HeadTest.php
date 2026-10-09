<?php

namespace Nitro\Tests\Feature;

use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page's <head> (@nitroHead): its title in the nitro.head.title format, and the tags search
 * engines and link previews read, from $this->head() in mount(), #[Title] / #[Description], or
 * the nitro.head defaults; in the server's HTML, and with a visit's page data.
 */
class HeadTest extends TestCase
{
    private function visit(string $uri): Response
    {
        return $this->request('GET', $uri, ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_NITRO_NAVIGATE' => '1']);
    }

    #[DataProvider('frameworks')]
    public function test_the_first_load_has_the_head_mount_set(string $framework): void
    {
        $this->boot($framework);

        $html = (string) $this->get('/article')->getContent();

        $this->assertStringContainsString('<title>Nitro &amp; Blade · Fixture</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="How Nitro compiles &lt;Blade&gt; to JavaScript" data-nitro-head>', $html, 'escaped');
        $this->assertStringContainsString('<meta property="og:title" content="Nitro &amp; Blade" data-nitro-head>', $html);
        $this->assertStringContainsString('<meta property="og:image" content="https://example.com/article.png" data-nitro-head>', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image" data-nitro-head>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/article" data-nitro-head>', $html);
        $this->assertStringContainsString('<meta name="author" content="Ada" data-nitro-head>', $html);
        $this->assertStringNotContainsString('Not shown', $html, '$this->head() wins over #[Description]');
        $this->assertStringNotContainsString('robots', $html);
    }

    #[DataProvider('frameworks')]
    public function test_attributes_give_the_head_and_noindex_keeps_it_out_of_search(string $framework): void
    {
        $this->boot($framework);

        $html = (string) $this->get('/notice')->getContent();

        $this->assertStringContainsString('<title>Notice · Fixture</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="A private notice" data-nitro-head>', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex" data-nitro-head>', $html);
        $this->assertStringNotContainsString('og:image', $html, 'no image: none set, no default');
    }

    #[DataProvider('frameworks')]
    public function test_a_visit_brings_the_title_and_the_tags(string $framework): void
    {
        $this->boot($framework);

        $page = $this->json($this->visit('/notice'));

        $this->assertSame('Notice · Fixture', $page['title']);
        $this->assertStringContainsString('<meta name="robots" content="noindex" data-nitro-head>', $page['head']);
        $this->assertStringNotContainsString('<title>', $page['head'], 'the title goes on its own');
    }

    #[DataProvider('frameworks')]
    public function test_a_layout_without_nitro_head_gets_no_tags_on_a_visit(string $framework): void
    {
        $this->boot($framework);

        $this->assertNull($this->json($this->visit('/counter'))['head']);
    }

    #[DataProvider('frameworks')]
    public function test_the_defaults_fill_what_a_page_leaves_out(string $framework): void
    {
        $this->boot($framework);
        $this->app->make('config')->set('nitro.head.image', 'https://example.com/share.png');

        $html = (string) $this->get('/notice')->getContent();

        $this->assertStringContainsString('<meta property="og:image" content="https://example.com/share.png" data-nitro-head>', $html);
    }
}
