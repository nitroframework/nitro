<?php

namespace Nitro\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each concept has one home, and the rest of Nitro asks it. A rule names what may only be
 * written in its home (a pattern, in code, comments out), and the home. Today's known breaks are
 * in known-breaks.php, each with the TASKS item that fixes it: a new break fails, and a fixed one
 * still listed fails too, so the baseline only shrinks.
 */
final class HomesTest extends TestCase
{
    /**
     * @return array<string, array{patterns: list<string>, homes: array<string, string>, why: string}>
     */
    public static function rules(): array
    {
        return [
            'hook names' => [
                'patterns' => [
                    '/[\[(,]\s*\$?\w*\s*,?\s*\'(boot|mount|dehydrate|exception|mounted|rendered|destroyed)\'\s*[,\])]|call\(.*\'hydrate\'|\'(updating|updated)\'|\/\^updated/',
                    '/[\'"](updating|updated|mounted|rendered|destroyed)[\'"]/',
                ],
                'homes' => [
                    'src/Support/ComponentHooks.php' => 'the component hooks',
                    'src/Support/PropertyHooks.php' => 'the property hooks',
                    'js/src/runtime/component/hooks.js' => 'calls them, by the definition',
                    'js/src/runtime/component/component.js' => 'tells hooks.js when (updating, updated)',
                    'js/src/runtime/runtime.js' => 'tells hooks.js when (mounted)',
                ],
                'why' => 'A #[Server] updated hook was ignored once: the compiler and the lifecycle had their own lists',
            ],
            'navigation modes' => [
                'patterns' => ['/(===|!==|in_array\(.*)\s*\'(markup|client)\'|\[\s*\'(data|markup|client)\'\s*,/'],
                'homes' => ['src/NavigationMode.php' => 'the modes and what each means'],
                'why' => 'nitro.navigation and #[Navigate] were checked against two different lists',
            ],
            'view engine names' => [
                'patterns' => ["/'(vue|react|solid|svelte)'/"],
                'homes' => [
                    'src/View/Adapters/VueAdapter.php' => 'its engine',
                    'src/View/Adapters/ReactAdapter.php' => 'its engine',
                    'src/View/Adapters/SolidAdapter.php' => 'its engine',
                    'src/View/Adapters/SvelteAdapter.php' => 'its engine',
                    'js/adapters/vue.js' => 'its engine',
                    'js/adapters/react.js' => 'its engine',
                    'js/adapters/solid.js' => 'its engine',
                    'js/adapters/svelte.svelte.js' => 'its engine',
                    'js/adapters/server/vue.js' => 'its engine, rendered on the server',
                    'js/adapters/server/react.js' => 'its engine, rendered on the server',
                    'js/adapters/server/solid.js' => 'its engine, rendered on the server',
                    'js/adapters/server/svelte.js' => 'its engine, rendered on the server',
                    'js/vite.js' => 'the module names the app imports, and one copy of each framework',
                ],
                'why' => 'Engines are nitro.views\' entries; a list of them goes stale when one is added',
            ],
            'signing' => [
                'patterns' => ['/hash_hmac\(|hash_equals\(/'],
                'homes' => [
                    'src/Support/Signer.php' => 'signs and verifies, with the previous keys',
                    'src/Compiler/Obfuscator.php' => 'aliases from the key: names, not signatures',
                ],
                'why' => 'Two signers disagreed on key rotation (TASKS 17.11)',
            ],
            'protocol: HTTP headers' => [
                'patterns' => ['/X-Nitro\b/i'],
                'homes' => ['src/Support/Protocol.php' => 'reads js/protocol.json, which the runtime imports'],
                'why' => 'Both sides spell each header; one was a literal on one side only (TASKS 17.3)',
            ],
            'protocol: attributes Nitro puts on elements' => [
                /** As a value (a whole string, a selector, a template): an error message may name them in its prose. */
                'patterns' => ['/[\'"`]nitro:(id|name|snapshot|page|placeholder|rendered|static|hydrate)[.\'"`]|[\'"`]nitro:h-|\[nitro\\\\{1,2}:(id|name|snapshot|page|placeholder|rendered|static|hydrate|h-)|nitro:h-\$\{|<\w+ nitro:rendered/'],
                'homes' => ['src/Support/Protocol.php' => 'reads js/protocol.json, which the runtime imports'],
                'why' => 'Written in PHP, read in the runtime, each spelling them (TASKS 17.2)',
            ],
            'protocol: endpoint paths' => [
                'patterns' => ['/[\'"`}]\/(update|upload)\b|\/upload\/preview|\/app\/\{(id|group)\}\.js/'],
                'homes' => ['src/Support/Protocol.php' => 'reads js/protocol.json, which the runtime imports'],
                'why' => 'Routes in one place, URLs built in five (TASKS 17.4)',
            ],
            'values from a URL' => [
                'patterns' => ['/FILTER_VALIDATE_INT|\'\/\^-\?\\\\d\+\$\/\'|\/\^\[\+-\]\?\(0\|\[1-9\]/'],
                'homes' => ['src/Support/UrlValues.php' => 'the one rule set, given to the browser with the client pages'],
                'why' => 'Three rules for an int in a URL, and they differ (TASKS 17.1)',
            ],
            'scalar types' => [
                'patterns' => ["/'int',\s*'float',\s*'string'/", "/'int'\s*=>\s*is_int\(/"],
                'homes' => [
                    'src/Support/Types.php' => 'the scalar types, and whether a value fits one',
                    'src/Support/UrlValues.php' => 'the types a URL segment can fill',
                ],
                'why' => 'The same match in two places, the list in three (TASKS 17.12)',
            ],
            'HTML element rules' => [
                'patterns' => ["/'area',\s*'base',\s*'br'/", "/\['script',\s*'style'/"],
                'homes' => ['src/Support/Html.php' => 'void, raw-text and RCDATA elements'],
                'why' => 'The void elements copied in two places (TASKS 17.15)',
            ],
            'durations' => [
                'patterns' => ['/\(ms\|s\)|m\?s\$/'],
                'homes' => [
                    'src/Support/Duration.php' => 'PHP: what the compiler accepts',
                    'js/src/runtime/dom/events.js' => 'the runtime: duration()',
                ],
                'why' => 'Parsed in three runtime files, checked by three patterns (TASKS 17.16)',
            ],
            'the registry cache file' => [
                'patterns' => ['#cache/nitro\.php#'],
                'homes' => ['src/Support/Registry.php' => 'its cache'],
                'why' => 'Its path in three files (TASKS 17.17)',
            ],
            'what a <nitro:...> tag may name' => [
                /** A tag's name read by a pattern of its own: the server, the browser compiler and the build would disagree on a name. */
                'patterns' => ['/<(\\\\\/)?nitro:\(\[/', '/\[a-z0-9\]\[a-z0-9\\\\-\.\]\*/'],
                'homes' => ['src/Compiler/Template/ChildTag.php' => 'NAME, which PATTERN and every closing tag read'],
                'why' => 'The tag\'s name was matched by four patterns: the Blade precompiler, the template compiler, its closing tags and the build\'s',
            ],
            'the runtime\'s moments (hooks)' => [
                /** Each moment announced in one place: its listeners, its window event, its earlier name. */
                'patterns' => [
                    "/['\"`]nitro:(init|starting|before-request|request|after-request|response|before-navigate|navigating|after-navigate|navigated|flash|error|expired)['\"`]/",
                    '/\.dispatchEvent\(new [\w.]*CustomEvent\(|\.emit\(/',
                ],
                'homes' => [
                    'js/src/runtime/hooks.js' => 'the hooks: their names, listeners and window events',
                    'js/src/runtime/runtime.js' => 'emit(): the application\'s own events ($this->dispatch())',
                    'js/adapters/core.js' => 'an adapter loaded before Nitro waits for nitro:init, as Nitro.hook() is not there yet',
                    'js/src/runtime/server/upload.js' => 'nitro-upload-* on the file input: its own DOM events, not the runtime\'s moments',
                ],
                'why' => 'The moments were announced from five files, two ways for one call: one home keeps their names, order and payloads one',
            ],
            'the page\'s globals' => [
                /** ARCHITECTURE.md, "What Nitro shares": the window is every script's. */
                'patterns' => ['/\bwindow\.[A-Za-z_$][\w$]*\s*=(?!=)|\bglobalThis\.[A-Za-z_$][\w$]*\s*=(?!=)/'],
                'homes' => ['js/src/index.js' => 'window.Nitro, Nitro\'s one global'],
                'why' => 'Another global is a name another script on the page may use too',
            ],
            'browser storage and tab channels' => [
                /** Each key and channel name starts with "nitro:", beside the app's and other scripts' own. */
                'patterns' => ['/\b(localStorage|sessionStorage|BroadcastChannel)\b/'],
                'homes' => [
                    'js/src/runtime/runtime.js' => 'nitro:stale, the page stale() loaded again',
                    'js/src/plugins/persist.js' => 'nitro:persist:{store}, #[Persist]',
                    'js/src/plugins/sync.js' => 'nitro:sync:{store}, #[Sync]',
                ],
                'why' => 'Storage and channels are shared with every script of the site: a key without nitro: can be another\'s',
            ],
            'state that outlives a request' => [
                /** Under a long-lived worker (Octane) a static property lasts for every request after: only what is read from the code. */
                'patterns' => ['/(private|protected|public)\s+static\s+[?\w|\\\\]*\s*\$\w+|->singleton\(/'],
                'homes' => [
                    'src/Compiler/MethodSource.php' => 'parsed methods, by file and modification time',
                    'src/Compiler/BrowserClasses.php' => 'which classes are #[Browser]',
                    'src/Compiler/Functions.php' => 'the PHP functions the browser has',
                    'src/Lifecycle/AttributeHandlers.php' => 'a class\'s feature attributes',
                    'src/Support/Protocol.php' => 'js/protocol.json',
                    'src/View/Adapters/SharedCore.php' => 'the names the adapters share',
                ],
                'why' => 'A request\'s data kept there would reach the next visitor; Nitro\'s parts are scoped, one per request',
            ],
            '#[Html] beside the signed state' => [
                /** The HTML that goes beside a snapshot, and the fingerprints that come back in its place: one home each side. */
                'patterns' => ["/get\('snapshot'\)\['html'\]|HTML_FIELD|snapshot\.html\b/"],
                'homes' => [
                    'src/State/Snapshot.php' => 'writes the HTML beside the signed state, leaving out what the browser has',
                    'src/State/Snapshots.php' => 'reads the fingerprints a call sends',
                    'js/src/runtime/component/snapshot.js' => 'keeps the HTML apart from the snapshot, and gives the fingerprints a call sends',
                    'js/src/runtime/protocol.js' => 'names the field, from js/protocol.json',
                ],
                'why' => 'HTML kept with the snapshot anywhere else would go back to the server with every call: megabytes for a long file',
            ],
            'a name\'s kind and namespace' => [
                /** "pages::", "stores::" and a namespace's "issues::": written and read in one place each side. */
                'patterns' => ["/'(pages|stores)::|\\$(name|view)\w*\s*,\s*'::'|'::'\s*,\s*\\$(name|view)|\.namespaceSeparator/"],
                'homes' => [
                    'src/Support/Naming.php' => 'writes names and reads them: their kind, their namespace, the name within it',
                    'js/adapters/core.js' => 'findView() and namespaced(): a namespace\'s views in the browser and the render service',
                ],
                'why' => 'A name split by hand where it is used reads "pages::issues::list" as the page "issues::list" in one place and the namespace "pages" in another',
            ],
            'where components are' => [
                /** nitro.components, nitro.pages and nitro.stores: read once, and asked of the registry. */
                'patterns' => ["/get\('nitro\.(components|pages|stores)'\)|config\('nitro\.(components|pages|stores)/", "/\['namespace'\]/"],
                'homes' => [
                    'src/NitroServiceProvider.php' => 'gives the configured places to the registry',
                    'src/Support/Registry.php' => 'its list of locations: names, discovery, views, where a new class goes',
                ],
                'why' => 'Each of the registry\'s questions read the places itself, each with its own list of them',
            ],
            'the browser data block' => [
                'patterns' => ["/'once'\s*=>\s*\(object\)/"],
                'homes' => ['src/State/SharedData.php' => 'forBrowser()'],
                'why' => 'Put together in three places, each a little differently (TASKS 17.13)',
            ],
            'signed state in and out' => [
                'patterns' => ['/Snapshot::fromEnvelope\(|->toJson\(\$/'],
                'homes' => ['src/State/Snapshots.php' => 'reads and writes the browser\'s signed state'],
                'why' => 'Keys, names and lifetime passed by hand at each place (TASKS 17.14)',
            ],
            'a page\'s data in its HTML' => [
                /** How a component's state and the globals travel in a page: written and read in one place each. */
                'patterns' => ["/attribute\('(snapshot|globals)'\)|ATTRIBUTES\.(snapshot|globals)|selector\('snapshot'\)|data-nitro-globals/"],
                'homes' => [
                    'src/Rendering/PageData.php' => 'writes them into a page, reads them back from one',
                    'js/src/runtime/page-data.js' => 'the runtime reads them',
                ],
                'why' => 'Written and read in seven files, so moving the state to a page-level block meant changing them all',
            ],
            'state from the browser, trusted' => [
                /** Opening what the browser sent, and the page's middleware again: the security checks, in one place. */
                'patterns' => ['/->open\(|persistent->check\(/'],
                'homes' => ['src/Http/IncomingState.php' => 'opens it, checks it is whole, known, and still admitted'],
                'why' => 'A call, a store and an upload each opened and checked the state themselves: the upload\'s copy accepted a partial reload\'s',
            ],
            'whether the application is in development' => [
                'patterns' => ["/['\"]app\.debug['\"]/"],
                'homes' => ['src/NitroServiceProvider.php' => 'gives it to Support\\Config::debug(), which everything asks'],
                'why' => 'Fallbacks, the dev overlay and compile errors each read APP_DEBUG themselves',
            ],
            'fallbacks' => [
                /** A failure turned into what shows in a component's place: worked out, marked and shown in one place each. */
                'patterns' => ["/attribute\('fallback'\)|ATTRIBUTES\.fallback|ComponentHooks::FALLBACK/"],
                'homes' => [
                    'src/Rendering/Fallbacks.php' => 'the fallback a component that failed shows, and the error reported',
                    'js/src/runtime/component/component.js' => 'fail(): it shows in the component\'s place in the browser',
                ],
                'why' => 'A fallback worked out where each failure is caught would differ: the server\'s, a call\'s and the browser\'s',
            ],
            'cached results' => [
                /** The application's cache, read and written for #[Cached]: the keys, the tags' versions. */
                'patterns' => ["/(?<![\w\\\\])Cache::|\bcache\(\)|make\('cache'\)|Cache\\\\Repository|->forever\(|->remember\(/"],
                'homes' => [
                    'src/State/MethodCache.php' => 'keeps and finds #[Cached] results, expires tags',
                    'src/NitroServiceProvider.php' => 'gives it the store (nitro.cache.store)',
                ],
                'why' => 'Keys and tags written where a result is used would differ: a page kept under one key, expired under another',
            ],
            'a state by its own names' => [
                'patterns' => ['/->fromBrowser\(/'],
                'homes' => ['src/State/Snapshots.php' => 'ownNames(): the component\'s and its properties\', an obfuscated build\'s aliases read'],
                'why' => 'Each place that took a state from the browser read its names itself: the component, a store, a partial reload, the test helper',
            ],
            'the current request' => [
                /** Asked for each time (Nitro serves many requests with one application), null when there is none. */
                'patterns' => ["/bound\('request'\)|make\('request'\)|app\('request'\)|csrf_token\(/"],
                'homes' => ['src/Support/CurrentRequest.php' => 'the request being answered, and its session'],
                'why' => 'Seven places found the request themselves, three its session, each guarding it a little differently',
            ],
            'what @nitroScripts tells the runtime' => [
                'patterns' => ['/data-(bundle|csrf|endpoint|chunks|progress|fallback|view-transitions)\b|dataset\.(bundle|csrf|endpoint|chunks|progress|fallback|viewTransitions)\b|config\.csrf/'],
                'homes' => [
                    'src/Rendering/Scripts.php' => 'writes the runtime\'s script',
                    'js/src/runtime/page-data.js' => 'the runtime reads it: runtimeScriptOf(), configOf()',
                ],
                'why' => 'Read at start and again on a visit, the CSRF token kept in two variables',
            ],
            'names to and from the browser' => [
                'patterns' => ['/obfuscator\(\)\??->/'],
                'homes' => ['src/Build/BrowserNames.php' => 'the browser\'s names for what Nitro sends, and what a name it sent stands for'],
                'why' => 'Each controller read and wrote an obfuscated build\'s names itself, nine times',
            ],
            'a component\'s and a store\'s state in the browser' => [
                /** Defaults, what changed since the server signed, the $this properties: one implementation for both. */
                'patterns' => ["/'default' in \w+\)|serverData\.get\(name\) !== value|Object\.defineProperty\(ctx, name,/"],
                'homes' => [
                    'js/src/runtime/component/snapshot.js' => 'defaults, what changed since the server signed',
                    'js/src/runtime/component/context.js' => 'the properties of a $this',
                ],
                'why' => 'A store repeated the component\'s: its properties, updates(), sameValue(), and defaults were read in three places',
            ],
            'a method\'s source' => [
                'patterns' => ['/findInstanceOf\([^;]*Stmt\\\\ClassMethod::class\)/'],
                'homes' => ['src/Compiler/MethodSource.php' => 'a method\'s syntax tree, its file parsed once'],
                'why' => 'The component compiler and the #[Browser] classes each parsed a file to find a method; the component\'s copy parsed it again for every method',
            ],
            'starting an instance' => [
                /** Its id and name, boot, its inputs, the features' mounting, mount(): in one order for every kind. */
                'patterns' => ['/->mounting\(|nitro\(\)->(id|name) =/'],
                'homes' => [
                    'src/Lifecycle/Features.php' => 'start(): a component, a page, a store',
                    'src/Lifecycle/AttributeHandlers.php' => 'hands mounting to each attribute',
                ],
                'why' => 'A page, a component and a store each started themselves: a page set its route parameters after #[Url], over it',
            ],
            'requests to the server' => [
                /** The browser sends in one place: Nitro's headers, the plugins', what every answer is checked for. */
                'patterns' => ['/\bwindow\.fetch\(|(?<![.\w$])fetch\(|XMLHttpRequest\(/'],
                'homes' => ['js/src/runtime/server/request.js' => 'every request the runtime sends'],
                'why' => 'Visits, reloads, calls and uploads each sent their own: an upload went without the plugins\' headers',
            ],
            'change signals' => [
                /** Not the template's $errors variable on the compiled component (ctx): the same word, another thing. */
                'patterns' => ["/(?<!ctx, )['\"]\\$(errors|deferred|unsaved|status|uploads|scroll)['\"]/"],
                'homes' => ['js/protocol.json' => 'its signals: what a view is told changed, besides properties (SIGNALS in runtime/protocol.js)'],
                'why' => 'Spelled across the runtime and the adapters (TASKS 17.9)',
            ],
            'view file extensions in the adapters' => [
                'patterns' => ["/\['jsx',\s*'tsx'/"],
                'homes' => [],
                'why' => 'nitro.views has them: the bundle gives them to the runtime (TASKS 17.10)',
            ],
        ];
    }

    /** @return array<string, array{0: array{patterns: list<string>, homes: array<string, string>, why: string}}> */
    public static function cases(): array
    {
        return array_map(fn (array $rule) => [$rule], self::rules());
    }

    /**
     * @param  array{patterns: list<string>, homes: array<string, string>, why: string}  $rule
     */
    #[DataProvider('cases')]
    public function test_a_concept_is_written_in_its_home_only(array $rule): void
    {
        $name = (string) $this->dataName();

        foreach (array_keys($rule['homes']) as $home) {
            $this->assertFileExists(Scanner::root().'/'.$home, "{$name}: its home [{$home}] is gone: name where the concept lives now");
        }
        $found = [];

        foreach ($rule['patterns'] as $pattern) {
            $found = [...$found, ...Scanner::filesMatching($pattern)];
        }

        $outside = array_values(array_diff(array_unique($found), array_keys($rule['homes'])));
        sort($outside);
        $known = array_keys(Baseline::of('homes', $name));
        sort($known);

        $this->assertSame([], array_values(array_diff($outside, $known)), "{$name}: written outside its home. {$rule['why']}");
        $this->assertSame([], array_values(array_diff($known, $outside)), "{$name}: fixed, so take it out of tests/Architecture/known-breaks.php");
    }
}
