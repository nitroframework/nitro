<?php

namespace Nitro\Tests\Feature;

use Nitro\Attributes\Computed;
use Nitro\Attributes\Html;
use Nitro\Attributes\Server;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentReader;
use Nitro\Component;
use Nitro\State\Snapshot;
use Nitro\Store;
use Nitro\Tests\Fixtures\Components\Excerpt;
use Nitro\Tests\Support\Node;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * #[Html]: HTML made on the server, placed by the view; sent beside the signed state, never in it,
 * never back; left out of a call's answer while the browser's fingerprint still matches.
 */
class HtmlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Excerpt::$made = 0;
    }

    /** The page, its excerpt's envelope, and the envelope as the browser keeps it (the HTML taken out). */
    private function page(): array
    {
        $response = $this->get('/excerpt');
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $envelope = $this->snapshots((string) $response->getContent())[0]['envelope'];

        return [(string) $response->getContent(), $envelope, ['state' => $envelope['state'], 'checksum' => $envelope['checksum']]];
    }

    private function call(array $signed, ?string $method, ?array $fingerprints): array
    {
        return $this->json($this->update(array_filter([
            'snapshot' => $signed,
            'html' => $fingerprints,
            'updates' => [],
            'call' => $method === null ? null : ['method' => $method, 'params' => []],
        ], fn ($value) => $value !== null)));
    }

    #[DataProvider('frameworks')]
    public function test_the_html_is_in_the_page_and_beside_the_signed_state_not_in_it(string $framework): void
    {
        $this->boot($framework);
        [$html, $envelope] = $this->page();

        $this->assertStringContainsString('<article><p class="body">Hello &lt;world&gt;</p></article>', $html, 'placed as it is, by the view');
        $this->assertSame([Snapshot::fingerprint('<p class="body">Hello &lt;world&gt;</p>'), '<p class="body">Hello &lt;world&gt;</p>'], $envelope['html']['body']);
        $this->assertStringNotContainsString('class=\"body\"', $envelope['state'], 'not in what is signed');
    }

    #[DataProvider('frameworks')]
    public function test_a_call_leaves_out_the_html_the_browser_has_and_sends_what_changed(string $framework): void
    {
        $this->boot($framework);
        [, $envelope, $signed] = $this->page();
        $fingerprint = $envelope['html']['body'][0];

        $answer = $this->call($signed, 'ping', ['body' => $fingerprint]);
        $this->assertArrayNotHasKey('html', $answer['snapshot'], 'unchanged: the browser keeps its own');
        $this->assertSame('pong', $answer['effects']['returns'] ?? null);

        $answer = $this->call($signed, 'shout', ['body' => $fingerprint]);
        $this->assertSame('<p class="body">HELLO &lt;WORLD&gt;</p>', $answer['snapshot']['html']['body'][1], 'changed: sent');
        $this->assertNotSame($fingerprint, $answer['snapshot']['html']['body'][0]);

        $answer = $this->call($signed, 'ping', null);
        $this->assertSame('<p class="body">Hello &lt;world&gt;</p>', $answer['snapshot']['html']['body'][1], 'no fingerprint: sent');
    }

    #[DataProvider('frameworks')]
    public function test_html_from_the_browser_is_never_read(string $framework): void
    {
        $this->boot($framework);
        [, $envelope] = $this->page();

        /** The whole envelope sent back, its HTML forged: the state still verifies, and the HTML is the server's own. */
        $envelope['html']['body'] = ['forged', '<script>alert(1)</script>'];
        $answer = $this->call($envelope, 'ping', ['body' => ['not' => 'a string'], 'other' => 'x']);

        $this->assertSame('<p class="body">Hello &lt;world&gt;</p>', $answer['snapshot']['html']['body'][1]);
    }

    #[DataProvider('frameworks')]
    public function test_the_browser_gets_the_names_and_not_the_method(string $framework): void
    {
        $this->boot($framework);
        $bundle = implode("\n", $this->app->make(\Nitro\Build\Bundle::class)->compileAll());

        preg_match('/Nitro\.define\("excerpt", \{[\s\S]*?\n\}\);/', $bundle, $definition);
        $this->assertNotEmpty($definition);
        $this->assertStringContainsString('html: ["body"]', $definition[0]);
        $this->assertStringNotContainsString('"body": function', $definition[0], 'it runs on the server only');
        $this->assertStringNotContainsString('<p class', $definition[0], 'nothing of the method is compiled in');
    }

    /** The browser's side (runtime/component/snapshot.js): the HTML kept apart, never in what goes back. */
    public function test_the_browser_keeps_the_html_apart_and_sends_back_its_fingerprints(): void
    {
        $this->assertSame([], Node::run('js/test/support/run-html.mjs', null));
    }

    /** @return array<string, array{0: object, 1: string}> */
    public static function misuses(): array
    {
        return [
            'not string' => [new class extends Component { #[Html] public function body(): int { return 1; } }, 'must return string'],
            'no return type' => [new class extends Component { #[Html] public function body() { return ''; } }, 'must return string'],
            'parameters' => [new class extends Component { #[Html] public function body(string $x): string { return $x; } }, 'cannot take parameters'],
            'also #[Server]' => [new class extends Component { #[Html] #[Server] public function body(): string { return ''; } }, 'is #[Html] only'],
            'also #[Computed]' => [new class extends Component { #[Html] #[Computed] public function body(): string { return ''; } }, 'is #[Html] only'],
            'not public' => [new class extends Component { #[Html] protected function body(): string { return ''; } }, 'must be public'],
            'on a store' => [new class extends Store { #[Html] public function body(): string { return ''; } }, 'a store has none'],
        ];
    }

    #[DataProvider('misuses')]
    public function test_a_misused_html_method_fails_the_build(object $component, string $message): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage($message);

        ComponentReader::read($component::class, 'x', 'nitro.x');
    }
}
