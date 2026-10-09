<?php

namespace Nitro\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The direction of Nitro's parts. Each folder is a layer; a layer uses the layers below it,
 * never one above, and receives what it needs in its constructor (NitroServiceProvider wires
 * them). Apps call the Nitro class (the facade's); Nitro's own parts never do: it is the front
 * door, not a hallway between them.
 *
 *   entry points   Http, Console, Blade, Testing, NitroServiceProvider
 *   Rendering      a component or page to HTML
 *   State          signed state, shared data, stores (booted through Lifecycle, named by Build)
 *   Lifecycle      hooks, features, an instance's Context      } beside each other:
 *   Build          the bundle, chunks, the browser's names     } neither uses the other
 *   View           the view engines (the build compiles their views)
 *   Compiler       ComponentMeta, which properties are state, PHP and templates to JavaScript
 *   Support        the vocabulary every part shares
 *
 * Public types (the root namespace, Attributes, Browser, Concerns, Facades: PublicApiTest) can be
 * named in any layer.
 */
final class DependencyTest extends TestCase
{
    /** Higher uses lower; the same level, neither uses the other. */
    public const LAYERS = [
        'Http' => 9, 'Console' => 9, 'Blade' => 9, 'Testing' => 9,
        'Rendering' => 8,
        'State' => 7,
        'Lifecycle' => 5, 'Build' => 5,
        'View' => 4,
        'Compiler' => 3,
        'Support' => 1,
    ];

    /** What may name the Nitro class, with why. */
    private const NITRO_ALLOWED = [
        'src/Nitro.php' => 'itself',
        'src/Facades/Nitro.php' => 'its facade',
        'src/NitroServiceProvider.php' => 'builds it',
        'src/Compiler/Php/Catalog.php' => 'recognises Nitro::browser() in a component\'s code to compile it: names the class, never calls it',
    ];

    public function test_a_layer_uses_only_the_layers_below_it(): void
    {
        $upward = [];

        foreach ($this->sources() as $path => $code) {
            $from = $this->layerOf($path);

            if ($from === null) {
                continue;
            }

            foreach ($this->foldersUsed($code) as $folder) {
                $to = self::LAYERS[$folder] ?? null;

                if ($to !== null && $folder !== $this->folderOf($path) && $to >= self::LAYERS[$this->folderOf($path)]) {
                    $upward["{$path} -> {$folder}"] = true;
                }
            }
        }

        $known = Baseline::of('dependencies', 'upward');

        $this->assertSame([], array_keys(array_diff_key($upward, $known)), 'A layer uses one above it (or beside it): move the class, or pass the value down');
        $this->assertSame([], array_keys(array_diff_key($known, $upward)), 'Fixed: take it out of known-breaks.php');
    }

    public function test_nitros_parts_do_not_go_through_nitro(): void
    {
        $through = [];

        foreach ($this->sources() as $path => $code) {
            if (! isset(self::NITRO_ALLOWED[$path]) && $this->namesNitro($path, $code)) {
                $through[$path] = true;
            }
        }

        $known = Baseline::of('dependencies', 'through Nitro');

        $this->assertSame([], array_keys(array_diff_key($through, $known)), 'A part reaches another through Nitro: take what it needs in its constructor');
        $this->assertSame([], array_keys(array_diff_key($known, $through)), 'Fixed: take it out of known-breaks.php');
    }

    /** @return array<string, string> Nitro's PHP files under src/ */
    private function sources(): array
    {
        return array_filter(Scanner::code(), fn (string $path) => str_starts_with($path, 'src/') && Scanner::isPhp($path), ARRAY_FILTER_USE_KEY);
    }

    private function folderOf(string $path): string
    {
        $parts = explode('/', $path);

        return count($parts) > 2 ? $parts[1] : '';
    }

    private function layerOf(string $path): ?int
    {
        return self::LAYERS[$this->folderOf($path)] ?? null;
    }

    /** @return list<string> the folders of Nitro's classes the code names (imported or written out) */
    private function foldersUsed(string $code): array
    {
        preg_match_all('/\\\\?Nitro\\\\([A-Z][A-Za-z]*)\\\\/', $code, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** Whether the file names the Nitro class: imported, written out, or (in the root namespace) by its short name. */
    private function namesNitro(string $path, string $code): bool
    {
        if (preg_match('/\\\\?Nitro\\\\Nitro\b(?!\\\\)/', $code) === 1) {
            return true;
        }

        $inRoot = preg_match('/^namespace Nitro;/m', $code) === 1;

        return $inRoot && preg_match('/(?<![\\\\\w$>])Nitro(::class|\s+\$|\s*\)|\s*\|)/', $code) === 1;
    }
}
