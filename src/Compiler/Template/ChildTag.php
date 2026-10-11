<?php

namespace Nitro\Compiler\Template;

use Nitro\Compiler\CompileException;
use Nitro\Support\Naming;
use Nitro\Support\Protocol;

/**
 * A child component in a template: <nitro:task-item :task="$task" label="Hi" :key="$task['id']" />.
 *
 * Children are numbered in the order they appear in the template. A child's key is that
 * number plus the indexes of the loops around it ("c2", "c2.0.3"), unless it sets :key.
 * The server (Blade precompiler) and the browser (TemplateCompiler) both number children with
 * this class, so a child keeps its key, and its state, in both.
 */
final class ChildTag
{
    /** <nitro:dynamic-component :is="...">: the component :is names, decided as the view renders. */
    public const DYNAMIC = 'dynamic-component';

    /** What a tag may name, as a regex: "post-card", "posts.post-card", "issues::post-card" (the server and the browser compiler read tags with it). */
    public const NAME = '(?:'.Naming::NAMESPACE.Naming::SEPARATOR.')?[a-z0-9][a-z0-9\-.]*';

    /** <nitro:name ...> or <nitro:name ... />; not <nitro:slot> (a slot of the child around it). */
    public const PATTERN = '/<nitro:(?!slot[\s\/>])('.self::NAME.')((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)(\/?)>/i';

    /** <nitro:slot name="footer"> */
    public const SLOT_PATTERN = '/<nitro:slot\s+name\s*=\s*(?:"([\w\-]+)"|\'([\w\-]+)\')\s*>/i';

    /**
     * @param  array<string, string>  $props  prop name (camelCase) => PHP expression of its value
     */
    public function __construct(
        public readonly string $name,
        public readonly array $props,
        /** The PHP expression of :key (or nitro:key), if set. */
        public readonly ?string $key,
        public readonly int $number,
        /** The length of the opening tag (the whole tag when it closes itself). */
        public readonly int $length,
        /** <nitro:name /> (no slots); otherwise its content, up to </nitro:name>, is its slots. */
        public readonly bool $selfClosing = true,
        /** nitro:model="path": the parent's property (path) its #[Modelable] property is bound to. */
        public readonly ?string $model = null,
        /**
         * That path's segments: a key, or ['php' => $expression] for a {{ }} segment
         * (nitro:model="lines.{{ $i }}.qty").
         *
         * @var list<string|array{php: string}>
         */
        public readonly array $modelPath = [],
        /**
         * An island's start (nitro:hydrate, nitro:static): "idle", "visible" ("visible:200px"),
         * "interaction", "media:(query)" or "static". Null: it starts with the page.
         */
        public readonly ?string $hydrate = null,
    ) {
    }

    /**
     * The closing tag at $offset of $text (</nitro:name>, </nitro:slot>): its name, in lower
     * case, and its length; null when none starts there.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function closing(string $text, int $offset): ?array
    {
        return preg_match('/\G<\/nitro:('.self::NAME.')\s*>/i', $text, $match, 0, $offset) === 1 ? [strtolower($match[1]), strlen($match[0])] : null;
    }

    /**
     * The names the tags in $source give, each once: what a view places.
     *
     * @return list<string>
     */
    public static function names(string $source): array
    {
        preg_match_all('/<nitro:('.self::NAME.')/i', $source, $tags);

        return array_values(array_unique($tags[1]));
    }

    /** Whether $code is one PHP expression (what a bound attribute's value is put in place as). */
    private static function isExpression(string $code): bool
    {
        if (trim($code) === '') {
            return false;
        }

        try {
            $tokens = token_get_all('<?php return ('.$code.');', TOKEN_PARSE);
        } catch (\ParseError) {
            return false;
        }

        /** One statement: no ; or ?> slipped in to end it early. */
        return count(array_filter($tokens, fn ($token) => $token === ';' || (is_array($token) && $token[0] === T_CLOSE_TAG))) === 1;
    }

    /**
     * The child tag starting at $offset of $text, numbered $number.
     */
    public static function parse(string $text, int $offset, int $number, ?string $file = null, int $line = 0): self
    {
        if (! preg_match(self::PATTERN, $text, $found, 0, $offset) || strpos($text, $found[0], $offset) !== $offset) {
            throw new CompileException('Malformed <nitro:...> tag', $file, $line);
        }

        [$whole, $name, $attributes, $selfClosing] = $found;
        $length = strlen($whole);

        $props = [];
        $key = null;
        $model = null;
        $modelPath = [];
        $hydrate = null;

        preg_match_all('/([:\w\-.]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'))?/', $attributes, $matches, PREG_SET_ORDER);

        /** Every character is an attribute or the space between them: anything else (an unquoted value, a stray quote) would be dropped unseen. */
        $rest = trim((string) preg_replace('/([:\w\-.]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'))?/', '', $attributes));

        if ($rest !== '') {
            throw new CompileException("Malformed attributes on <nitro:{$name}>: \"{$rest}\" (a value goes in quotes: :count=\"\$count\")", $file, $line);
        }

        foreach ($matches as $match) {
            $attribute = $match[1];
            $hasValue = isset($match[2]) || isset($match[3]);
            $value = ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '');
            $bound = str_starts_with($attribute, ':');
            $prop = ltrim($attribute, ':');

            $isModel = $prop === 'nitro:model' || str_starts_with($prop, 'nitro:model.');

            if (! $isModel && (str_contains($value, '{{') || str_contains($value, '{!!'))) {
                throw new CompileException("Use :{$prop}=\"\$value\" to pass a value to <nitro:{$name}>, not {{ }}", $file, $line);
            }

            /** Bound (:prop) values are PHP expressions; others are strings; a bare attribute is true. */
            if (($bound || $prop === 'nitro:key') && ! self::isExpression($value)) {
                throw new CompileException("The value of {$attribute} on <nitro:{$name}> is not a PHP expression: \"{$value}\"", $file, $line);
            }

            $expression = match (true) {
                $bound || $prop === 'nitro:key' => $value,
                $hasValue => var_export($value, true),
                default => 'true',
            };

            if ($prop === Protocol::attribute('static') || $prop === Protocol::attribute('hydrate') || str_starts_with($prop, Protocol::attribute('hydrate').'.')) {
                if ($hydrate !== null) {
                    throw new CompileException("<nitro:{$name}> has one nitro:hydrate or nitro:static", $file, $line);
                }

                $hydrate = self::hydrate($prop, $bound ? null : ($hasValue ? $value : null), $name, $file, $line);

                continue;
            }

            if ($prop === 'key' || $prop === 'nitro:key') {
                $key = $expression;

                continue;
            }

            /**
             * nitro:model="form.email" on a child: its #[Modelable] property is bound to this
             * path of the parent, a segment of which may be {{ }} ("lines.{{ $i }}.qty"). The
             * parent's value travels as the "$model" prop.
             */
            if ($isModel) {
                if ($bound || ! preg_match('/^[A-Za-z_]\w*(\.([\w\-]+|\{\{(?:(?!\}\}).)+\}\}))*$/s', $value)) {
                    throw new CompileException("nitro:model on <nitro:{$name}> names a property of this component, or a key under it (form.email, lines.{{ \$i }}.qty): \"{$value}\" is not one", $file, $line);
                }

                $modelPath = array_map(function (string $segment) use ($name, $file, $line) {
                    if (! str_starts_with($segment, '{{')) {
                        return $segment;
                    }

                    $php = trim(substr($segment, 2, -2));

                    return self::isExpression($php) ? ['php' => $php] : throw new CompileException("nitro:model on <nitro:{$name}>: {$segment} is not a PHP expression", $file, $line);
                }, preg_split('/\.(?![^{]*\}\})/', $value));

                $root = array_shift($modelPath);
                $props[Protocol::modelProp()] = '$'.$root.implode('', array_map(fn ($segment) => '['.(is_array($segment) ? '('.$segment['php'].')' : var_export($segment, true)).']', $modelPath));
                array_unshift($modelPath, $root);
                $model = $value;

                continue;
            }

            $props[self::camel($prop)] = $expression;
        }

        return new self(strtolower($name), $props, $key, $number, $length, $selfClosing !== '', $model, $modelPath, $hydrate);
    }

    /**
     * When an island starts: nitro:hydrate.idle, .visible (.visible.200px), .interaction,
     * .media="(max-width: 768px)"; nitro:static never.
     */
    private static function hydrate(string $attribute, ?string $value, string $name, ?string $file, int $line): string
    {
        if ($attribute === Protocol::attribute('static')) {
            return $value === null ? 'static' : throw new CompileException("nitro:static on <nitro:{$name}> takes no value", $file, $line);
        }

        $modifiers = array_slice(explode('.', $attribute), 1);
        $when = $modifiers[0] ?? null;
        $usage = 'nitro:hydrate takes when the island starts: .idle, .visible (.visible.200px), .interaction or .media="(max-width: 768px)"';

        return match (true) {
            in_array($when, ['idle', 'interaction'], true) && count($modifiers) === 1 && $value === null => $when,
            $when === 'visible' && count($modifiers) === 1 && $value === null => 'visible',
            $when === 'visible' && count($modifiers) === 2 && preg_match('/^\d+px$/', $modifiers[1]) === 1 && $value === null => 'visible:'.$modifiers[1],
            $when === 'media' && count($modifiers) === 1 && $value !== null && trim($value) !== '' => 'media:'.trim($value),
            $when === 'media' => throw new CompileException("nitro:hydrate.media on <nitro:{$name}> needs a media query: nitro:hydrate.media=\"(max-width: 768px)\"", $file, $line),
            default => throw new CompileException("{$usage} (on <nitro:{$name}>)", $file, $line),
        };
    }

    /** Every child tag of a template, in order. @return list<array{0: int, 1: self}> offset => tag */
    public static function all(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches, PREG_OFFSET_CAPTURE);
        $tags = [];

        foreach ($matches[0] as $number => [, $offset]) {
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
