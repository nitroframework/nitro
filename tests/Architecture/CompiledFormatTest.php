<?php

namespace Nitro\Tests\Architecture;

use Nitro\Build\Bundle;
use Nitro\Compiler\Globals;
use Nitro\Support\Protocol;
use Nitro\Tests\TestCase;

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

    /**
     * A tree's fields (a node's, a directive's, an attribute's and its value's parts, a handler's):
     * what the compiler writes, for every fixture component, and what the server's render reads
     * (View\TreeRenderer), are listed.
     */
    public function test_a_trees_fields_are_the_listed_ones(): void
    {
        $this->boot('laravel');
        $node = Protocol::get('compiled')['node'];
        $code = implode("\n", $this->app->make(Bundle::class)->compileAll());
        preg_match_all('/template: (\{.*?\n\}),\nexpressions: /s', $code, $templates);
        $this->assertNotEmpty($templates[1]);
        $fields = [];

        foreach ($templates[1] as $json) {
            $this->fields(json_decode($json, true, flags: JSON_THROW_ON_ERROR), $fields);
        }

        $this->assertSame([], array_values(array_diff(array_unique($fields), $node)), 'tree fields the compiler writes that js/protocol.json does not list');

        preg_match_all('/\$(?:node|directive|part|own|first|attribute)\[\'(\w+)\'\]/', Scanner::code()['src/View/TreeRenderer.php'], $read);
        $this->assertSame([], array_values(array_diff(array_unique($read[1]), $node)), 'tree fields the server\'s render reads that js/protocol.json does not list');
    }

    /**
     * A node's fields, and those of what it holds: its directives (i), attributes (a; a call's
     * given ones, d, by name), their parts (h) and handlers (on), its children and slots' nodes.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $fields
     */
    private function fields(array $node, array &$fields): void
    {
        array_push($fields, ...array_keys($node));

        /** A block's directive; a call's i is its inputs' expression. */
        foreach (($node['t'] ?? null) === 'template' ? $node['i'] : [] as $directive) {
            array_push($fields, ...array_keys($directive));
        }

        foreach ([...(array) ($node['a'] ?? []), ...array_merge([], ...array_values((array) ($node['d'] ?? [])))] as $attribute) {
            array_push($fields, ...array_keys($attribute), ...array_keys((array) ($attribute['on'] ?? [])));

            foreach ((array) ($attribute['h'] ?? []) as $part) {
                array_push($fields, ...array_keys($part));
            }
        }

        foreach ([...(array) ($node['h'] ?? []), ...array_merge([], ...array_map(fn (array $slot) => $slot['h'] ?? [], array_values((array) ($node['slots'] ?? []))))] as $child) {
            $this->fields($child, $fields);
        }
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
     * The handle the adapters bind a view to: what they use of it is in the browser's handle
     * (runtime/component/handle.js) and in the render service's (adapters/server/handle.js), so a
     * view renders on the server with the same calls, and a method that is the browser's fails
     * there with Nitro's message, not "is not a function".
     */
    public function test_the_render_services_handle_has_what_the_adapters_use(): void
    {
        $code = Scanner::code();
        $members = function (string $file) use ($code): array {
            preg_match('/\n    return \{\n(.*?)\n    \};/s', $code[$file], $returned);
            preg_match_all('/^ {8}(?:get )?(\w+)\s*(?:\(|:|,)/m', $returned[1] ?? '', $found);

            return $found[1];
        };

        $browser = $members('js/src/runtime/component/handle.js');
        $server = $members('js/adapters/server/handle.js');
        $this->assertContains('get', $browser, 'the browser\'s handle is found');
        $this->assertContains('get', $server, 'the render service\'s handle is found');

        $used = [];

        foreach ($code as $path => $source) {
            if (str_starts_with($path, 'js/adapters/') && ! str_starts_with($path, 'js/adapters/server/')) {
                preg_match_all('/\bhandle\.(\w+)/', $source, $found);
                $used = [...$used, ...$found[1]];
            }
        }

        $used = array_values(array_unique($used));
        $this->assertNotEmpty($used, 'the adapters use the handle');
        $this->assertSame([], array_values(array_diff($used, $browser)), 'what the adapters use of the handle that the browser\'s handle does not have');
        $this->assertSame([], array_values(array_diff($used, $server)), 'what the adapters use of the handle that the render service\'s handle does not have');
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

    /** Nitro's Blade directives: each registered for the server's render and compiled for the browser, none on one side only. */
    public function test_each_nitro_directive_renders_on_both_sides(): void
    {
        $code = Scanner::code();
        preg_match_all("/->directive\('(\w+)'/", $code['src/Blade/Directives.php'], $registered);
        preg_match_all("/^\s*'(\w+)'(?:, '\w+')* =>/m", $code['src/Compiler/Template/Parts/Directives.php'], $compiled);

        foreach (Globals::DIRECTIVES as $directive) {
            $this->assertContains($directive, $registered[1], "@{$directive} (Globals::DIRECTIVES) is not registered with Blade: the server could not render it");
            $this->assertContains($directive, $compiled[1], "@{$directive} (Globals::DIRECTIVES) is not compiled for the browser");
        }
    }

    /** The globals @nitroScripts gives the browser (B.globals): what the server sends and the runtime reads, both listed. */
    public function test_the_browser_globals_are_the_listed_ones(): void
    {
        $listed = Protocol::get('globals');
        $this->boot('laravel');
        $this->app->instance('request', \Illuminate\Http\Request::create('/'));
        $sent = array_keys($this->app->make(\Nitro\Rendering\Scripts::class)->globals());
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

    /** A request to the update endpoint (a call, a check), and a check's answer: what the runtime sends, what UpdateController reads, the listed fields only. */
    public function test_a_requests_fields_are_the_listed_ones(): void
    {
        $request = Protocol::get('request');
        $answer = Protocol::get('answer');
        $code = Scanner::code();
        $network = $code['js/src/runtime/server/network.js'];
        $update = $code['src/Http/UpdateController.php'];

        preg_match_all('/const body = \{\n(.*?)\n        \};/s', $network, $bodies);
        $this->assertCount(2, $bodies[1], 'a call\'s body and a check\'s are found in network.js');
        preg_match_all('/^ {12}(\w+):/m', implode("\n", $bodies[1]), $sent);
        $this->assertSame([], array_values(array_diff(array_unique($sent[1]), $request['update'])), 'request fields the runtime sends that js/protocol.json does not list');

        preg_match_all("/\\\$payload\['(\w+)'\]/", $update, $read);
        $this->assertNotEmpty($read[1], 'the request is found in UpdateController');
        $this->assertSame([], array_values(array_diff(array_unique($read[1]), $request['update'])), 'request fields UpdateController reads that js/protocol.json does not list');

        preg_match_all("/\\\$entry\['(\w+)'\]/", $update, $store);
        $this->assertSame([], array_values(array_diff(array_unique($store[1]), $request['store'])), 'a store\'s request fields UpdateController reads that js/protocol.json does not list');

        preg_match_all("/\\\$call\['(\w+)'\]/", $update, $call);
        $this->assertSame([], array_values(array_diff(array_unique($call[1]), $request['call'])), 'a call\'s fields UpdateController reads that js/protocol.json does not list');

        preg_match_all("/new JsonResponse\(\['(\w+)' =>/", $update, $checked);
        $this->assertNotEmpty($checked[1], 'a check\'s answer is found in UpdateController');
        $this->assertSame([], array_values(array_diff($checked[1], $answer['check'])), 'check answer fields UpdateController sends that js/protocol.json does not list');

        preg_match('/async sendCheck\(.*?\n    \}\n/s', $network, $check);
        preg_match_all('/\banswer\.(\w+)/', $check[0] ?? '', $checkRead);
        $this->assertNotEmpty($checkRead[1], 'the runtime reads a check\'s answer in network.js');
        $this->assertSame([], array_values(array_diff(array_unique($checkRead[1]), $answer['check'])), 'check answer fields the runtime reads that js/protocol.json does not list');
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
