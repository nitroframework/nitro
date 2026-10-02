<?php

namespace Nitro\Fusion\Compiler\Template;

use Nitro\Fusion\Compiler\CompileException;

/**
 * A child component in a template: <fusion:task-item :task="$task" label="Hi" :key="$task['id']" />.
 *
 * Children are numbered in the order they appear in the template. A child's key is that
 * number plus the indexes of the loops around it ("c2", "c2.0.3"), unless it sets :key.
 * The server (Blade precompiler) and the browser (TemplateCompiler) both number children with
 * this class, so a child keeps its key, and its state, in both.
 */
final class ChildTag
{
    /** <fusion:name ...> or <fusion:name ... />; not <fusion:slot> (a slot of the child around it). */
    public const PATTERN = '/<fusion:(?!slot[\s\/>])([a-z0-9][a-z0-9\-.]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)(\/?)>/i';

    /** <fusion:slot name="footer"> */
    public const SLOT_PATTERN = '/<fusion:slot\s+name\s*=\s*(?:"([\w\-]+)"|\'([\w\-]+)\')\s*>/i';

    /**
     * @param  array<string, string>  $props  prop name (camelCase) => PHP expression of its value
     */
    public function __construct(
        public readonly string $name,
        public readonly array $props,
        /** The PHP expression of :key (or fusion:key), if set. */
        public readonly ?string $key,
        public readonly int $number,
        /** The length of the opening tag (the whole tag when it closes itself). */
        public readonly int $length,
        /** <fusion:name /> (no slots); otherwise its content, up to </fusion:name>, is its slots. */
        public readonly bool $selfClosing = true,
        /** fusion:model="path": the parent's property (path) its #[Modelable] property is bound to. */
        public readonly ?string $model = null,
        /**
         * An island's start (fusion:hydrate, fusion:static): "idle", "visible" ("visible:200px"),
         * "interaction", "media:(query)" or "static". Null: it starts with the page.
         */
        public readonly ?string $hydrate = null,
    ) {
    }

    /**
     * The child tag starting at $offset of $text, numbered $number.
     */
    public static function parse(string $text, int $offset, int $number, ?string $file = null, int $line = 0): self
    {
        if (! preg_match(self::PATTERN, $text, $m, 0, $offset) || strpos($text, $m[0], $offset) !== $offset) {
            throw new CompileException('Malformed <fusion:...> tag', $file, $line);
        }

        [$whole, $name, $attributes, $selfClosing] = $m;
        $length = strlen($whole);

        $props = [];
        $key = null;
        $model = null;
        $hydrate = null;

        preg_match_all('/([:\w\-.]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'))?/', $attributes, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $attribute = $match[1];
            $hasValue = isset($match[2]) || isset($match[3]);
            $value = ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '');
            $bound = str_starts_with($attribute, ':');
            $prop = ltrim($attribute, ':');

            if (str_contains($value, '{{') || str_contains($value, '{!!')) {
                throw new CompileException("Use :{$prop}=\"\$value\" to pass a value to <fusion:{$name}>, not {{ }}", $file, $line);
            }

            /** Bound (:prop) values are PHP expressions; others are strings; a bare attribute is true. */
            $expression = match (true) {
                $bound || $prop === 'fusion:key' => $value,
                $hasValue => var_export($value, true),
                default => 'true',
            };

            if ($prop === 'fusion:static' || $prop === 'fusion:hydrate' || str_starts_with($prop, 'fusion:hydrate.')) {
                if ($hydrate !== null) {
                    throw new CompileException("<fusion:{$name}> has one fusion:hydrate or fusion:static", $file, $line);
                }

                $hydrate = self::hydrate($prop, $bound ? null : ($hasValue ? $value : null), $name, $file, $line);

                continue;
            }

            if ($prop === 'key' || $prop === 'fusion:key') {
                $key = $expression;

                continue;
            }

            /**
             * fusion:model="form.email" on a child: its #[Modelable] property is bound to this
             * path of the parent. The parent's value travels as the "$model" prop.
             */
            if ($prop === 'fusion:model' || str_starts_with($prop, 'fusion:model.')) {
                if (! preg_match('/^[A-Za-z_]\w*(\.[\w\-]+)*$/', $value)) {
                    throw new CompileException("fusion:model on <fusion:{$name}> names a property of this component (or a key under it): \"{$value}\" is not one", $file, $line);
                }

                $parts = explode('.', $value);
                $props['$model'] = '$'.array_shift($parts).implode('', array_map(fn ($k) => '['.var_export($k, true).']', $parts));
                $model = $value;

                continue;
            }

            $props[self::camel($prop)] = $expression;
        }

        return new self(strtolower($name), $props, $key, $number, $length, $selfClosing !== '', $model, $hydrate);
    }

    /**
     * When an island starts: fusion:hydrate.idle, .visible (.visible.200px), .interaction,
     * .media="(max-width: 768px)"; fusion:static never.
     */
    private static function hydrate(string $attribute, ?string $value, string $name, ?string $file, int $line): string
    {
        if ($attribute === 'fusion:static') {
            return $value === null ? 'static' : throw new CompileException("fusion:static on <fusion:{$name}> takes no value", $file, $line);
        }

        $modifiers = array_slice(explode('.', $attribute), 1);
        $when = $modifiers[0] ?? null;
        $usage = 'fusion:hydrate takes when the island starts: .idle, .visible (.visible.200px), .interaction or .media="(max-width: 768px)"';

        return match (true) {
            in_array($when, ['idle', 'interaction'], true) && count($modifiers) === 1 && $value === null => $when,
            $when === 'visible' && count($modifiers) === 1 && $value === null => 'visible',
            $when === 'visible' && count($modifiers) === 2 && preg_match('/^\d+px$/', $modifiers[1]) === 1 && $value === null => 'visible:'.$modifiers[1],
            $when === 'media' && count($modifiers) === 1 && $value !== null && trim($value) !== '' => 'media:'.trim($value),
            $when === 'media' => throw new CompileException("fusion:hydrate.media on <fusion:{$name}> needs a media query: fusion:hydrate.media=\"(max-width: 768px)\"", $file, $line),
            default => throw new CompileException("{$usage} (on <fusion:{$name}>)", $file, $line),
        };
    }

    /** Every child tag of a template, in order. @return list<array{0: int, 1: self}> offset => tag */
    public static function all(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $m, PREG_OFFSET_CAPTURE);
        $tags = [];

        foreach ($m[0] as $number => [, $offset]) {
            $tags[] = [$offset, self::parse($text, $offset, $number)];
        }

        return $tags;
    }

    /** "c2" or "c2.0.3": the child's number and the loop indexes around it. */
    public static function key(int $number, array $loopIndexes): string
    {
        return 'c'.$number.($loopIndexes === [] ? '' : '.'.implode('.', $loopIndexes));
    }

    private static function camel(string $name): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name))));
    }
}
