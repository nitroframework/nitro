<?php

namespace Nitro\Fusion\Compiler;

/**
 * What compiled code uses that the browser must be given: the routes, translation keys and config
 * keys its Laravel helpers name, the PHP functions it calls (js/functions), the #[Browser] classes
 * it reaches. The compiler collects them; @fusionScripts ships their values with the page.
 */
final class Uses
{
    public const KINDS = ['routes', 'lang', 'config', 'functions', 'classes'];

    /**
     * Nothing used yet, as the compiler collects them (each a set: name => true).
     *
     * @return array{routes: array<string, true>, lang: array<string, true>, config: array<string, true>, functions: array<string, true>, classes: array<string, true>}
     */
    public static function none(): array
    {
        /** @var array{routes: array<string, true>, lang: array<string, true>, config: array<string, true>, functions: array<string, true>, classes: array<string, true>} */
        return array_fill_keys(self::KINDS, []);
    }

    /**
     * The uses, sorted; config() only for the keys the application made public.
     *
     * @param  array{routes: array<string, true>, lang: array<string, true>, config: array<string, true>, functions: array<string, true>, classes: array<string, true>}  $uses
     * @param  list<string>  $publicConfig  fusion.public_config
     * @return array{routes: list<string>, lang: list<string>, config: list<string>, functions: list<string>, classes: list<string>}
     *
     * @throws CompileException for a config key that is not public
     */
    public static function checked(array $uses, array $publicConfig): array
    {
        foreach (array_keys($uses['config']) as $key) {
            if (! in_array($key, $publicConfig, true)) {
                throw new CompileException("config('{$key}') runs in the browser, which sees only public config: add '{$key}' to fusion.public_config (its value is sent to every visitor)");
            }
        }

        $sorted = [];

        foreach ($uses as $kind => $names) {
            $names = array_map('strval', array_keys($names));
            sort($names);
            $sorted[$kind] = $names;
        }

        /** @var array{routes: list<string>, lang: list<string>, config: list<string>, functions: list<string>, classes: list<string>} */
        return $sorted;
    }
}
