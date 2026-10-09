<?php

namespace Nitro\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Each folder holds one kind of class. Where that can be checked, it is: what Http holds, what
 * Support holds, what Rendering does not. A file outside these is a known break or a decision
 * to write down here, with why.
 */
final class PlacementTest extends TestCase
{
    /** Http: answering requests. Beside controllers and middleware, these, with why. */
    private const HTTP = [
        'src/Http/PageResponses.php' => 'answers a routed page (Route::nitro)',
        'src/Http/IncomingState.php' => 'makes the state a request brings from the browser trusted',
        'src/Http/FinishPage.php' => 'middleware: finishes any HTML answer (portals, the page\'s data block)',
        'src/Http/ServerTiming.php' => 'middleware: each answer\'s server and database time, for the dev overlay',
    ];

    /** Support: what every part uses and none owns. */
    private const SUPPORT = [
        'src/Support/Naming.php' => 'component and page names',
        'src/Support/Registry.php' => 'the component and page classes, by name',
        'src/Support/Wire.php' => 'the value format between PHP and the browser',
        'src/Support/Protocol.php' => 'the names both sides use (js/protocol.json)',
        'src/Support/Types.php' => 'the scalar types, and whether a value fits one',
        'src/Support/Html.php' => 'HTML\'s element rules',
        'src/Support/Duration.php' => 'durations ("500ms", "1s")',
        'src/Support/UrlValues.php' => 'typed values from a URL',
        'src/Support/Config.php' => 'Nitro\'s configuration: every part reads it',
        'src/Support/LayoutKey.php' => 'the layout key the server writes and the browser compares: the build and the page responses make it',
        'src/Support/Signer.php' => 'signs and verifies (state, uploads), and Nitro\'s key: state, the build and uploads use it',
        'src/Support/ComponentHooks.php' => 'the component hooks\' names and rules: the compiler, the server and the runtime read them',
        'src/Support/PropertyHooks.php' => 'the property hooks\' names and rules: the compiler, the server and the runtime read them',
        'src/Support/CurrentRequest.php' => 'the request being answered and its session: every layer asks (Signer, State, Rendering, Http, #[Url])',
    ];

    /** Rendering renders; state is State's. */
    private const NOT_IN_RENDERING = ['Snapshot', 'Context'];

    public function test_each_folder_holds_its_kind(): void
    {
        $misplaced = [];

        foreach (array_keys(Scanner::code()) as $path) {
            $folder = dirname($path);
            $file = basename($path, '.php');

            if ($folder === 'src/Http' && preg_match('/(Controller|Middleware)$/', $file) !== 1 && ! isset(self::HTTP[$path])) {
                $misplaced[$path] = 'Http answers requests: controllers and middleware';
            }

            if ($folder === 'src/Support' && ! isset(self::SUPPORT[$path])) {
                $misplaced[$path] = 'Support is for what every part uses and none owns';
            }

            if ($folder === 'src/Rendering' && in_array($file, self::NOT_IN_RENDERING, true)) {
                $misplaced[$path] = 'state belongs in State';
            }
        }

        $known = Baseline::of('placement', 'misplaced');

        $this->assertSame([], array_diff_key($misplaced, $known), 'In a folder of another kind: move it, or write down here why it belongs');
        $this->assertSame([], array_keys(array_diff_key($known, $misplaced)), 'Moved: take it out of known-breaks.php');
    }
}
