<?php

namespace Nitro\Fusion\Compiler;

/**
 * Viewi's PhpJsFunctions: PHP functions ported to JavaScript (Viewi's are locutus'), one file
 * each in js/functions, for Fusion's values (a PHP array is a JS array when it is a list, a Map
 * otherwise). A bundle has those its components call, with the ones they use (Viewi's getUses():
 * the file's "// uses: a, b" line). php.js has the functions the runtime itself needs.
 */
final class Functions
{
    /** @var array<string, string>|null name => file */
    private static ?array $files = null;

    /** @return array<string, string> the functions there are: name => file */
    public static function all(): array
    {
        if (self::$files === null) {
            self::$files = [];

            /** By category (array/, string/, regex/, ...): a function is known by its name, wherever it is. */
            foreach (glob(dirname(__DIR__, 2).'/js/functions/*/*.js') ?: [] as $file) {
                self::$files[basename($file, '.js')] = $file;
            }
        }

        return self::$files;
    }

    public static function has(string $name): bool
    {
        return isset(self::all()[$name]);
    }

    /** @return list<string> the functions this one calls (Viewi's getUses()) */
    public static function uses(string $name): array
    {
        $source = (string) file_get_contents(self::all()[$name]);

        return preg_match('~^// uses: (.+)$~m', $source, $m)
            ? array_map('trim', explode(',', $m[1]))
            : [];
    }

    /**
     * The functions, with those they use (Viewi's collectFunctionDependencies), sorted.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function withUses(array $names): array
    {
        $all = [];
        $collect = function (string $name) use (&$collect, &$all): void {
            if (isset($all[$name])) {
                return;
            }

            if (! self::has($name)) {
                /** One of php.js's, always there. */
                if (in_array($name, Transpiler::FUNCTIONS, true)) {
                    return;
                }

                throw new CompileException("Function '{$name}' can not be found.");
            }

            $all[$name] = true;

            foreach (self::uses($name) as $used) {
                $collect($used);
            }
        };

        foreach ($names as $name) {
            $collect($name);
        }

        $sorted = array_keys($all);
        sort($sorted);

        return $sorted;
    }

    /**
     * The bundle's functions: each registered as P.fns.name (where the compiled code calls it).
     *
     * @param  list<string>  $names
     */
    public static function js(array $names): string
    {
        $js = '';

        foreach (self::withUses($names) as $name) {
            $js .= "P.fns.{$name} = (function () {\n".trim((string) file_get_contents(self::all()[$name]))."\nreturn {$name};\n})();\n";
        }

        return $js;
    }
}
