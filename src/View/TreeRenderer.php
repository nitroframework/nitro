<?php

namespace Nitro\View;

use Closure;
use Illuminate\Support\LazyCollection;
use Nitro\Compiler\Template\ChildTag;
use Nitro\Support\Html;
use Nitro\Support\Protocol;
use RuntimeException;

/**
 * A compiled view (ServerViews) rendered on the server: the tree the browser renders
 * (runtime/view/render.js), as HTML, with the expressions' PHP twins. The browser's render
 * without what only the browser needs (anchors, tracking, updates, hydration): a block renders
 * its branch or its iterations in place, as Blade's PHP does.
 *
 * A twin gives what Blade prints there, as Blade prints it: written as it is. The tree's own text
 * and attribute values are as the HTML parser read them (character references decoded): written
 * encoded again, so the HTML parser reads them back the same. What Blade trims (an included view's
 * start: PhpEngine ltrim()s it; a Blade component's slot: trim()med) is trimmed in the output, as
 * Blade trims its string.
 *
 * An instance renders one component's view, with the views of the Blade components it calls
 * (their $this: the call's inputs, anything else the component's) and its children through the
 * host (TreeHost).
 *
 * @internal
 */
final class TreeRenderer
{
    private const SVG = 'svg';

    private const MATHML = 'math';

    /** Directives that continue the block before them (render.js CONTINUES). */
    private const CONTINUES = ['else-if', 'else', 'empty'];

    /** @var array<string, int> where each view Blade trims began (a {t: "w"} mark), by its id: an offset in the output */
    private array $trims = [];

    /**
     * @param  array<string, array{tree: array<string, mixed>, expressions: Closure}>  $calls  the Blade components' views it calls, by id
     * @param  array<string, mixed>  $shared  what a Blade component's view is given: the shared data, $errors, $__env
     */
    public function __construct(
        private readonly TreeHost $host,
        private readonly array $calls,
        private readonly array $shared,
    ) {
    }

    /**
     * The component's view, as HTML.
     *
     * @param  array{tree: array<string, mixed>, expressions: Closure, calls?: array<string, mixed>}  $view
     * @param  array<string, mixed>  $data  the view's data (extract()ed, as Blade's)
     * @param  array<string, string>  $slots  what its parent placed in its slots: name => HTML
     */
    public function render(array $view, object $component, array $data, array $slots): string
    {
        $instance = [
            'expressions' => $view['expressions']->call($component, $data),
            'owner' => $component,
            'slots' => $slots,
            'caller' => null,
            'outerLoop' => null,
        ];
        $out = '';
        $scope = self::scope([]);
        $this->nodes($view['tree']['h'] ?? [], $instance, $scope, $out, null, 'html');

        return $out;
    }

    /**
     * Nodes, written to $out. $parent: the element they are in (its tag), $ns its namespace. The
     * scope is theirs and what is after them (a loop sets in it what it leaves set: leave()), as
     * the browser's scope's arguments are. Returns a @break or @continue on its way out of the
     * loops it leaves ({kind, levels}).
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $instance
     * @param  array{args: list<mixed>, loops: list<int>}  $scope
     * @return array{kind: string, levels: int}|null
     */
    private function nodes(array $nodes, array $instance, array &$scope, string &$out, ?string $parent, string $ns): ?array
    {
        for ($i = 0, $count = count($nodes); $i < $count; $i++) {
            $node = $nodes[$i];

            switch ($node['t']) {
                case 'template':
                    $last = $i;

                    while (($nodes[$last + 1]['t'] ?? null) === 'template' && in_array($nodes[$last + 1]['i'][0]['c'], self::CONTINUES, true)) {
                        $last++;
                    }

                    $chain = array_slice($nodes, $i, $last - $i + 1);
                    $i = $last;
                    $signal = $this->chain($chain, $instance, $scope, $out, $parent, $ns);

                    if ($signal !== null) {
                        return $signal;
                    }

                    break;
                case 'x':
                    $out .= isset($node['e'])
                        ? (string) $this->value($instance, $node['code'], $scope)
                        : $node['src'] ?? $this->text($node['c'] ?? '', $parent, $ns);

                    break;
                case 'm':
                    $out .= '<!--'.($node['c'] ?? '').'-->';

                    break;
                case 't':
                    $this->tag($node, $instance, $scope, $out, $ns);

                    break;
                case 'c':
                    $this->child($node, $instance, $scope, $out, $parent, $ns);

                    break;
                case 'v':
                    $signal = $this->call($node, $instance, $scope, $out, $parent, $ns);

                    if ($signal !== null) {
                        return $signal;
                    }

                    break;
                case 's':
                    $out .= $instance['slots'][$node['c']] ?? '';

                    break;
                case 'w':
                    $this->trimMark($node, $out);

                    break;
                case 'p':
                    $html = '';
                    $teleported = $scope;
                    $this->nodes($node['h'] ?? [], $instance, $teleported, $html, $parent, $ns);
                    $this->host->teleport((string) $node['to'], $html);

                    break;
                case 'b':
                    if (! isset($node['code']) || $this->value($instance, $node['code'], $scope)) {
                        return ['kind' => (string) $node['c'], 'levels' => (int) ($node['levels'] ?? 1)];
                    }

                    break;
                default:
                    throw new RuntimeException("Nitro: unknown template node {$node['t']}");
            }
        }

        return null;
    }

    /** Text of the tree's own: as the HTML parser read it, written so it reads it back (in <script> and <style> nothing was decoded). */
    private function text(string $text, ?string $parent, string $ns): string
    {
        if ($ns === 'html' && $parent !== null && in_array(strtolower($parent), Html::RAW_TEXT, true)) {
            return $text;
        }

        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /**
     * An element: its attributes, its content, its end tag (none for a void element). The HTML
     * parser drops a newline right after <pre>'s start tag, as the compiler did from its text: one
     * is written there for it to drop.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $instance
     * @param  array{args: list<mixed>, loops: list<int>}  $scope
     */
    private function tag(array $node, array $instance, array &$scope, string &$out, string $parentNs): void
    {
        $tag = (string) $node['c'];
        $ns = match (true) {
            $tag === 'svg' => self::SVG,
            $tag === 'math' => self::MATHML,
            default => $parentNs,
        };
        $out .= '<'.$tag.$this->attributes($node, $instance, $scope).'>';

        if ($ns === 'html' && in_array(strtolower($tag), Html::VOID, true)) {
            return;
        }

        $children = $node['h'] ?? [];

        /** Its content as the tree has it, unless it starts with what renders (nl): as Blade prints that, whose newline the parser drops. */
        if ($ns === 'html' && in_array(strtolower($tag), Html::LEADING_NEWLINE, true) && ! isset($node['nl'])) {
            $out .= "\n";
        }

        /** SVG's <foreignObject> holds HTML again. */
        $this->nodes($children, $instance, $scope, $out, $tag, $ns === self::SVG && $tag === 'foreignObject' ? 'html' : $ns);
        $out .= '</'.$tag.'>';
    }

    /**
     * The element's attributes: its own, and in a Blade component's view the caller's nitro:
     * attributes it is given (d), read with the caller's (attributes.js attributeValues()).
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $instance
     * @param  array{args: list<mixed>, loops: list<int>}  $scope
     */
    private function attributes(array $node, array $instance, array $scope): string
    {
        $out = '';

        foreach ($node['a'] ?? [] as $attribute) {
            $given = isset($attribute['d'])
                ? array_map(fn (array $own) => [$own, $instance['caller']['instance'], $instance['caller']['scope']], $instance['caller']['attributes'][$attribute['d']] ?? [])
                : [[$attribute, $instance, $scope]];

            foreach ($given as [$own, $reader, $readScope]) {
                if (isset($own['when']) && ! $this->value($reader, $own['when'], $readScope)) {
                    continue;
                }

                $out .= ' '.$own['c'];

                if (! isset($own['h'])) {
                    continue;
                }

                /** w: where an included view begins (end: ends) in the value: Blade ltrim()s what each renders. */
                $trimming = [];
                $value = '';

                foreach ($own['h'] as $part) {
                    if (isset($part['w'])) {
                        if (isset($part['end'])) {
                            unset($trimming[$part['w']]);
                        } else {
                            $trimming[$part['w']] = true;
                        }

                        continue;
                    }

                    $text = isset($part['e'])
                        ? (string) $this->value($reader, $part['code'], $readScope)
                        : (isset($part['src']) && ! str_contains($part['src'], '"') ? $part['src'] : str_replace(['&', '"'], ['&amp;', '&quot;'], (string) $part['c']));

                    if ($trimming !== []) {
                        $text = ltrim($text);

                        if ($text !== '') {
                            $trimming = [];
                        }
                    }

                    $value .= $text;
                }

                $out .= '="'.$value.'"';
            }
        }

        return $out;
    }

    /**
     * A block and those continuing it (@elseif, @else, @empty), in place (blocks.js renderBranches()).
     *
     * @param  list<array<string, mixed>>  $chain
     * @param  array<string, mixed>  $instance
     * @param  array{args: list<mixed>, loops: list<int>}  $scope
     * @return array{kind: string, levels: int}|null
     */
    private function chain(array $chain, array $instance, array &$scope, string &$out, ?string $parent, string $ns): ?array
    {
        foreach ($chain as $node) {
            $directive = $node['i'][0];
            $children = $node['h'] ?? [];

            switch ($directive['c']) {
                case 'if':
                case 'else-if':
                    if (! $this->value($instance, $directive['code'], $scope)) {
                        break;
                    }

                    return $this->nodes($children, $instance, $scope, $out, $parent, $ns);
                case 'else':
                case 'empty':
                    return $this->nodes($children, $instance, $scope, $out, $parent, $ns);
                case 'error':
                    /** Blade's (CompilesErrors): the field's first message in the bag, as $message. */
                    $arguments = $this->value($instance, $directive['code'], $scope);
                    $bag = $this->shared['errors']->getBag($arguments[1] ?? 'default');

                    if (! $bag->has($arguments[0])) {
                        break;
                    }

                    $branch = self::with($scope, [$directive['message'] => $bag->first($arguments[0])]);

                    return $this->nodes($children, $instance, $branch, $out, $parent, $ns);
                case 'foreach':
                    [$signal, $produced] = $this->foreach($directive, $children, $instance, $scope, $out, $parent, $ns);

                    if ($signal !== null || $produced) {
                        return $signal;
                    }

                    break;
                case 'for':
                case 'while':
                    /** The twin works out each iteration's variables (@for's, by position: vars), and what they are after it. */
                    ['runs' => $runs, 'after' => $after] = $this->value($instance, $directive['code'], $scope);
                    $withValues = function (array $values) use ($directive, $scope): array {
                        $variables = [];

                        foreach ($directive['vars'] ?? [] as $i => $position) {
                            $variables[$position] = $values[$i];
                        }

                        return self::with($scope, $variables);
                    };
                    $last = $withValues($after)['args'];
                    $signal = null;

                    foreach ($runs as $values) {
                        $iteration = $withValues($values);
                        $signal = $this->nodes($children, $instance, $iteration, $out, $parent, $ns);

                        if ($signal !== null && ($signal['levels'] > 1 || $signal['kind'] === 'break')) {
                            $last = $iteration['args'];

                            break;
                        }
                    }

                    $this->leave($instance, $directive, $scope, $last);

                    return $signal !== null && $signal['levels'] > 1 ? ['kind' => $signal['kind'], 'levels' => $signal['levels'] - 1] : null;
                case 'switch':
                    return $this->switch($node, $instance, $scope, $out, $parent, $ns);
                default:
                    throw new RuntimeException("Nitro: unknown directive {$directive['c']}");
            }
        }

        return null;
    }

    /**
     * @foreach, its $loop as Blade's (ManagesLoops): returns [a signal leaving it, whether it had items].
     *
     * @param  array<string, mixed>  $directive
     * @param  list<array<string, mixed>>  $children
     * @param  array<string, mixed>  $instance
     * @param  array{args: list<mixed>, loops: list<int>}  $scope
     * @return array{0: array{kind: string, levels: int}|null, 1: bool}
     */
    private function foreach(array $directive, array $children, array $instance, array &$scope, string &$out, ?string $parent, string $ns): array
    {
        $data = $this->value($instance, $directive['forData'], $scope);
        /** $loop->parent: the $loop where the loop opens; in a Blade component's view's outermost loops, the caller's. */
        $parentLoop = isset($directive['parentLoop']) ? ($scope['args'][$directive['parentLoop']] ?? null) : $instance['outerLoop'];
        $length = is_countable($data) && ! $data instanceof LazyCollection ? count($data) : null;
        $loop = [
            'iteration' => 0,
            'index' => 0,
            'remaining' => $length,
            'count' => $length,
            'first' => true,
            'last' => isset($length) ? $length === 1 : null,
            'odd' => false,
            'even' => true,
            'depth' => $parentLoop !== null ? $parentLoop->depth + 1 : 1,
            'parent' => $parentLoop,
        ];
        $last = null;
        $signal = null;

        foreach ($data as $key => $value) {
            $loop = array_merge($loop, [
                'iteration' => $loop['iteration'] + 1,
                'index' => $loop['iteration'],
                'first' => $loop['iteration'] === 0,
                'odd' => ! $loop['odd'],
                'even' => ! $loop['even'],
                'remaining' => isset($loop['count']) ? $loop['remaining'] - 1 : null,
                'last' => isset($loop['count']) ? $loop['iteration'] == $loop['count'] - 1 : null,
            ]);
            $variables = [$directive['item'] => $value, $directive['loop'] => (object) $loop];

            if (isset($directive['key'])) {
                $variables[$directive['key']] = $key;
            }

            $iteration = self::with($scope, $variables);
            $iteration['loops'][] = $loop['index'];
            $last = $iteration['args'];
            $signal = $this->nodes($children, $instance, $iteration, $out, $parent, $ns);

            if ($signal !== null && ($signal['levels'] > 1 || $signal['kind'] === 'break')) {
                break;
            }
        }

        $this->leave($instance, $directive, $scope, $last);

        return [$signal !== null && $signal['levels'] > 1 ? ['kind' => $signal['kind'], 'levels' => $signal['levels'] - 1] : null, $last !== null];
    }

    /**
     * The variables a loop leaves set as PHP's loop does, read after it (its directive's left:
     * [its position in the loop, where it is read, and for a loop that ran no iteration: absent,
     * it keeps its value; null, null; else the expression giving what it was before]), set in
     * the scope the loop is in. $last: the variables its last iteration had (or as a @break left
     * them; @for's after it), null when none ran (foreach.js leaveLoop()).
     *
     * @param  array<string, mixed>  $instance
     * @param  array<string, mixed>  $directive
     * @param  array{args: list<mixed>, loops: list<int>}  $scope
     * @param  list<mixed>|null  $last
     */
    private function leave(array $instance, array $directive, array &$scope, ?array $last): void
    {
        foreach ($directive['left'] ?? [] as $left) {
            if ($last === null && ! array_key_exists(2, $left)) {
                continue;
            }

            $value = $last !== null ? ($last[$left[0]] ?? null) : ($left[2] === null ? null : $this->value($instance, $left[2], $scope));
            $scope = self::with($scope, [$left[1] => $value]);
        }
    }

    /**
     * A @switch, as PHP's: from the matching @case (else the @default) on, until a @break.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $instance
     * @param  array{args: list<mixed>, loops: list<int>}  $scope
     * @return array{kind: string, levels: int}|null
     */
    private function switch(array $node, array $instance, array &$scope, string &$out, ?string $parent, string $ns): ?array
    {
        $cases = $node['h'] ?? [];
        $subject = $this->value($instance, $node['i'][0]['code'], $scope);
        $start = null;

        foreach ($cases as $index => $case) {
            if ($case['i'][0]['c'] === 'case' && $subject == $this->value($instance, $case['i'][0]['code'], $scope)) {
                $start = $index;

                break;
            }
        }

        foreach ($start === null ? $cases : [] as $index => $case) {
            if ($case['i'][0]['c'] === 'default') {
                $start = $index;

                break;
            }
        }

        for ($i = $start ?? count($cases); $i < count($cases); $i++) {
            $signal = $this->nodes($cases[$i]['h'] ?? [], $instance, $scope, $out, $parent, $ns);

            if ($signal !== null) {
                return $signal['levels'] > 1 ? ['kind' => $signal['kind'], 'levels' => $signal['levels'] - 1] : null;
            }
        }

        return null;
    }

    /**
     * <nitro:name>: the child, placed by the host. Its slots render here first, with this view's
     * variables, as a Blade view renders what is between the tags before the component.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $instance
     * @param  array{args: list<mixed>, loops: list<int>}  $scope
     */
    private function child(array $node, array $instance, array $scope, string &$out, ?string $parent, string $ns): void
    {
        [$name, $props, $key, $number, $hydrate] = $this->value($instance, $node['props'], $scope);
        $slots = [];

        foreach ($node['slots'] ?? [] as $slot => $content) {
            $html = '';
            $this->nodes($content['h'] ?? [], $instance, $scope, $html, $parent, $ns);
            $slots[$slot] = $html;
        }

        $out .= $this->host->child($name, $props, $key !== null ? (string) $key : ChildTag::key($number, $scope['loops']), $number, $slots, $hydrate);
    }

    /**
     * A Blade component's call: its slots render first, with the caller's variables, trimmed (a
     * ComponentSlot's); then its view, with its inputs as $this and the caller's nitro:
     * attributes, slots and $loop (calls.js callInstance()).
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $instance
     * @param  array{args: list<mixed>, loops: list<int>}  $scope
     * @return array{kind: string, levels: int}|null
     */
    private function call(array $node, array $instance, array $scope, string &$out, ?string $parent, string $ns): ?array
    {
        $view = $this->calls[$node['v']] ?? throw new RuntimeException("Nitro: no compiled view {$node['v']}");
        $inputs = (array) $this->value($instance, $node['i'], $scope);
        $slots = [];

        foreach ($node['slots'] ?? [] as $name => $slot) {
            $html = '';
            $this->nodes($slot['h'] ?? [], $instance, $scope, $html, $parent, $ns);
            $slots[$name] = trim($html);
        }

        /** __empty_name: whether that slot renders empty ($slot->isEmpty()). */
        $empty = Protocol::emptySlot();

        foreach (array_keys($inputs) as $input) {
            if (str_starts_with((string) $input, $empty)) {
                $inputs[$input] = ($slots[substr((string) $input, strlen($empty))] ?? '') === '';
            }
        }

        $call = [
            'expressions' => $view['expressions']->call(self::inputs($inputs, $instance['owner']), $this->shared),
            'owner' => $instance['owner'],
            'slots' => $slots,
            'caller' => ['attributes' => $node['d'] ?? [], 'instance' => $instance, 'scope' => $scope],
            'outerLoop' => isset($node['l']) ? ($scope['args'][$node['l']] ?? null) : $instance['outerLoop'],
        ];

        $callScope = self::scope([]);

        return $this->nodes($view['tree']['h'] ?? [], $call, $callScope, $out, $parent, $ns);
    }

    /** Where a view Blade renders on its own begins ({t: "w", w: id}) and ends (end): its output ltrim()med, as PhpEngine's. */
    private function trimMark(array $node, string &$out): void
    {
        $id = (string) $node['w'];

        if (! isset($node['end'])) {
            $this->trims[$id] = strlen($out);

            return;
        }

        if (isset($this->trims[$id])) {
            $at = $this->trims[$id];
            unset($this->trims[$id]);
            $out = substr($out, 0, $at).ltrim(substr($out, $at));
        }
    }

    /**
     * An expression's twin, with the scope's variables.
     *
     * @param  array<string, mixed>  $instance
     * @param  array{args: list<mixed>, loops: list<int>}  $scope
     */
    private function value(array $instance, int $code, array $scope): mixed
    {
        $twin = $instance['expressions'][$code] ?? throw new RuntimeException("Nitro: expression {$code} runs in the browser only");

        return $twin(...$scope['args']);
    }

    /**
     * @param  list<mixed>  $args
     * @return array{args: list<mixed>, loops: list<int>}
     */
    private static function scope(array $args): array
    {
        return ['args' => $args, 'loops' => []];
    }

    /**
     * The scope with variables set at their positions (those before them that are not set yet: null).
     *
     * @param  array{args: list<mixed>, loops: list<int>}  $scope
     * @param  array<int, mixed>  $variables
     * @return array{args: list<mixed>, loops: list<int>}
     */
    private static function with(array $scope, array $variables): array
    {
        foreach ($variables as $position => $value) {
            for ($i = count($scope['args']); $i < $position; $i++) {
                $scope['args'][$i] = null;
            }

            $scope['args'][$position] = $value;
        }

        return $scope;
    }

    /**
     * A Blade component's view's $this: its inputs; anything else is the component's (calls.js).
     *
     * @param  array<string, mixed>  $inputs
     */
    private static function inputs(array $inputs, object $owner): object
    {
        return new class($inputs, $owner)
        {
            /** @param  array<string, mixed>  $inputs */
            public function __construct(private readonly array $inputs, private readonly object $owner)
            {
            }

            public function __get(string $name): mixed
            {
                return array_key_exists($name, $this->inputs) ? $this->inputs[$name] : (fn () => $this->{$name})->call($this->owner);
            }

            public function __isset(string $name): bool
            {
                return array_key_exists($name, $this->inputs) ? isset($this->inputs[$name]) : (fn () => isset($this->{$name}))->call($this->owner);
            }

            /** @param  list<mixed>  $arguments */
            public function __call(string $name, array $arguments): mixed
            {
                return (fn () => $this->{$name}(...$arguments))->call($this->owner);
            }
        };
    }
}
