<?php

namespace Nitro\Fusion\Tests\Architecture;

use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Compiler\Globals;
use Nitro\Fusion\Support\Protocol;
use Nitro\Fusion\Tests\TestCase;

/**
 * The compiled component the browser gets (Compiler\Template\TemplateNode's format, and the
 * definition around it) is written by the PHP compiler and read by the runtime: two sides of one
 * format, kept from drifting by js/protocol.json's "compiled" list. What the compiler emits, for
 * every fixture component, must be listed; what is listed, the runtime must handle; what the
 * runtime reads of a definition, the compiler must give.
 */
final class CompiledFormatTest extends TestCase
{
    /** @return array{types: list<string>, blocks: list<string>, fields: list<string>} */
    private function emitted(): array
    {
        $this->boot('laravel');
        $code = implode("\n", $this->app->make(Bundle::class)->compileAll());
        $found = ['types' => [], 'blocks' => [], 'fields' => []];

        preg_match_all('/^([a-zA-Z]+): /m', $code, $fields);
        $found['fields'] = array_values(array_unique($fields[1]));

        preg_match_all('/template: (\{.*?\n\}),\nexpressions: /s', $code, $templates);

        foreach ($templates[1] as $json) {
            $this->walk(json_decode($json, true, flags: JSON_THROW_ON_ERROR), $found);
        }

        return array_map(fn (array $values) => array_values(array_unique($values)), $found);
    }

    /** @param  array{types: list<string>, blocks: list<string>, fields: list<string>}  $found */
    private function walk(mixed $node, array &$found): void
    {
        if (! is_array($node)) {
            return;
        }

        if (isset($node['t']) && is_string($node['t'])) {
            $found['types'][] = $node['t'];
        }

        foreach ((array) ($node['i'] ?? []) as $directive) {
            if (is_array($directive) && is_string($directive['c'] ?? null)) {
                $found['blocks'][] = $directive['c'];
            }
        }

        foreach ($node as $child) {
            $this->walk($child, $found);
        }
    }

    public function test_the_compiler_emits_only_what_the_protocol_lists(): void
    {
        $compiled = Protocol::get('compiled');
        $emitted = $this->emitted();

        /** The root is known by its place: the runtime renders what is in it. */
        $this->assertSame([], array_values(array_diff($emitted['types'], [$compiled['root'], ...$compiled['types']])), 'node types the compiler emits that js/protocol.json does not list');
        $this->assertSame([], array_values(array_diff($emitted['blocks'], $compiled['blocks'])), 'block kinds the compiler emits that js/protocol.json does not list');
        $this->assertSame([], array_values(array_diff($emitted['fields'], $compiled['fields'])), 'definition fields the compiler gives that js/protocol.json does not list');
    }

    public function test_the_runtime_handles_every_listed_type_and_block(): void
    {
        $compiled = Protocol::get('compiled');
        $view = implode("\n", array_filter(Scanner::code(), fn (string $path) => str_starts_with($path, 'js/src/runtime/view/'), ARRAY_FILTER_USE_KEY));

        foreach ([...$compiled['types'], ...$compiled['blocks']] as $code) {
            $this->assertMatchesRegularExpression('/case \''.preg_quote($code, '/').'\'|\.(c|t) === \''.preg_quote($code, '/').'\'/', $view, "js/protocol.json lists [{$code}]: the runtime's view must handle it");
        }
    }

    /**
     * A JavaScript view's two formats: the state the server sends the render service (ViewState),
     * and the names its definition gives the view's handle (the compiler's "names").
     */
    public function test_a_javascript_views_formats_are_the_listed_ones(): void
    {
        $view = Protocol::get('view');
        $code = Scanner::code();

        preg_match('/function jsonSerialize\(\).*?return \[(.*?)\];/s', $code['src/View/ServerRendering/ViewState.php'], $serialized);
        preg_match_all("/'(\w+)' =>/", $serialized[1] ?? '', $sent);
        $this->assertSame($view['state'], $sent[1], 'ViewState sends what js/protocol.json lists, in its order');

        preg_match_all('/state\.(\w+)/', $code['js/adapters/server/handle.js'].$code['js/adapters/server/serve.js'], $read);
        $this->assertSame([], array_values(array_diff(array_unique($read[1]), $view['state'])), 'the render service reads only listed view state');

        $this->boot('laravel');
        preg_match_all('/^names: (\{.*\}),$/m', implode("\n", $this->app->make(Bundle::class)->compileAll()), $names);
        $this->assertNotEmpty($names[1], 'the fixtures have JavaScript views');

        foreach ($names[1] as $json) {
            $this->assertSame($view['names'], array_keys(json_decode($json, true, flags: JSON_THROW_ON_ERROR)), 'the compiler gives the names js/protocol.json lists');
        }

        preg_match_all('/names\??\.(\w+)/', $code['js/src/runtime/view/script.js'], $used);
        $this->assertSame([], array_values(array_diff(array_unique($used[1]), $view['names'])), 'the view\'s handle reads only listed names');
    }

    /** Each prop of a definition: its fields, and its #[Url]'s. */
    public function test_a_props_fields_are_the_listed_ones(): void
    {
        $compiled = Protocol::get('compiled');
        $this->boot('laravel');
        preg_match_all('/^props: (\{.*\}),$/m', implode("\n", $this->app->make(Bundle::class)->compileAll()), $props);
        $this->assertNotEmpty($props[1]);
        $fields = [];
        $url = [];

        foreach ($props[1] as $json) {
            foreach (json_decode($json, true, flags: JSON_THROW_ON_ERROR) as $prop) {
                $fields = [...$fields, ...array_keys($prop)];
                $url = [...$url, ...array_keys((array) ($prop['url'] ?? []))];
            }
        }

        $this->assertSame([], array_values(array_diff(array_unique($fields), $compiled['prop'])), 'prop fields the compiler gives that js/protocol.json does not list');
        $this->assertSame([], array_values(array_diff(array_unique($url), $compiled['url'])), '#[Url] fields the compiler gives that js/protocol.json does not list');

        $runtime = implode("\n", array_filter(Scanner::code(), fn (string $path) => str_starts_with($path, 'js/src/runtime/'), ARRAY_FILTER_USE_KEY));
        preg_match_all('/\bprop\??\.(\w+)|props\[\w+\]\??\.(\w+)/', $runtime, $read);
        $this->assertSame([], array_values(array_diff(array_filter(array_unique([...$read[1], ...$read[2]])), $compiled['prop'])), 'prop fields the runtime reads that js/protocol.json does not list');
    }

    /** Fusion's Blade directives: each registered for the server's render and compiled for the browser, none on one side only. */
    public function test_each_fusion_directive_renders_on_both_sides(): void
    {
        $code = Scanner::code();
        preg_match_all("/Blade::directive\('(\w+)'/", $code['src/FusionServiceProvider.php'], $registered);
        preg_match_all("/^\s*'(\w+)'(?:, '\w+')* =>/m", $code['src/Compiler/Template/Parts/Directives.php'], $compiled);

        foreach (Globals::DIRECTIVES as $directive) {
            $this->assertContains($directive, $registered[1], "@{$directive} (Globals::DIRECTIVES) is not registered with Blade: the server could not render it");
            $this->assertContains($directive, $compiled[1], "@{$directive} (Globals::DIRECTIVES) is not compiled for the browser");
        }
    }

    /** The globals @fusionScripts gives the browser (B.globals): what the server sends and the runtime reads, both listed. */
    public function test_the_browser_globals_are_the_listed_ones(): void
    {
        $listed = Protocol::get('globals');
        $this->boot('laravel');
        $this->app->instance('request', \Illuminate\Http\Request::create('/'));
        $sent = array_keys($this->app->make(\Nitro\Fusion\Rendering\Scripts::class)->globals());
        $this->assertSame([], array_values(array_diff($sent, $listed)), 'globals Scripts sends that js/protocol.json does not list');

        $read = [];

        foreach (Scanner::code() as $path => $code) {
            /** index.js's own globals is the <script> element that holds them. */
            if (str_starts_with($path, 'js/src/') && $path !== 'js/src/index.js') {
                preg_match_all('/\bglobals\.(\w+)/', $code, $matches);
                $read = [...$read, ...$matches[1]];
            }
        }

        $this->assertSame([], array_values(array_diff(array_unique($read), $listed)), 'globals the runtime reads that js/protocol.json does not list');
    }

    /** A snapshot's state and memo (State\Snapshot writes them, the runtime's readState() reads them): the listed fields only. */
    public function test_a_snapshots_fields_are_the_listed_ones(): void
    {
        $snapshot = Protocol::get('snapshot');
        $code = Scanner::code();
        $php = $code['src/State/Snapshot.php'];

        preg_match('/Wire::json\(\[(.*?)\]\)/', $php, $state);
        preg_match_all("/'(\w+)' =>/", $state[1] ?? '', $stateFields);
        $this->assertSame($snapshot['state'], $stateFields[1], 'Snapshot writes the state js/protocol.json lists, in its order');

        /** The memo: what Snapshot writes (from the component's Context, or kept for a partial reload), what any part reads. */
        preg_match_all("/'(\w+)' => (?:\\\$context->|array_intersect_key\(|\\\$this->memo\[)/", $php, $written);
        $read = [];

        foreach ($code as $path => $source) {
            if (str_starts_with($path, 'src/')) {
                preg_match_all("/memo\['(\w+)'\]/", $source, $matches);
                $read = [...$read, ...$matches[1]];
            }
        }

        preg_match_all("/memo\.get\('(\w+)'\)/", $code['js/src/runtime/component/snapshot.js'], $browser);
        $this->assertSame([], array_values(array_diff(array_unique([...$written[1], ...$read, ...$browser[1]]), $snapshot['memo'])), 'memo fields that js/protocol.json does not list');

        preg_match_all("/position\.get\('(\w+)'\)/", $code['js/src/runtime/component/component.js'], $position);
        preg_match_all("/'scroll' => \[(.*?)\]/s", $code['src/Merge.php'], $scrollBlocks);
        preg_match_all("/'(\w+)' =>/", implode("\n", $scrollBlocks[1]), $paginator);
        $this->assertNotEmpty($paginator[1], 'Merge writes the scroll position');
        $this->assertSame([], array_values(array_diff(array_unique([...$position[1], ...$paginator[1]]), $snapshot['position'])), 'scroll position fields that js/protocol.json does not list');

        preg_match_all("/rule\.get\('(\w+)'\)/", $code['js/src/runtime/component/snapshot.js'], $rule);
        $this->assertSame([], array_values(array_diff(array_unique($rule[1]), $snapshot['merge'])), 'merge rule fields the runtime reads that js/protocol.json does not list');
    }

    /** The answer to a #[Server] call, and a visit's page data: what the server sends, what the runtime reads, the listed fields only. */
    public function test_an_answers_fields_are_the_listed_ones(): void
    {
        $answer = Protocol::get('answer');
        $code = Scanner::code();
        $update = $code['src/Http/UpdateController.php'];

        preg_match('/return new JsonResponse\(\[\n(.*?)\n        \]\);/s', $update, $call);
        preg_match_all("/^ {12}'(\w+)' =>/m", $call[1] ?? '', $callFields);
        preg_match_all("/^ {16}'(\w+)' =>/m", $call[1] ?? '', $effects);
        preg_match_all("/^ {20}'(\w+)' =>/m", $call[1] ?? '', $dispatch);
        $this->assertNotEmpty($callFields[1], 'the call\'s answer is found in UpdateController');
        $this->assertSame([], array_values(array_diff($callFields[1], $answer['call'])), 'answer fields UpdateController sends that js/protocol.json does not list');
        $this->assertSame([], array_values(array_diff($effects[1], $answer['effects'])), 'effects UpdateController sends that js/protocol.json does not list');
        $this->assertSame([], array_values(array_diff($dispatch[1], $answer['dispatch'])), 'dispatch fields UpdateController sends that js/protocol.json does not list');

        preg_match('/return new JsonResponse\(\[\n(.*?)\n        \]/s', $code['src/Http/PageResponses.php'], $page);
        preg_match_all("/^ {12}'(\w+)' =>/m", $page[1] ?? '', $pageFields);
        $this->assertNotEmpty($pageFields[1], 'a visit\'s page data is found in PageResponses');
        $this->assertSame([], array_values(array_diff($pageFields[1], $answer['page'])), 'page data fields PageResponses sends that js/protocol.json does not list');

        $runtime = $code['js/src/runtime/navigation/navigate.js'].$code['js/src/runtime/navigation/page-mount.js'].$code['js/src/runtime/server/network.js'];
        preg_match_all('/\bpage\.(\w+)/', $runtime, $pageRead);
        $this->assertSame([], array_values(array_diff(array_unique($pageRead[1]), $answer['page'])), 'page data fields the runtime reads that js/protocol.json does not list');

        preg_match_all('/\beffects\.(\w+)/', $code['js/src/runtime/server/effects.js'].$code['js/src/runtime/server/network.js'], $effectsRead);
        $this->assertSame([], array_values(array_diff(array_unique($effectsRead[1]), $answer['effects'])), 'effects the runtime reads that js/protocol.json does not list');

        preg_match('/const \{ ([\w, ]+) \} of effects\.dispatches/', $code['js/src/runtime/server/effects.js'], $dispatchRead);
        $this->assertSame([], array_values(array_diff(array_map('trim', explode(',', $dispatchRead[1] ?? '')), $answer['dispatch'])), 'dispatch fields the runtime reads that js/protocol.json does not list');
    }

    public function test_the_runtime_reads_only_listed_definition_fields(): void
    {
        $compiled = Protocol::get('compiled');
        $read = [];

        foreach (Scanner::code() as $path => $code) {
            if (str_starts_with($path, 'js/src/runtime/')) {
                preg_match_all('/definition\??\.([a-zA-Z]+)/', $code, $matches);
                $read = [...$read, ...$matches[1]];
            }
        }

        $this->assertSame([], array_values(array_diff(array_unique($read), $compiled['fields'])), 'definition fields the runtime reads that js/protocol.json does not list');
    }
}
