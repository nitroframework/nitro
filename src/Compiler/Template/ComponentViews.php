<?php

namespace Nitro\Compiler\Template;

use Nitro\Compiler\Scope;
use Nitro\Compiler\Subscriptions;

/**
 * Blade components, compiled once: each component's view, for the names its tags give (its
 * signature: the view as worked out for the tag, its values left as inputs), compiled once as a
 * view of its own and shipped once (Nitro.view(id, ...), before the definitions that call it). A
 * tag is a call of it (BladeInliner); the browser renders the call with the view's own instance
 * (runtime/view/calls.js).
 *
 * The view's inputs are its own names (the Scope's inputs): never aliased in an obfuscated
 * build; an input naming an error's field is given by the browser's name where it is called.
 * What the views use (#[Browser] classes, Laravel's helpers) is the bundle's, as a component's
 * view's is.
 *
 * One for each bundle compiled (registered scoped).
 *
 * @internal
 */
final class ComponentViews
{
    /** @var array<string, string> id => its Nitro.view(...) */
    private array $views = [];

    /** @var array<string, list<string>> id => the views it calls */
    private array $calls = [];

    /** @var array<string, array<string, array<string, true>>> id => what it uses (Compiler\Uses) */
    private array $uses = [];

    /** @var array<string, bool> id => whether it runs the app's own JavaScript (a nitro: attribute of nitro.attributes) */
    private array $appScript = [];

    /** @var array<string, string> id => the component members it was compiled with, for a view that uses its component */
    private array $owners = [];

    /** @var array<string, bool> id => whether it (or a view it calls) reads an error's field known as the page runs: its callers' definitions carry their names */
    private array $needsNames = [];

    /** @var array<string, list<string>> id => the inputs it reads */
    private array $read = [];

    /** @var array<string, list<string>> id => its expressions (JavaScript) */
    private array $expressions = [];

    /** @var array<string, list<array{locals: list<string>, php: string}|null>> id => its expressions' PHP twins (the server's render) */
    private array $phpExpressions = [];

    /** @var array<string, array<string, mixed>> id => its node tree */
    private array $trees = [];

    /** @var array<string, array{reach: list<array{0: string, 1: list<array{0: string, 1: ?int}>, 2: string}>, slots: array<string, list<list<array{0: string, 1: ?int}>>>}> id => what its callers decide of its nesting */
    private array $nesting = [];

    /** The id of a component's view for a signature. */
    public static function id(string $file, array $signature): string
    {
        return 'v'.substr(hash('xxh128', str_replace('\\', '/', $file)."\0".json_encode($signature, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * The view, compiled the first time a call needs it: its source (BladeInliner's, its inputs read
     * through the frame around it), in a compiler like the one compiling the caller.
     *
     * A view with nitro: attributes of its own (or calling one that has) uses the Nitro component
     * rendering it: its handlers and models are the component's. It is compiled for each component
     * whose members differ, as it is checked and compiled against them.
     *
     * @param  string  $label  what the bundle names it by (<x-badge>)
     * @param  list<string>  $inputs
     * @param  bool  $trimmed  the view ends a slot: its end is trimmed (TemplateCompiler::$endsTrimmed)
     * @return array{0: string, 1: array<string, array<string, true>>} the view's id for the call, and what the view uses (for the caller to use as its own)
     */
    public function compile(string $id, string $source, string $file, string $label, array $inputs, TemplateCompiler $like, bool $trimmed): array
    {
        $callerScope = $like->scope();
        $owner = hash('xxh128', json_encode([$callerScope->properties, $callerScope->clientMethods, $callerScope->serverMethods, $callerScope->computed, $callerScope->locked, array_keys($callerScope->stores)], JSON_THROW_ON_ERROR));

        if (isset($this->owners[$id]) && $this->owners[$id] !== $owner) {
            $id = self::id($file, [$id, $owner]);
        }

        if (! isset($this->views[$id])) {
            $this->views[$id] = '';
            $compiler = $like->sibling();
            $compiler->endsTrimmed = $trimmed;
            $compiler->componentView = true;
            $scope = new Scope(
                properties: $callerScope->properties,
                clientMethods: $callerScope->clientMethods,
                serverMethods: $callerScope->serverMethods,
                file: $file,
                locked: $callerScope->locked,
                computed: $callerScope->computed,
                componentName: $callerScope->componentName,
                names: $callerScope->names,
                stores: $callerScope->stores,
                inputs: $inputs,
            );

            try {
                $compiler->compile($source, $scope, $file);
            } catch (\Throwable $exception) {
                unset($this->views[$id]);

                throw $exception;
            }

            $subs = [];

            foreach ((new Subscriptions([], $inputs))->ofTemplate($compiler->expressions(), $compiler->expressionSources()) as $index => $keys) {
                $subs[] = "/* {$index} */ ".json_encode($keys, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }

            /** The inputs its expressions read: a call gives only those. */
            $code = implode("\n", $compiler->expressions());
            $inputs = $this->read[$id] = array_values(array_filter($inputs, fn (string $input) => preg_match('/\$this\.'.preg_quote($input, '/').'\b/', $code) === 1));

            $this->calls[$id] = $compiler->calls();
            $this->expressions[$id] = $compiler->expressions();
            $this->phpExpressions[$id] = $compiler->phpExpressions();
            $this->trees[$id] = $compiler->tree();
            $this->needsNames[$id] = $compiler->needsNames();
            $this->nesting[$id] = $compiler->nesting();
            $this->uses[$id] = $compiler->uses();
            $this->appScript[$id] = $compiler->runsAppScript;
            $this->views[$id] = '// '.$label." · Blade component\nNitro.view(".json_encode($id).", {\ninputs: ".json_encode($inputs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).",\n"
                .$compiler->fragment().",\nsubs: [\n".implode(",\n", $subs)."\n]\n});";

            $owns = preg_match('/(?<=\s)nitro:[\w\-]/', $source) === 1 || array_filter($this->calls[$id], fn (string $called) => isset($this->owners[$called])) !== [];

            if ($owns) {
                $this->owners[$id] ??= $owner;
            }
        }

        return [$id, $this->uses[$id] ?? []];
    }

    /**
     * What a call of the view decides of its elements' nesting (Nesting): its elements whose
     * rule reaches its top, and what is open where each slot is placed (nothing while it
     * compiles: a view calling itself).
     *
     * @return array{reach: list<array{0: string, 1: list<array{0: string, 1: ?int}>, 2: string}>, slots: array<string, list<list<array{0: string, 1: ?int}>>>}
     */
    public function nesting(string $id): array
    {
        return $this->nesting[$id] ?? ['reach' => [], 'slots' => []];
    }

    /**
     * The inputs the view reads: what a call of it gives (null while it compiles: a view calling
     * itself gives all).
     *
     * @return list<string>|null
     */
    public function inputs(string $id): ?array
    {
        return $this->read[$id] ?? null;
    }

    /** @return list<string> the views compiled, by id */
    public function ids(): array
    {
        return array_keys(array_filter($this->views, fn (string $view) => $view !== ''));
    }

    /** @return list<string> a view's expressions, as JavaScript */
    public function expressions(string $id): array
    {
        return $this->expressions[$id] ?? [];
    }

    /** @return list<array{locals: list<string>, php: string}|null> a view's expressions' PHP twins (the server's render), by the same index */
    public function phpExpressions(string $id): array
    {
        return $this->phpExpressions[$id] ?? [];
    }

    /** @return array<string, mixed> a view's node tree */
    public function tree(string $id): array
    {
        return $this->trees[$id] ?? [];
    }

    /** @return list<string> the views a view calls, by id */
    public function callsOf(string $id): array
    {
        return $this->calls[$id] ?? [];
    }

    /** Whether the view, or one it calls, reads an error's field known as the page runs: a component calling it carries its names. */
    public function needsNames(string $id): bool
    {
        return $this->needsNames[$id] ?? false;
    }

    /** Whether the view runs the app's own JavaScript (it, not the views it calls: theirs are theirs). */
    public function runsAppScript(string $id): bool
    {
        return $this->appScript[$id] ?? false;
    }

    /** @return list<string> every view compiled, as Nitro.view(...) */
    public function definitions(): array
    {
        return array_values(array_filter($this->views, fn (string $view) => $view !== ''));
    }
}
