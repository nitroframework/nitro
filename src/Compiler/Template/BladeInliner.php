<?php

namespace Nitro\Compiler\Template;

use Illuminate\View\Factory;
use InvalidArgumentException;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\Template\Parts\ComponentCalls;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * Blade's @include and anonymous components (<x-name>) in a Nitro view, for the browser, before
 * the template compiler reads the view, so the compiled render function renders what Blade
 * renders. The server still renders the original view with Blade; tests/Parity checks the two
 * agree.
 *
 * An @include is replaced by the view it names (inlined): the inliner marks the region with
 * @nitroScope({vars}) ... @endnitroScope, and the template compiler replaces those variables in
 * the region's expressions by the PHP expressions they were given:
 *
 *   include: @include('row', ['cake' => $item])    $cake in row.blade.php reads $item
 *
 * A component tag is a call of the component's view, compiled once for the names the tag gives
 * (ComponentViews, Parts\ComponentCalls): its variables are the call's inputs, read where the tag
 * is; its slots are compiled there too, and placed by the view:
 *
 *   <x-badge :tone="$t">New</x-badge>     the badge's view, called with tone = $t; {{ $slot }}
 *                                         places "New", rendered with the caller's variables
 *
 * As Blade's: an included view sees the variables around it, a component only its own (its
 * @props, its attributes, $slot and the named slots, $errors). A component's $attributes is
 * worked out as ComponentAttributeBag works it out (AttributeBag). Whitespace follows Blade: the
 * newline after @include and after a component tag is dropped (PHP's "?>"), a slot is trimmed.
 *
 * Not compiled: class-based components (their class renders on the server only), dynamic view
 * names, <nitro:...> children inside a component's view. Each is a CompileException.
 */
final class BladeInliner
{
    private const MAX_DEPTH = 32;

    /** The expanded source. */
    private string $out = '';

    /** @var list<array{0: ?string, 1: int}> by line of the expanded source (0-based): the file and line it came from */
    private array $lines = [];

    private readonly ComponentParts $parts;

    /** The @each loops so far: each loop's variables are its own. */
    private int $eaches = 0;

    /**
     * @param  TemplateCompiler|null  $compiler  the compiler reading the expanded view (a component's view is compiled like it); null: dependencies() only
     */
    public function __construct(
        private readonly Factory $views,
        private readonly ComponentTags $tags,
        private readonly ?TemplateCompiler $compiler = null,
        private readonly ?ComponentViews $componentViews = null,
    ) {
        $this->parts = new ComponentParts($tags);
    }

    /** The directives that render another view: the @include family and @each. */
    private const INCLUDES = '@(?:include(?:If|When|Unless|First)?|each)\s*\(';

    /** Whether a view uses anything this inlines (most do not: they compile as they are). */
    public static function needed(string $source): bool
    {
        return preg_match('/'.self::INCLUDES.'|<\s*x[-:]/', $source) === 1;
    }

    /**
     * The view with its includes inlined and its components called, and where each of its lines
     * came from. trimmed: its end is trimmed where it renders (a component's view ending a slot).
     *
     * @return array{0: string, 1: list<array{0: ?string, 1: int}>}
     */
    public function expand(string $source, ?string $file, bool $trimmed = false): array
    {
        $this->out = '';
        $this->lines = [[$file, 1]];
        $this->eaches = 0;
        $this->inline($source, $file, 1, 0, $trimmed);

        return [$this->out, $this->lines];
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

        if (preg_match_all('/'.self::INCLUDES.'(.*?)\)\s*$/m', $source, $match)) {
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

    /** trimmed: the source's end is trimmed where it renders (Blade trims a slot): an include or a component ending it is too. */
    private function inline(string $source, ?string $file, int $line, int $depth, bool $trimmed = false): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new CompileException('Views include each other without end (more than '.self::MAX_DEPTH.' levels)', $file, $line);
        }

        $at = 0;
        $length = strlen($source);

        while ($at < $length) {
            if (! preg_match('/\{\{--|@verbatim\b|'.self::INCLUDES.'|<\s*\/?\s*x[-:]/', $source, $match, PREG_OFFSET_CAPTURE, $at)) {
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

            /** @@include is Blade's escape (the text "@include"); after a word, @ is text to Blade (its \B@). */
            if ($token[0] === '@' && $found > 0 && ($source[$found - 1] === '@' || preg_match('/\w/', $source[$found - 1]) === 1)) {
                $line = $this->emit($token, $file, $line);
                $at = $found + strlen($token);

                continue;
            }

            if ($token[0] === '@') {
                [$at, $line] = $this->include($source, $found, $file, $line, $depth, $trimmed);

                continue;
            }

            [$at, $line] = $this->component($source, $found, $file, $line, $depth, $trimmed);
        }
    }

    /** Whether nothing but whitespace follows in a trimmed source: what ends at $at ends it. */
    private static function ends(string $source, int $at, bool $trimmed): bool
    {
        return $trimmed && trim(substr($source, $at)) === '';
    }

    /** An inlined view on a line of its own (the line map is by line): a comment holding a newline, which renders nothing. */
    private function startLine(?string $file, int $line): void
    {
        if ($this->out !== '' && ! str_ends_with($this->out, "\n")) {
            $this->emit("{{--\n--}}", $file, $line - 1);
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
    private function include(string $source, int $at, ?string $file, int $line, int $depth, bool $trimmed): array
    {
        preg_match('/\G@(include(?:If|When|Unless|First)?|each)\s*\(/', $source, $match, 0, $at);
        $open = $at + strlen($match[0]) - 1;
        $close = PhpSource::closing($source, $open, $file, $line);
        $directive = $match[1];
        $args = PhpSource::arguments(substr($source, $open + 1, $close - $open - 1), "@{$directive}", $file, $line);
        $after = $close + 1;
        $next = $line + substr_count($source, "\n", $at, $after - $at);

        if ($directive === 'each') {
            $this->each($args, $file, $line, $depth);

            return [$after, $next];
        }

        [$condition, $view, $data] = match ($directive) {
            'include', 'includeIf', 'includeFirst' => [null, $args[0] ?? null, $args[1] ?? null],
            'includeWhen' => [$args[0] ?? null, $args[1] ?? null, $args[2] ?? null],
            'includeUnless' => [isset($args[0]) ? new Expr\BooleanNot($args[0]) : null, $args[1] ?? null, $args[2] ?? null],
            default => throw new CompileException("@{$directive} is not an include", $file, $line),
        };

        [$name, $path] = $directive === 'includeFirst' ? $this->firstView($view, $file, $line) : [$this->viewName($view, "@{$directive}", $file, $line), null];

        try {
            $path ??= $this->views->getFinder()->find($name);
        } catch (InvalidArgumentException) {
            if ($directive !== 'includeIf') {
                throw new CompileException("@{$directive}: there is no view [{$name}]", $file, $line);
            }
        }

        $vars = $data === null ? [] : PhpSource::literalArray($data, "@{$directive}'s data", $file, $line);

        if ($path === null) {
            /** @includeIf of a view that is not there: nothing, and the newline after it goes (as Blade's statement). */
            $this->out .= '@nitroScope('.$this->frame([], false, $name).')@endnitroScope';

            return [$after, $next];
        }

        $ends = self::ends($source, $after, $trimmed);
        $this->out .= $condition !== null ? '@if('.PhpSource::print($condition).')' : '';
        $this->inlineView($path, $vars, false, $name, $depth, $ends);
        $this->out .= $condition !== null ? '@endif' : '';

        return [$after, $next];
    }

    /**
     * A view, inlined in a frame of its own: its variables (name => PHP), whether it sees those
     * around it, its label; trimmed: its end is (it ends a slot).
     *
     * @param  array<string, string>  $vars
     */
    private function inlineView(string $path, array $vars, bool $isolated, string $label, int $depth, bool $trimmed = false): void
    {
        [$included, $firstLine] = self::rendered((string) file_get_contents($path));
        $included = $trimmed ? rtrim($included) : $included;
        $this->out .= '@nitroScope('.$this->frame($vars, $isolated, $label, ComponentParts::setAside($included)[0]).')';
        $this->startLine($path, $firstLine);
        $this->inline($included, $path, $firstLine, $depth + 1, $trimmed);
        $this->out .= '@endnitroScope';
    }

    /** A view's name, written as a string (a name worked out at run time cannot be compiled). */
    private function viewName(?Expr $view, string $directive, ?string $file, int $line): string
    {
        if (! $view instanceof Scalar\String_) {
            throw new CompileException("{$directive} names its view with a string (such as '{$this->exampleView()}') so that it can be compiled for the browser", $file, $line);
        }

        return $view->value;
    }

    /**
     * The view @includeFirst(['a', 'b']) renders: the first of them that is there, as Blade picks it.
     *
     * @return array{0: string, 1: string} its name and its file
     */
    private function firstView(?Expr $views, ?string $file, int $line): array
    {
        $names = [];

        foreach ($views instanceof Expr\Array_ ? $views->items : [null] as $item) {
            $names[] = $this->viewName($item?->value, '@includeFirst', $file, $line);
        }

        foreach ($names as $name) {
            try {
                return [$name, $this->views->getFinder()->find($name)];
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        throw new CompileException('@includeFirst: none of the views ['.implode(', ', $names).'] exist', $file, $line);
    }

    /**
     * Blade's @each('view', $items, 'item', 'empty'), as its renderEach() renders it: the view for
     * each item, seeing only $key and the item; for no items the empty view, or the text after
     * "raw|", as it is.
     *
     * @param  list<Expr>  $args
     */
    private function each(array $args, ?string $file, int $line, int $depth): void
    {
        $view = $this->viewName($args[0] ?? null, '@each', $file, $line);
        $iterator = $this->viewName($args[2] ?? null, '@each', $file, $line);

        if (! isset($args[1])) {
            throw new CompileException('@each needs the items: @each(\'view\', $items, \'item\')', $file, $line);
        }

        $find = function (string $name) use ($file, $line): string {
            try {
                return $this->views->getFinder()->find($name);
            } catch (InvalidArgumentException) {
                throw new CompileException("@each: there is no view [{$name}]", $file, $line);
            }
        };

        /** In a frame of its own: its markers keep the directives apart wherever it is written (after a word too). */
        $n = ++$this->eaches;
        $this->out .= '@nitroScope('.$this->frame([], false, "@each('{$view}')").')@forelse('.PhpSource::print($args[1])." as \$__eachKey{$n} => \$__eachItem{$n})";
        $this->inlineView($find($view), ['key' => "\$__eachKey{$n}", $iterator => "\$__eachItem{$n}"], true, "@each('{$view}')", $depth);

        if (isset($args[3])) {
            $this->out .= '@empty';
            $empty = $this->viewName($args[3], '@each', $file, $line);
            if (str_starts_with($empty, 'raw|')) {
                $this->out .= '@nitroScope('.$this->frame([], true, "@each('{$view}')").')@verbatim'.substr($empty, 4).'@endverbatim@endnitroScope';
            } else {
                $this->inlineView($find($empty), [], true, "@each('{$view}')", $depth);
            }
        }

        $this->out .= '@endforelse@endnitroScope';
    }

    /**
     * @return array{0: int, 1: int} where reading goes on, and its line
     */
    private function component(string $source, int $at, ?string $file, int $line, int $depth, bool $trimmed): array
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

        $this->expandComponent($name, $attributeString, $content, $file, $contentLine, $depth, self::ends($source, $after, $trimmed));

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
    private function expandComponent(string $name, string $attributeString, string $content, ?string $file, int $line, int $depth, bool $trimmed): void
    {
        if ($name === 'dynamic-component') {
            throw new CompileException('<x-dynamic-component> names its component at run time: use <nitro:dynamic-component :is> for Nitro components', $file, $line);
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
        [$view, $kept] = ComponentParts::setAside((string) file_get_contents($path));

        [$attributes, $bound] = $this->tags->attributes($attributeString);

        if (array_key_exists('attributes', $attributes)) {
            throw new CompileException("<x-{$name} {{ \$attributes }}>: passing a component's attributes on to another is not supported in the browser yet", $file, $line);
        }

        /** nitro:click="..." and the other nitro: attributes, as written: the template compiler reads them where they land. */
        preg_match_all('/(?<=\s|^)(nitro:[\w\-.]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'))?/', $attributeString, $nitroMatches, PREG_SET_ORDER);
        $nitro = [];

        foreach ($nitroMatches as $match) {
            $nitro[$match[1]] = $match[0];
        }

        [$slots, $defaultSlot] = $this->parts->namedSlots($content, $name, $file, $line);
        $slots = ['slot' => [ComponentParts::slotContent($defaultSlot), $line], ...$slots];

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

        $bagVars = [];
        $bagCodes = [];

        foreach (array_keys($bag) as $i => $attribute) {
            if (isset($nitro[$attribute])) {
                continue;
            }

            $bagVars["__fa{$i}"] = $bag[$attribute];
            $bagCodes[$attribute] = "\$__fa{$i}";
        }

        $view = $this->slotsIn($view, $slots, $path);
        $view = AttributeBag::render($view, $bagCodes, $nitro, $bag, $path);

        [$view, $slotVars] = $this->parts->slotAttributes($view, $slots, $path);

        if (preg_match('/\$attributes\b/', $view, $left, PREG_OFFSET_CAPTURE)) {
            throw new CompileException('$attributes is used in a way the browser cannot render: use {{ $attributes }}, ->merge([...]), ->class([...]), ->only([...]), ->except([...]) in a tag, or ->get() and ->has()', $path, 1 + substr_count($view, "\n", 0, $left[0][1]));
        }

        $call = $this->call($name, $path, [...$vars, ...$bagVars, ...$slotVars], $view, $kept, $trimmed);
        $this->callTag($call, $nitro, $slots, $file, $line, $depth);
    }

    /**
     * The component's view, worked out for this tag, compiled once as a view of its own
     * (ComponentViews): its variables are its inputs ($this->name in it), read where the tag is.
     * The same tag elsewhere (the same names) calls the same view.
     *
     * @param  array<string, string>  $inputs  name => PHP
     * @param  list<string>  $kept  what ComponentParts::setAside() took out of the view
     * @return string the call, for @nitroCall: the view's id and its inputs (PHP)
     */
    private function call(string $name, string $path, array $inputs, string $view, array $kept, bool $trimmed): string
    {
        if ($this->compiler === null || $this->componentViews === null) {
            throw new \LogicException('BladeInliner compiles components with a TemplateCompiler and ComponentViews');
        }

        [$rendered, $firstLine] = self::rendered(ComponentParts::putBack($view, $kept));
        $own = [];

        foreach (array_keys($inputs) as $input) {
            $own[$input] = "\$this->{$input}";
        }

        $source = '@nitroScope('.$this->frame($own, true, "<x-{$name}>", $view).')'
            .($firstLine > 1 ? '{{--'.str_repeat("\n", $firstLine - 1).'--}}' : '')
            .($trimmed ? rtrim($rendered) : $rendered);
        [$id, $uses] = $this->componentViews->compile(ComponentViews::id($path, [$source, $trimmed]), $source, $path, "<x-{$name}>", array_keys($inputs), $this->compiler, $trimmed);
        $this->compiler->use($uses);
        $this->compiler->runsAppScript = $this->compiler->runsAppScript || $this->componentViews->runsAppScript($id);

        $read = $this->componentViews->inputs($id);
        $inputs = $read === null ? $inputs : array_intersect_key($inputs, array_flip($read));

        foreach ($this->componentViews->pathInputs($id) as $input) {
            $inputs[$input] = $this->path($inputs[$input], $path);
        }

        $items = [];

        foreach ($inputs as $input => $code) {
            $items[] = var_export($input, true)." => ({$code})";
        }

        return base64_encode(json_encode(['view' => $id, 'inputs' => '['.implode(', ', $items).']'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * An input the view names an error's field by, as PHP: a field written out ('form.email', or
     * the start of one, 'items.'.$i) by the browser's name for its property; a variable of a
     * component's view, passed on, names one in that view too.
     */
    private function path(string $code, string $path): string
    {
        $expression = PhpSource::expression($code, $path, 1);
        $start = $expression;

        while ($start instanceof Expr\BinaryOp\Concat || $start instanceof Expr\BinaryOp\Coalesce) {
            $start = $start->left;
        }

        if ($start instanceof Expr\Variable && is_string($start->name)) {
            $this->compiler?->passesPath($start->name);
        }

        if (! $start instanceof Scalar\String_ || $this->compiler === null) {
            return $code;
        }

        $start->value = $this->compiler->scope()->browserPath($start->value);

        return PhpSource::print($expression);
    }

    /**
     * The call where the tag is: its element with the tag's nitro: attributes, and its slots, each
     * compiled here (the caller's variables) and trimmed as Blade trims a slot; then @nitroCall.
     *
     * @param  array<string, string>  $nitro
     * @param  array<string, array{0: string, 1: int, 2?: string}>  $slots
     */
    private function callTag(string $call, array $nitro, array $slots, ?string $file, int $line, int $depth): void
    {
        $this->emit('<'.ComponentCalls::TAG.implode('', array_map(fn (string $written) => ' '.$written, $nitro)).'>', $file, $line);

        foreach ($slots as $slot => [$content, $slotLine]) {
            if ($content === '') {
                continue;
            }

            $this->out .= '<'.ComponentCalls::SLOT.' name="'.($slot === 'slot' ? 'default' : $slot).'">@nitroSlot()';
            $this->inline($content, $file, $slotLine, $depth + 1, true);
            $this->out .= '@endnitroSlot</'.ComponentCalls::SLOT.'>';
        }

        $this->out .= '</'.ComponentCalls::TAG.">@nitroCall({$call})";
    }

    /**
     * {{ $slot }} and {{ $name }} (a named slot) place the slot's content ({{ $slots['name'] }}:
     * the call's slot); $slot->isEmpty() and ->isNotEmpty() are known here.
     *
     * @param  array<string, array{0: string, 1: int}>  $slots
     */
    private function slotsIn(string $view, array $slots, string $path): string
    {
        foreach ($slots as $slot => [$content]) {
            $quoted = preg_quote($slot, '/');
            $placed = "\$slots['".($slot === 'slot' ? 'default' : $slot)."']";
            $view = (string) preg_replace('/\{\{\s*\$'.$quoted.'\s*\}\}/', "{{ {$placed} }}", $view);
            $view = (string) preg_replace('/\{!!\s*\$'.$quoted.'\s*!!\}/', "{!! {$placed} !!}", $view);
            $view = preg_replace('/\$'.$quoted.'\s*->\s*isEmpty\(\s*\)/', $content === '' ? 'true' : 'false', $view);
            $view = preg_replace('/\$'.$quoted.'\s*->\s*isNotEmpty\(\s*\)/', $content === '' ? 'false' : 'true', $view);

            if (preg_match('/\$'.$quoted.'\b(?!\s*\?\?)/', $view, $left, PREG_OFFSET_CAPTURE) && $slot === 'slot') {
                throw new CompileException('$slot is used in a way the browser cannot render: use {{ $slot }}, $slot->isEmpty() or $slot->isNotEmpty()', $path, 1 + substr_count($view, "\n", 0, $left[0][1]));
            }
        }

        return $view;
    }

    // ------------------------------------------------------------ frames

    /** @return array{0: string, 1: int} a view without the whitespace it begins with (PhpEngine ltrim()s a view), and its first line */
    private static function rendered(string $view): array
    {
        preg_match('/^(?:\s|\{\{--.*?--\}\})*/s', $view, $leading);
        return [substr($view, strlen($leading[0])), 1 + substr_count($leading[0], "\n")];
    }

    /**
     * A frame's description for @nitroScope: its variables (name => PHP, read where the region
     * is), whether it sees the variables around it (an include) or not (a component), a label,
     * and its own imports (@use: a file's, as in Blade).
     *
     * @param  array<string, string>  $vars
     */
    private static function frame(array $vars, bool $isolated, string $label, string $source = ''): string
    {
        return base64_encode(json_encode(['vars' => $vars, 'isolated' => $isolated, 'label' => $label, 'imports' => TemplateCompiler::imports($source)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function exampleView(): string
    {
        return 'partials.row';
    }
}
