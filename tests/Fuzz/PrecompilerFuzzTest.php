<?php

namespace Nitro\Tests\Fuzz;

use Nitro\Compiler\CompileException;
use Nitro\Tests\TestCase;
use Throwable;

/**
 * Robustness fuzzing of the Blade precompiler (<nitro:...> tags in any view): random views made
 * of tags (with props, keys, slots, quotes and > in attributes), tags written in PHP, echoes,
 * comments and @verbatim, and broken pieces, compiled by Blade. Whatever the view:
 *
 *   - it compiles, or a broken tag is refused with a CompileException;
 *   - what it compiles to is PHP that parses;
 *   - a tag written in PHP, an echo, a comment or @verbatim stays as it was written.
 *
 * Off unless asked for:   FUZZ_PRECOMPILER=2000 FUZZ_SEED=1 phpunit tests/Fuzz/PrecompilerFuzzTest.php
 */
class PrecompilerFuzzTest extends TestCase
{
    /** Pieces a view is made of; each is balanced Blade, so a failure is the precompiler's. */
    private const PIECES = [
        '<nitro:counter />', '<nitro:counter :count="$a" />', '<nitro:counter :count="1 + 2" :key="$i" />', "<nitro:counter :count=\"'a > b'\" />",
        '<nitro:counter :label="\'it\\\'s\'" />', '<nitro:counter count="5" />', '<nitro:counter :items="[1, 2, \'x\' => [3]]" />', "<nitro:counter\n    :count=\"\$a\"\n/>",
        '<nitro:search-box nitro:model="query" />', '<nitro:students.table />', '<nitro:dynamic-component :is="$name" />', '<nitro:counter nitro:hydrate.idle />',
        '<nitro:card>text</nitro:card>', '<nitro:card><nitro:slot name="footer">f</nitro:slot>body</nitro:card>', '<nitro:card><nitro:counter /></nitro:card>',
        '<NITRO:counter />', '<nitro:counter/>', '<nitro:counter ></nitro:counter>',
        "@php \$t = '<nitro:counter />'; @endphp", "<?php \$t = '<nitro:counter />'; ?>", "{{ '<nitro:counter />' }}", "{!! '<nitro:counter />' !!}",
        '{{-- <nitro:counter /> --}}', "@verbatim <nitro:counter /> {{ x }} @endverbatim", '@{{ <nitro:counter /> }}', "{{ \$a ?? '<nitro:x>' }}",
        '<div class="a">', '</div>', '<p>', '</p>', 'text', "\n", ' ', '&lt;nitro:x&gt;', 'nitro:counter', '<nitro', 'ada@example.com',
        '@if ($a) <nitro:counter /> @endif', '@foreach ($items as $i) <nitro:counter :key="$i" /> @endforeach', '@if ($a) x @else <nitro:card>y</nitro:card> @endif', '{{ $a }}', '{!! $b !!}', '@csrf', '<!-- <nitro:counter /> -->',
        '<script>let x = "<nitro:counter />";</script>', "<input value=\"{{ '>' }}\">",
    ];

    /** Pieces that are not a tag Nitro can make sense of: a CompileException, or text. */
    private const BROKEN = [
        '<nitro:', '<nitro:counter', '<nitro:counter :count="$a', '</nitro:counter>', '</nitro:slot>', '<nitro:slot>', '<nitro:slot name="">',
        '<nitro:counter :count=$a />', '<nitro:counter ::x="1" />', '<nitro:counter :="1" />', '<nitro:-x />', '<nitro:counter nitro:hydrate.soon />',
        "<nitro:counter :count=\"'\" />", '<nitro:counter :count="{{ $a }}" />', '<nitro:counter :count="$a" :count="$b" />',
    ];

    public function test_random_views_compile_to_php_or_are_refused(): void
    {
        $count = (int) getenv('FUZZ_PRECOMPILER');

        if ($count < 1) {
            $this->markTestSkipped('Set FUZZ_PRECOMPILER=N to compile N random views.');
        }

        $seed = (int) (getenv('FUZZ_SEED') ?: random_int(1, 1_000_000));
        $this->boot('laravel');
        $blade = $this->app->make('blade.compiler');
        $failures = [];
        $outcomes = ['compiled' => 0, 'refused' => 0];

        for ($i = 0; $i < $count; $i++) {
            $caseSeed = $seed + $i;
            $mutator = new Mutator($caseSeed);
            $pieces = [];

            for ($n = mt_rand(1, 14); $n > 0; $n--) {
                $pieces[] = $mutator->chance(12) ? $mutator->pick(self::BROKEN) : $mutator->pick(self::PIECES);
            }

            $view = implode($mutator->pick([' ', "\n", ' text ']), $pieces);

            try {
                $php = $blade->compileString($view);
            } catch (CompileException) {
                $outcomes['refused']++;

                continue;
            } catch (Throwable $e) {
                $failures[] = "seed {$caseSeed}: ".get_class($e).': '.$e->getMessage()."\n{$view}";

                continue;
            }

            $outcomes['compiled']++;

            try {
                token_get_all($php, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $failures[] = "seed {$caseSeed}: compiled to PHP that does not parse ({$e->getMessage()})\n{$view}\n--- PHP\n{$php}";

                continue;
            }

            /** Text Nitro must leave alone, still there as written. */
            foreach (["@php \$t = '<nitro:counter />'; @endphp" => "\$t = '<nitro:counter />';", "{{ '<nitro:counter />' }}" => "e('<nitro:counter />')", '{{-- <nitro:counter /> --}}' => null, "<?php \$t = '<nitro:counter />'; ?>" => "\$t = '<nitro:counter />';"] as $written => $kept) {
                if ($kept !== null && str_contains($view, $written) && ! str_contains($php, $kept)) {
                    $failures[] = "seed {$caseSeed}: {$written} was changed\n{$view}\n--- PHP\n{$php}";
                }
            }
        }

        fwrite(STDERR, sprintf("\n  Precompiler fuzz: seeds %d to %d, %s, %d failures\n", $seed, $seed + $count - 1, json_encode($outcomes), count($failures)));

        $this->assertSame([], array_slice($failures, 0, 8), implode("\n\n", array_slice($failures, 0, 8)));
    }
}
