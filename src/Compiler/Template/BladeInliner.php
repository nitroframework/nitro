<?php

namespace Nitro\Fusion\Compiler\Template;

use Illuminate\View\Factory;
use InvalidArgumentException;
use Nitro\Fusion\Compiler\CompileException;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * Blade's @include and anonymous components (<x-name>) in a Fusion view, for the browser: before
 * the template compiler reads the view, each is replaced by the view it names (inlined), so the
 * compiled render function renders what Blade renders. The server still renders the original
 * view with Blade; tests/Parity checks the two agree.
 *
 * An inlined view reads its variables from the place that includes it: the inliner marks the
 * region with @fusionScope({vars}) ... @endfusionScope, and the template compiler replaces those
 * variables in the region's expressions by the PHP expressions they were given:
 *
 *   include: @include('row', ['cake' => $item])    $cake in row.blade.php reads $item
 *   <x-badge :tone="$t">New</x-badge>     $tone reads $t; {{ $slot }} is "New", compiled where
 *                                         the tag is (@fusionSlot: the parent's variables)
 *
 * As Blade's: an included view sees the variables around it, a component only its own (its
 * @props, its attributes, $slot and the named slots, $errors). A component's $attributes is
 * worked out as ComponentAttributeBag works it out (AttributeBag). Whitespace follows Blade: the
 * newline after @include and after a component tag is dropped (PHP's "?>"), a slot is trimmed.
 *
 * Not inlined: class-based components (their class renders on the server only), dynamic view
 * names, <fusion:...> children inside an inlined view. Each is a CompileException.
 */
final class BladeInliner
{
    private const MAX_DEPTH = 32;


    /** The expanded source. */
    private string $out = '';

    /** @var list<array{0: ?string, 1: int}> by line of the expanded source (0-based): the file and line it came from */
    private array $lines = [];

    /** @var list<array{0: string, 1: ?string, 2: int, 3: int}> slot contents by placeholder: [source, file, line] and the frame they belong outside of */
    private array $slots = [];

    private int $frames = 0;

    /** @var array<string, true> the files inlined */
    private array $files = [];

    private readonly ComponentParts $parts;

    public function __construct(
        private readonly Factory $views,
        private readonly ComponentTags $tags,
    ) {
        $this->parts = new ComponentParts($tags);
    }

    /** Whether a view uses anything this inlines (most do not: they compile as they are). */
    public static function needed(string $source): bool
    {
        return preg_match('/@include(?:If|When|Unless)?\s*\(|<\s*x[-:]/', $source) === 1;
    }

    /**
     * The view with its includes and components inlined, and where each of its lines came from.
     *
     * @return array{0: string, 1: list<array{0: ?string, 1: int}>}
     */
    public function expand(string $source, ?string $file): array
    {
        $this->out = '';
        $this->lines = [[$file, 1]];
        $this->slots = [];
        $this->frames = 0;
        $this->files = [];
        $this->inline($source, $file, 1, 0);

        return [$this->out, $this->lines];
    }

    /** @return list<string> the files expand() inlined */
    public function files(): array
    {
        return array_keys($this->files);
    }

    /**
     * The views a view includes or uses as components, and theirs (the bundle's version follows
     * their changes). A quick reading: what it cannot resolve, it leaves out.
     *
     * @return list<string>
     */
    public function dependencies(string $file, array &$seen = []): array
    {
        if (isset($seen[$file]) || ! is_file($file)) {
            return [];
        }

        $seen[$file] = true;
        $source = (string) file_get_contents($file);
        $names = [];

        if (preg_match_all('/@include(?:If|When|Unless)?\s*\((.*?)\)\s*$/m', $source, $match)) {
            foreach ($match[1] as $args) {
                if (preg_match_all('/([\'"])([\w.\-:\/]+)\1/', $args, $strings)) {
                    $names = [...$names, ...$strings[2]];
                }
            }
        }

        if (preg_match_all('/<\s*x[-:]([\w\-:.]+)/', $source, $match)) {
            foreach ($match[1] as $component) {
                try {
                    $class = $component === 'slot' ? null : $this->tags->componentClass($component);
                } catch (InvalidArgumentException) {
                    $class = null;
                }

                if (is_string($class) && ! class_exists($class)) {
                    $names[] = $class;
                }
            }
        }

        $files = [];

        foreach (array_unique($names) as $name) {
            try {
                $path = $this->views->getFinder()->find($name);
            } catch (InvalidArgumentException) {
                continue;
            }

            $files = [$path, ...$files, ...$this->dependencies($path, $seen)];
        }

        return array_values(array_unique($files));
    }

    // ------------------------------------------------------------ reading a view

    private function inline(string $source, ?string $file, int $line, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new CompileException('Views include each other without end (more than '.self::MAX_DEPTH.' levels)', $file, $line);
        }

        $at = 0;
        $length = strlen($source);

        while ($at < $length) {
            if (! preg_match('/\{\{--|@verbatim\b|@(?:include(?:If|When|Unless)?)\s*\(|<\s*\/?\s*x[-:]|\x00SLOT(\d+)\x00/', $source, $match, PREG_OFFSET_CAPTURE, $at)) {
                $this->emit(substr($source, $at), $file, $line);

                return;
            }

            $found = $match[0][1];
            $line = $this->emit(substr($source, $at, $found - $at), $file, $line);
            $token = $match[0][0];

            /** Blade's comments render nothing (Blade removes them before it reads components). */
            if ($token === '{{--') {
                $end = strpos($source, '--}}', $found);
                $end = $end === false ? $length : $end + 4;
                $line += substr_count($source, "\n", $found, $end - $found);
                $at = $end;

                continue;
            }

            /** @verbatim is the template compiler's to read, as it is. */
            if ($token === '@verbatim') {
                $end = strpos($source, '@endverbatim', $found);
                $end = $end === false ? $length : $end + strlen('@endverbatim');
                $line = $this->emit(substr($source, $found, $end - $found), $file, $line);
                $at = $end;

                continue;
            }

            if (str_starts_with($token, "\x00SLOT")) {
                $this->slot((int) $match[1][0], $file, $line, $depth);
                $at = $found + strlen($token);

                continue;
            }

            /** @@include is Blade's escape (the text "@include"); after a word, @ is text to Blade (its \B@). */
            if ($token[0] === '@' && $found > 0 && ($source[$found - 1] === '@' || preg_match('/\w/', $source[$found - 1]) === 1)) {
                $line = $this->emit($token, $file, $line);
                $at = $found + strlen($token);

                continue;
            }

            if ($token[0] === '@') {
                [$at, $line] = $this->include($source, $found, $file, $line, $depth);

                continue;
            }

            [$at, $line] = $this->component($source, $found, $file, $line, $depth);
        }
    }

    /** Text as it is; returns the line it ends on. */
    private function emit(string $text, ?string $file, int $line): int
    {
        $this->out .= $text;

        for ($i = substr_count($text, "\n"); $i > 0; $i--) {
            $this->lines[] = [$file, ++$line];
        }

        return $line;
    }

    /**
     * @return array{0: int, 1: int} where reading goes on, and its line
     */
    private function include(string $source, int $at, ?string $file, int $line, int $depth): array
    {
        preg_match('/\G@(include(?:If|When|Unless)?)\s*\(/', $source, $match, 0, $at);
        $open = $at + strlen($match[0]) - 1;
        $close = PhpSource::closing($source, $open, $file, $line);
        $directive = $match[1];
        $args = PhpSource::arguments(substr($source, $open + 1, $close - $open - 1), "@{$directive}", $file, $line);
        [$condition, $view, $data] = match ($directive) {
            'include', 'includeIf' => [null, $args[0] ?? null, $args[1] ?? null],
            'includeWhen' => [$args[0] ?? null, $args[1] ?? null, $args[2] ?? null],
            'includeUnless' => [isset($args[0]) ? new Expr\BooleanNot($args[0]) : null, $args[1] ?? null, $args[2] ?? null],
            default => throw new CompileException("@{$directive} is not an include", $file, $line),
        };

        if (! $view instanceof Scalar\String_) {
            throw new CompileException("@{$directive} names its view with a string (such as '{$this->exampleView()}') so that it can be compiled for the browser", $file, $line);
        }

        try {
            $path = $this->views->getFinder()->find($view->value);
        } catch (InvalidArgumentException) {
            if ($directive !== 'includeIf') {
                throw new CompileException("@{$directive}: there is no view [{$view->value}]", $file, $line);
            }

            $path = null;
        }

        $vars = $data === null ? [] : PhpSource::literalArray($data, "@{$directive}'s data", $file, $line);
        $after = $close + 1;
        $next = $line + substr_count($source, "\n", $at, $after - $at);

        if ($path === null) {
            /** @includeIf of a view that is not there: nothing, and the newline after it goes (as Blade's statement). */
            $this->out .= '@fusionScope('.$this->frame([], false, $view->value).')@endfusionScope';

            return [$after, $next];
        }

        $this->files[$path] = true;
        $this->out .= ($condition !== null ? '@if('.PhpSource::print($condition).')' : '').'@fusionScope('.$this->frame($vars, false, $view->value).')';
        $this->inline((string) file_get_contents($path), $path, 1, $depth + 1);
        $this->out .= '@endfusionScope'.($condition !== null ? '@endif' : '');

        return [$after, $next];
    }

    /**
     * @return array{0: int, 1: int} where reading goes on, and its line
     */
    private function component(string $source, int $at, ?string $file, int $line, int $depth): array
    {
        if (preg_match(ComponentTags::CLOSING, $source, $match, 0, $at)) {
            throw new CompileException("{$match[0]} closes no <x-{$match[1]}>", $file, $line);
        }

        if (preg_match(ComponentTags::SLOT, $source, $match, 0, $at)) {
            throw new CompileException('<x-slot> belongs inside a component tag', $file, $line);
        }

        $selfClosing = preg_match(ComponentTags::SELF_CLOSING, $source, $match, 0, $at) === 1;

        if (! $selfClosing && ! preg_match(ComponentTags::OPENING, $source, $match, 0, $at)) {
            throw new CompileException('This <x-...> tag cannot be read as a Blade component', $file, $line);
        }

        $name = $match[1];
        $attributeString = $match['attributes'];
        $contentStart = $at + strlen($match[0]);
        $contentLine = $line + substr_count($match[0], "\n");
        [$content, $after] = $selfClosing ? ['', $contentStart] : $this->contents($source, $contentStart, $name, $file, $line);

        $this->expandComponent($name, $attributeString, $content, $file, $contentLine, $depth);

        return [$after, $line + substr_count($source, "\n", $at, $after - $at)];
    }

    /**
     * What is between <x-name> and the </x-...> that closes it (Blade's closing tags close the
     * innermost open one; <x-slot> tags are the component's own).
     *
     * @return array{0: string, 1: int} the content, and where reading goes on
     */
    private function contents(string $source, int $from, string $name, ?string $file, int $line): array
    {
        $depth = 0;
        $at = $from;

        while (preg_match('/<\s*\/?\s*x[-:]/', $source, $match, PREG_OFFSET_CAPTURE, $at)) {
            $found = $match[0][1];

            if (preg_match(ComponentTags::SLOT_CLOSING, $source, $tagMatch, 0, $found) || preg_match(ComponentTags::SLOT, $source, $tagMatch, 0, $found)) {
                $at = $found + strlen($tagMatch[0]);
            } elseif (preg_match(ComponentTags::CLOSING, $source, $tagMatch, 0, $found)) {
                if ($depth === 0) {
                    return [substr($source, $from, $found - $from), $found + strlen($tagMatch[0])];
                }

                $depth--;
                $at = $found + strlen($tagMatch[0]);
            } elseif (preg_match(ComponentTags::SELF_CLOSING, $source, $tagMatch, 0, $found)) {
                $at = $found + strlen($tagMatch[0]);
            } elseif (preg_match(ComponentTags::OPENING, $source, $tagMatch, 0, $found)) {
                $depth++;
                $at = $found + strlen($tagMatch[0]);
            } else {
                $at = $found + 1;
            }
        }

        throw new CompileException("<x-{$name}> is not closed", $file, $line);
    }

    /**
     * The component the tag names, inlined: its view, with its props and attributes worked out
     * from the tag, and its slots from the tag's content.
     */
    private function expandComponent(string $name, string $attributeString, string $content, ?string $file, int $line, int $depth): void
    {
        if ($name === 'dynamic-component') {
            throw new CompileException('<x-dynamic-component> names its component at run time: use <fusion:dynamic-component :is> for Fusion components', $file, $line);
        }

        try {
            $class = $this->tags->componentClass($name);
        } catch (InvalidArgumentException $exception) {
            throw new CompileException($exception->getMessage(), $file, $line);
        }

        if (class_exists($class)) {
            throw new CompileException("<x-{$name}> is the class component {$class}: its class runs on the server only, so it cannot render in the browser (make it an anonymous component, resources/views/components/...)", $file, $line);
        }

        $path = $this->views->getFinder()->find($class);
        $this->files[$path] = true;
        [$view, $kept] = ComponentParts::setAside((string) file_get_contents($path));

        [$attributes, $bound] = $this->tags->attributes($attributeString);

        if (array_key_exists('attributes', $attributes)) {
            throw new CompileException("<x-{$name} {{ \$attributes }}>: passing a component's attributes on to another is not supported in the browser yet", $file, $line);
        }

        /** fusion:click="..." and the other fusion: attributes, as written: the template compiler reads them where they land. */
        preg_match_all('/(?<=\s|^)(fusion:[\w\-.]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'))?/', $attributeString, $fusionMatches, PREG_SET_ORDER);
        $fusion = [];

        foreach ($fusionMatches as $match) {
            $fusion[$match[1]] = $match[0];
        }

        [$slots, $defaultSlot] = $this->parts->namedSlots($content, $name, $file, $line);
        $slots = ['slot' => [trim($defaultSlot), $line], ...$slots];

        [$props, $view] = $this->parts->props($view, $path);
        [$vars, $bag] = $this->parts->componentVariables($attributes, $bound, $props);

        /** A named slot the tag gives is set (@isset($footer), @if ($footer)); its content is placed by {{ $footer }}. */
        foreach (array_keys($slots) as $slotName) {
            if ($slotName !== 'slot' && ! isset($vars[$slotName])) {
                $vars[$slotName] = 'true';
            }
        }

        /** What the view only tests (isset($x), $x ?? ...) and nothing gives is undefined: null, as in Blade. */
        if (preg_match_all('/isset\s*\(\s*\$(\w+)|\$(\w+)\s*\?\?/', $view, $tested)) {
            foreach (array_filter([...$tested[1], ...$tested[2]]) as $testedName) {
                if (! isset($vars[$testedName]) && ! in_array($testedName, ['attributes', 'slot', 'errors', 'this'], true)) {
                    $vars[$testedName] = 'null';
                }
            }
        }

        $id = ++$this->frames;
        $bagVars = [];
        $bagCodes = [];

        foreach (array_keys($bag) as $i => $attribute) {
            if (isset($fusion[$attribute])) {
                continue;
            }

            $bagVars["__fa{$i}"] = $bag[$attribute];
            $bagCodes[$attribute] = "\$__fa{$i}";
        }

        $view = $this->slotsIn($view, $slots, $id, $path, $file);
        $view = AttributeBag::render($view, $bagCodes, $fusion, $bag, $id, $path);

        if (preg_match('/\$attributes\b/', $view, $left, PREG_OFFSET_CAPTURE)) {
            throw new CompileException('$attributes is used in a way the browser cannot render: use {{ $attributes }}, ->merge([...]), ->class([...]), ->only([...]), ->except([...]) in a tag, or ->get() and ->has()', $path, 1 + substr_count($view, "\n", 0, $left[0][1]));
        }

        $this->out .= '@fusionScope('.$this->frame([...$vars, ...$bagVars], true, "<x-{$name}>", $id).')';
        $this->inline(ComponentParts::putBack($view, $kept), $path, 1, $depth + 1);
        $this->out .= '@endfusionScope';
    }

    /**
     * {{ $slot }} and {{ $name }} (a named slot) become the slot's content; $slot->isEmpty() and
     * ->isNotEmpty() are known here.
     *
     * @param  array<string, array{0: string, 1: int}>  $slots
     */
    private function slotsIn(string $view, array $slots, int $frame, string $path, ?string $tagFile): string
    {
        foreach ($slots as $slot => [$content, $slotLine]) {
            $quoted = preg_quote($slot, '/');
            $view = preg_replace_callback('/\{\{\s*\$'.$quoted.'\s*\}\}|\{!!\s*\$'.$quoted.'\s*!!\}/', function () use ($content, $slotLine, $frame, $tagFile) {
                $this->slots[] = [$content, $tagFile, $slotLine, $frame];

                return "\x00SLOT".(count($this->slots) - 1)."\x00";
            }, $view);
            $view = preg_replace('/\$'.$quoted.'\s*->\s*isEmpty\(\s*\)/', $content === '' ? 'true' : 'false', $view);
            $view = preg_replace('/\$'.$quoted.'\s*->\s*isNotEmpty\(\s*\)/', $content === '' ? 'false' : 'true', $view);

            if (preg_match('/\$'.$quoted.'\b(?!\s*\?\?)/', $view, $left, PREG_OFFSET_CAPTURE) && $slot === 'slot') {
                throw new CompileException('$slot is used in a way the browser cannot render: use {{ $slot }}, $slot->isEmpty() or $slot->isNotEmpty()', $path, 1 + substr_count($view, "\n", 0, $left[0][1]));
            }
        }

        return $view;
    }

    /**
     * A slot's placeholder: its content, compiled with the variables of where the component tag
     * is (@fusionSlot: those outside the component's frame).
     */
    private function slot(int $index, ?string $file, int $line, int $depth): void
    {
        [$content, $slotFile, $slotLine, $frame] = $this->slots[$index];
        $this->out .= "@fusionSlot({$frame})";
        $this->inline($content, $slotFile, $slotLine, $depth + 1);
        $this->out .= '@endfusionSlot';
    }

    // ------------------------------------------------------------ frames

    /**
     * A frame's description for @fusionScope: its variables (name => PHP, read where the region
     * is), whether it sees the variables around it (an include) or not (a component), a label.
     *
     * @param  array<string, string>  $vars
     */
    private function frame(array $vars, bool $isolated, string $label, ?int $id = null): string
    {
        return base64_encode(json_encode(['id' => $id ?? ++$this->frames, 'vars' => $vars, 'isolated' => $isolated, 'label' => $label], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function exampleView(): string
    {
        return 'partials.row';
    }
}
