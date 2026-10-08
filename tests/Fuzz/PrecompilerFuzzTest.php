<?php

namespace Nitro\Fusion\Tests\Fuzz;

use Nitro\Fusion\Compiler\CompileException;
use Nitro\Fusion\Tests\TestCase;
use Throwable;

/**
 * Robustness fuzzing of the Blade precompiler (<fusion:...> tags in any view): random views made
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
        '<fusion:counter />', '<fusion:counter :count="$a" />', '<fusion:counter :count="1 + 2" :key="$i" />', "<fusion:counter :count=\"'a > b'\" />",
        '<fusion:counter :label="\'it\\\'s\'" />', '<fusion:counter count="5" />', '<fusion:counter :items="[1, 2, \'x\' => [3]]" />', "<fusion:counter\n    :count=\"\$a\"\n/>",
        '<fusion:search-box fusion:model="query" />', '<fusion:students.table />', '<fusion:dynamic-component :is="$name" />', '<fusion:counter fusion:hydrate.idle />',
        '<fusion:card>text</fusion:card>', '<fusion:card><fusion:slot name="footer">f</fusion:slot>body</fusion:card>', '<fusion:card><fusion:counter /></fusion:card>',
        '<FUSION:counter />', '<fusion:counter/>', '<fusion:counter ></fusion:counter>',
        "@php \$t = '<fusion:counter />'; @endphp", "<?php \$t = '<fusion:counter />'; ?>", "{{ '<fusion:counter />' }}", "{!! '<fusion:counter />' !!}",
        '{{-- <fusion:counter /> --}}', "@verbatim <fusion:counter /> {{ x }} @endverbatim", '@{{ <fusion:counter /> }}', "{{ \$a ?? '<fusion:x>' }}",
        '<div class="a">', '</div>', '<p>', '</p>', 'text', "\n", ' ', '&lt;fusion:x&gt;', 'fusion:counter', '<fusion', 'ada@example.com',
        '@if ($a) <fusion:counter /> @endif', '@foreach ($items as $i) <fusion:counter :key="$i" /> @endforeach', '@if ($a) x @else <fusion:card>y</fusion:card> @endif', '{{ $a }}', '{!! $b !!}', '@csrf', '<!-- <fusion:counter /> -->',
        '<script>let x = "<fusion:counter />";</script>', "<input value=\"{{ '>' }}\">",
    ];

    /** Pieces that are not a tag Fusion can make sense of: a CompileException, or text. */
    private const BROKEN = [
        '<fusion:', '<fusion:counter', '<fusion:counter :count="$a', '</fusion:counter>', '</fusion:slot>', '<fusion:slot>', '<fusion:slot name="">',
        '<fusion:counter :count=$a />', '<fusion:counter ::x="1" />', '<fusion:counter :="1" />', '<fusion:-x />', '<fusion:counter fusion:hydrate.soon />',
        "<fusion:counter :count=\"'\" />", '<fusion:counter :count="{{ $a }}" />', '<fusion:counter :count="$a" :count="$b" />',
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

            /** Text Fusion must leave alone, still there as written. */
            foreach (["@php \$t = '<fusion:counter />'; @endphp" => "\$t = '<fusion:counter />';", "{{ '<fusion:counter />' }}" => "e('<fusion:counter />')", '{{-- <fusion:counter /> --}}' => null, "<?php \$t = '<fusion:counter />'; ?>" => "\$t = '<fusion:counter />';"] as $written => $kept) {
                if ($kept !== null && str_contains($view, $written) && ! str_contains($php, $kept)) {
                    $failures[] = "seed {$caseSeed}: {$written} was changed\n{$view}\n--- PHP\n{$php}";
                }
            }
        }

        fwrite(STDERR, sprintf("\n  Precompiler fuzz: seeds %d to %d, %s, %d failures\n", $seed, $seed + $count - 1, json_encode($outcomes), count($failures)));

        $this->assertSame([], array_slice($failures, 0, 8), implode("\n\n", array_slice($failures, 0, 8)));
    }
}
