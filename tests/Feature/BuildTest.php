<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Build\Bundle;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * fusion:build (the production bundle, minified with esbuild) and fusion:clear; the bundle
 * compiled on first use without a build; and optimize leaving Fusion alone.
 */
class BuildTest extends TestCase
{
    private const COMPILED = self::APP.'/storage/framework/fusion';

    /** The fixture's chunks: one per page (pages::student-page in its #[LazyLoad] group), and todo-item's group. */
    private const CHUNKS = ['board', 'counter', 'devices', 'feed', 'gallery', 'greeting', 'islands', 'journal', 'live', 'mounted-timeline', 'orders', 'pages', 'panels', 'report', 'shelf', 'showcase', 'timeline', 'todos', 'transitions'];

    #[DataProvider('frameworks')]
    public function test_build_minifies_the_bundle_and_caches_the_component_list(string $framework): void
    {
        $this->boot($framework);
        $manifest = self::APP.'/bootstrap/cache/fusion.php';

        /** A bundle from an earlier version: nothing asks for it any more. */
        @mkdir(self::COMPILED, 0777, true);
        file_put_contents(self::COMPILED.'/app-0000000000000000.js', '// old');

        $code = $this->console()->call('fusion:build');
        $output = $this->console()->output(); // fetch() empties the buffer: read it once
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('10 Fusion components and 18 pages built', $output);
        $this->assertFileExists($manifest);
        $this->assertSame(
            ['beacon', 'card', 'clock', 'counter', 'journal', 'pages::board', 'pages::counter', 'pages::devices', 'pages::feed', 'pages::gallery', 'pages::greeting', 'pages::journal', 'pages::links', 'pages::live', 'pages::mounted-timeline', 'pages::orders', 'pages::panels', 'pages::report', 'pages::shelf', 'pages::showcase', 'pages::student-page', 'pages::timeline', 'pages::transitions', 'roster', 'search-box', 'todo-item', 'todo-list', 'wallet'],
            array_keys(array_sort_recursive(require $manifest))
        );

        $bundle = $this->app->make(Bundle::class);
        $version = $bundle->version();
        $files = array_map('basename', glob(self::COMPILED.'/app-*.js'));
        sort($files);
        $expected = ["app-{$version}.min.js", ...array_map(fn (string $group) => "app-{$version}.{$group}.chunk.min.js", self::CHUNKS)];
        sort($expected);
        $this->assertSame($expected, $files, 'the bundle and a chunk for each page and #[LazyLoad] group; the old bundle is removed');
        $built = (string) file_get_contents(self::COMPILED."/app-{$version}.min.js");
        $readable = $bundle->compile();

        /**
         * Minified: no more statement per line, smaller, still defining every component; the
         * templates are JSON the browser parses.
         */
        $this->assertMatchesRegularExpression('/^ +"t": "t",$/m', $readable, 'the readable bundle: the tree, indented');
        $this->assertDoesNotMatchRegularExpression('/^\s*"t": "t",$/m', $built);
        $this->assertLessThan(strlen($readable) * 0.8, strlen($built), strlen($built).' of '.strlen($readable).' bytes');
        $this->assertMatchesRegularExpression('/\w\.define\("counter",\{/', $built);
        $this->assertMatchesRegularExpression('/template:JSON\.parse\(\'\{"t":"r","h":\[/', $built);
        $this->assertStringNotContainsString("\n})(window.Fusion", $built);

        /** Pages and #[LazyLoad] components are in their chunks, not the bundle. */
        $this->assertDoesNotMatchRegularExpression('/define\("(pages::board|pages::student-page|todo-item)"/', $built);
        $this->assertMatchesRegularExpression('/\w\.define\("pages::board",\{/', (string) file_get_contents(self::COMPILED."/app-{$version}.board.chunk.min.js"));
        $this->assertMatchesRegularExpression('/\w\.define\("todo-item",\{/', (string) file_get_contents(self::COMPILED."/app-{$version}.todos.chunk.min.js"));

        /** The build is app-<version>.min.js, and pages ask for it by a version of its own. */
        $tag = $bundle->tag();
        $this->assertStringEndsWith('.min', $tag);
        $this->assertSame($built, $this->get('/_fusion/app.js?v='.$tag)->getContent());

        /**
         * Published under public/ (fusion.static), as `vite build` writes public/build: the page
         * points at those files, served without PHP. The names carry the version; chunks are
         * named by a hash, and app.js maps the pages to those names.
         */
        $counterChunk = $bundle->chunkId('counter');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $counterChunk);
        $this->assertSame('counter', $bundle->groupFor($counterChunk));
        $this->assertStringContainsString('"pages::counter":"'.$counterChunk.'"', $built);
        $this->assertStringNotContainsString('"pages::counter":"counter"', $built);
        $this->assertFileEquals(self::COMPILED."/app-{$version}.min.js", self::APP."/public/fusion/app-{$version}.js");
        $this->assertFileEquals(self::COMPILED."/app-{$version}.counter.chunk.min.js", self::APP."/public/fusion/{$counterChunk}-{$version}.js");
        $this->assertFileExists(self::APP.'/public/fusion/fusion-'.$this->fusion()->runtimeVersion().'.js');
        $this->assertStringContainsString('Published to public/fusion', $output);

        $page = (string) $this->get('/counter')->getContent();
        $this->assertStringContainsString('<script src="http://localhost/fusion/fusion-'.$this->fusion()->runtimeVersion().'.js" ', $page);
        $this->assertStringContainsString('<script src="http://localhost/fusion/app-'.$version.'.js" defer></script>', $page);
        $this->assertStringContainsString('<script src="http://localhost/fusion/'.$counterChunk.'-'.$version.'.js" defer></script>', $page);
        $this->assertStringContainsString('data-chunks="http://localhost/fusion/{id}-'.$version.'.js"', $page);
        $this->assertStringNotContainsString('/_fusion/app', $page);

        /** The routes still serve them by those names (the fallback until the next build). */
        $this->app->forgetScopedInstances();
        $this->assertSame((string) file_get_contents(self::COMPILED."/app-{$version}.counter.chunk.min.js"), $this->get('/_fusion/app/'.$counterChunk.'.js?v='.$tag)->getContent());

        $this->assertSame(0, $this->console()->call('fusion:clear'));
        $this->assertFileDoesNotExist($manifest);
        $this->assertCount(0, glob(self::COMPILED.'/app-*.js'));
        $this->assertCount(0, glob(self::APP.'/public/fusion/*.js'), 'and the published files');
    }

    /**
     * Laravel's helpers in the browser: the bundle knows what its code names; @fusionScripts ships
     * those only (route definitions, this locale's translations, public config), and a config key
     * the application did not make public fails the build.
     */
    #[DataProvider('frameworks')]
    public function test_the_page_ships_what_the_helpers_name(string $framework): void
    {
        $this->boot($framework);
        $bundle = $this->app->make(Bundle::class);

        $uses = $bundle->uses();
        $chunks = $uses['chunks'];
        unset($uses['chunks']);
        ksort($chunks);

        $this->assertSame([
            'routes' => ['archive', 'counter', 'posts.show', 'students.show'],
            'lang' => ['Nothing to translate', 'Open :name', 'messages.welcome'],
            'config' => ['app.name'],
            /** Viewi's: the PHP functions the components call, from js/functions (their bundle has them). */
            'functions' => ['Str_camel', 'Str_headline', 'Str_limit', 'Str_mask', 'Str_padLeft', 'Str_slug', 'Str_title', '_browser_canvas', '_browser_clipboard', '_browser_session', '_browser_socket', '_browser_storage', 'html_entity_decode', 'levenshtein', 'preg_match', 'preg_match_all', 'preg_replace', 'preg_split', 'str_word_count', 'strtr'],
        ], $uses);

        /** What is in chunks: every page (but pages::links, #[LazyLoad(false)]), and todo-item and beacon (#[LazyLoad]). */
        $this->assertSame([
            'beacon' => 'islands',
            'pages::board' => 'board',
            'pages::counter' => 'counter',
            'pages::devices' => 'devices',
            'pages::feed' => 'feed',
            'pages::gallery' => 'gallery',
            'pages::greeting' => 'greeting',
            'pages::journal' => 'journal',
            'pages::live' => 'live',
            'pages::mounted-timeline' => 'mounted-timeline',
            'pages::orders' => 'orders',
            'pages::panels' => 'panels',
            'pages::report' => 'report',
            'pages::shelf' => 'shelf',
            'pages::showcase' => 'showcase',
            'pages::student-page' => 'pages',
            'pages::timeline' => 'timeline',
            'pages::transitions' => 'transitions',
            'todo-item' => 'todos',
        ], $chunks);
        $this->assertStringContainsString('P.fns.str_word_count = (function () {', $this->get('/_fusion/app.js')->getContent());
        $this->assertStringNotContainsString('P.fns.soundex', $this->get('/_fusion/app.js')->getContent());

        $page = (string) $this->get('/embed')->getContent();
        $this->assertMatchesRegularExpression('#<script type="application/json" data-fusion-globals>\{"url":"http://localhost".*"app\.name":"Fusion Tests"\},"shared":\{"\$m":\[\["user",\{"\$m":\[\["name","Ada"\]\]\}\],\["countries",\["PK","US"\]\]\]\},"once":\{"countries":null\},"flash":\[\]\}</script>#', $page);

        $globals = $this->fusion()->globals();
        $this->assertSame('http://localhost', $globals['url']);
        $this->assertSame(['uri' => 'posts/{post}', 'bindings' => ['post' => 'slug']], (array) $globals['routes']->{'posts.show'});
        $this->assertSame("Open :Name's record", $globals['lang']->{'Open :name'});
        $this->assertSame('Welcome, :NAME! (:name, :Name)', $globals['lang']->{'messages.welcome'});
        $this->assertObjectNotHasProperty('Nothing to translate', $globals['lang'], 'a key without a translation is its own line');
        $this->assertSame(['app.name'], array_keys((array) $globals['config']));
        $this->assertObjectNotHasProperty('login', $globals['routes'], 'only the routes the compiled code names');

        $this->app->make('config')->set('fusion.public_config', []);
        $this->app->forgetScopedInstances();

        try {
            $this->app->make(Bundle::class)->compileAll();
            $this->fail('config() of a key that is not public must fail the build');
        } catch (\Nitro\Fusion\Compiler\CompileException $e) {
            $this->assertStringContainsString("config('app.name') runs in the browser, which sees only public config: add 'app.name' to fusion.public_config", $e->getMessage());
        }
    }

    /**
     * #[LazyLoad] components are chunks of their own, as Viewi's lazy groups: the bundle says which
     * component is in which chunk and which pages render them; a page rendered on the server
     * loads the chunks it uses with the bundle.
     */
    #[DataProvider('frameworks')]
    public function test_lazy_components_are_chunks_loaded_when_a_page_needs_them(string $framework): void
    {
        $this->boot($framework);
        $bundle = $this->app->make(Bundle::class);
        $files = $bundle->compileAll();

        $groups = array_keys($files);
        $this->assertSame('', array_shift($groups), 'the bundle first');
        sort($groups);
        $this->assertSame(self::CHUNKS, $groups, 'then a chunk per page and group');

        /** app.js maps what is in which chunk, and the paths of the routed pages to their chunks. */
        foreach (['"todo-item":"todos"', '"pages::student-page":"pages"', '"pages::board":"board"', '["^/students/[^/]+/?$","pages"]', '["^/board/?$","board"]'] as $entry) {
            $this->assertStringContainsString($entry, $files['']);
        }
        $this->assertStringNotContainsString('Fusion.define("todo-item"', $files['']);
        $this->assertStringContainsString('Fusion.define("todo-item", {', $files['todos']);
        $this->assertStringContainsString('Fusion.define("pages::student-page", {', $files['pages']);

        $tag = $bundle->tag();
        $chunk = $this->get('/_fusion/app/todos.js?v='.$tag);
        $this->assertSame(200, $chunk->getStatusCode());
        $this->assertSame($files['todos'], $chunk->getContent());
        $this->assertStringContainsString('immutable', (string) $chunk->headers->get('Cache-Control'));
        $this->assertSame(404, $this->get('/_fusion/app/nope.js')->getStatusCode());

        /** Pages list the chunks of what they render: the runtime has them when it starts. */
        $page = fn (string $uri) => tap((string) $this->get($uri)->getContent(), fn () => $this->app->forgetScopedInstances()); // each request its own, as in production
        $student = $page('/students/1');
        $this->assertStringContainsString('data-bundle="'.$tag.'"', $student);
        $this->assertStringContainsString('<script src="/_fusion/app/pages.js?v='.$tag.'" defer></script>', $student);
        $this->assertStringNotContainsString('/_fusion/app/todos.js', $student);
        $this->assertStringContainsString('<script src="/_fusion/app/todos.js?v='.$tag.'" defer></script>', $page('/embed'));
        $this->assertStringNotContainsString('<script src="/_fusion/app/', $page('/links'), '#[LazyLoad(false)]: in app.js, no chunk loaded');
    }

    /**
     * Every page is a chunk of its own, as Next.js and Nuxt split by page, however it is reached;
     * #[LazyLoad('group')] still groups pages, #[LazyLoad(false)] keeps one in app.js, and the
     * components pages place stay in app.js.
     */
    #[DataProvider('frameworks')]
    public function test_each_page_is_a_chunk_of_its_own(string $framework): void
    {
        $this->boot($framework);
        $bundle = $this->app->make(Bundle::class);
        $files = $bundle->compileAll();

        foreach (['counter', 'devices', 'feed', 'gallery', 'greeting', 'journal', 'live', 'mounted-timeline', 'orders', 'panels', 'report', 'shelf', 'showcase', 'timeline', 'transitions'] as $page) {
            $this->assertStringNotContainsString('Fusion.define("pages::'.$page.'"', $files[''], "{$page} is a page: not in app.js");
            $this->assertStringContainsString('Fusion.define("pages::'.$page.'", {', $files[$page]);
        }

        $this->assertStringContainsString('Fusion.define("pages::links", {', $files[''], '#[LazyLoad(false)]');
        $this->assertStringContainsString('Fusion.define("pages::student-page", {', $files['pages'], "#[LazyLoad('pages')]");
        $this->assertStringContainsString('Fusion.define("counter", {', $files[''], 'the component a page extends: in app.js');
        $this->assertStringContainsString('Fusion.define("todo-list", {', $files[''], 'a component');
        $this->assertStringContainsString('["^/counter/?$","counter"]', $files['']);
        $this->assertStringContainsString('["^/private/?$","counter"]', $files['']);
        $this->assertSame('counter', $bundle->uses()['chunks']['pages::counter']);

        $tag = $bundle->tag();
        $this->assertSame($files['counter'], $this->get('/_fusion/app/counter.js?v='.$tag)->getContent());

        $page = fn (string $uri) => tap((string) $this->get($uri)->getContent(), fn () => $this->app->forgetScopedInstances());
        $this->assertStringContainsString('<script src="/_fusion/app/counter.js?v='.$tag.'" defer></script>', $page('/counter'));
        $this->assertStringNotContainsString('<script src="/_fusion/app/', $page('/links'));
    }

    #[DataProvider('frameworks')]
    public function test_without_a_build_the_bundle_is_compiled_on_first_use_readable(string $framework): void
    {
        $this->boot($framework);
        $this->console()->call('fusion:clear');

        $bundle = (string) $this->get('/_fusion/app.js')->getContent();

        $this->assertStringContainsString("Fusion.define(\"counter\", {\n", $bundle);
        $this->assertGreaterThan(100, substr_count($bundle, "\n"));
    }

    #[DataProvider('frameworks')]
    public function test_with_minify_on_change_a_changed_component_is_built_minified_and_replaces_the_last_build(string $framework): void
    {
        $this->boot($framework);
        $this->app->make('config')->set('fusion.minify_on_change', true);
        $this->console()->call('fusion:build');
        $this->app->forgetScopedInstances();
        $before = $this->app->make(Bundle::class)->version();

        $source = self::APP.'/resources/views/fusion/components/counter.blade.php';
        $time = filemtime($source);
        touch($source, $time + 10);
        clearstatcache();

        try {
            $this->app->forgetScopedInstances();
            $bundle = $this->app->make(Bundle::class);
            $version = $bundle->version();
            $this->assertNotSame($before, $version);

            $script = (string) $this->get('/_fusion/app.js?v='.$version.'.min')->getContent();

            $this->assertSame($version.'.min', $bundle->tag());
            $this->assertMatchesRegularExpression('/\w\.define\("counter",\{/', $script, 'minified');
            $this->assertSame($script, file_get_contents(self::COMPILED."/app-{$version}.min.js"));
            $files = array_map('basename', glob(self::COMPILED.'/app-*'));
            $this->assertSame([], array_filter($files, fn (string $file) => ! str_contains($file, $version)), 'only this version is kept');
        } finally {
            touch($source, $time);
        }
    }

    #[DataProvider('frameworks')]
    public function test_with_minify_on_change_and_no_esbuild_the_bundle_is_compiled_readable(string $framework): void
    {
        $this->boot($framework);
        $this->app->make('config')->set('fusion.minify_on_change', true);
        $this->app->make('config')->set('fusion.esbuild.root', sys_get_temp_dir());
        $this->app->make('config')->set('fusion.esbuild.node', 'no-such-node-binary');
        $this->console()->call('fusion:clear');
        $this->app->forgetScopedInstances();

        $bundle = (string) $this->get('/_fusion/app.js')->getContent();

        $this->assertStringContainsString("Fusion.define(\"counter\", {\n", $bundle);
        $bundle = $this->app->make(Bundle::class);
        $this->assertSame($bundle->version(), $bundle->tag(), 'not built');
    }

    #[DataProvider('frameworks')]
    public function test_build_fails_clearly_without_esbuild(string $framework): void
    {
        $this->boot($framework);
        $this->app->make('config')->set('fusion.esbuild.root', sys_get_temp_dir());
        $this->app->forgetScopedInstances();

        $code = $this->console()->call('fusion:build');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('npm install --save-dev esbuild', $this->console()->output());
    }

    /**
     * The browser caches app.js by its version: it must change with anything that changes the
     * output, the traits components use and the compiler included.
     */
    #[DataProvider('frameworks')]
    public function test_the_bundle_version_follows_traits_and_the_compiler(string $framework): void
    {
        $this->boot($framework);
        $version = fn () => $this->app->make(Bundle::class)->version();
        $this->app->forgetScopedInstances();

        $bundle = $this->app->make(Bundle::class)->compile();
        $this->assertStringContainsString('doubled', $bundle);
        $this->assertMatchesRegularExpression('/\/\* (\d+) \*\/ function \(\$this\) \{ return P\.toStr\(\$this\.doubled\(\)\); \}.*?subs: \[.*?\/\* \1 \*\/ \["count"\]/s', $bundle, 'what the trait\'s method reads');

        foreach ([__DIR__.'/../Fixtures/Components/Doubles.php', dirname(__DIR__, 2).'/src/Compiler/Transpiler.php'] as $file) {
            $before = $version();
            $mtime = filemtime($file);
            touch($file, $mtime + 10);
            clearstatcache();
            $this->app->forgetScopedInstances();

            try {
                $this->assertNotSame($before, $version(), basename($file));
            } finally {
                touch($file, $mtime);
                clearstatcache();
                $this->app->forgetScopedInstances();
            }
        }
    }

    /** fusion:build is its own step, as `vite build` is: optimize neither builds nor clears it. */
    #[DataProvider('frameworks')]
    public function test_optimize_leaves_fusion_alone(string $framework): void
    {
        /** optimize boots bootstrap/app.php afresh (route:cache): it must be the same framework. */
        putenv('FUSION_FRAMEWORK='.$framework);
        $this->boot($framework);
        $manifest = self::APP.'/bootstrap/cache/fusion.php';

        try {
            $this->console()->call('fusion:clear');
            $this->assertSame(0, $this->console()->call('optimize'), $this->console()->output());
            $this->assertFileDoesNotExist($manifest, 'optimize does not build Fusion');

            $this->assertSame(0, $this->console()->call('fusion:build'), $this->console()->output());
            $this->assertSame(0, $this->console()->call('optimize:clear'), $this->console()->output());
            $this->assertFileExists($manifest, 'optimize:clear does not clear Fusion');
            $this->assertCount(1 + count(self::CHUNKS), glob(self::COMPILED.'/app-*.js'), 'the bundle and its chunks');
        } finally {
            $this->console()->call('fusion:clear');
            putenv('FUSION_FRAMEWORK');
        }
    }
}

function array_sort_recursive(array $array): array
{
    ksort($array);

    return $array;
}
