<?php

namespace Nitro\Fusion\Rendering;

use Illuminate\Support\MessageBag;

/**
 * What Fusion keeps about a component instance besides its state.
 */
final class Context
{
    public string $id = '';

    public string $name = '';

    public MessageBag $errors;

    /** @var list<array{event: string, params: array, to?: string, self?: bool}> */
    public array $dispatches = [];

    /** @var array{url: string, navigate: bool}|null */
    public ?array $redirect = null;

    /** Whether the component's view is being rendered: #[Computed] values are kept meanwhile. */
    public bool $rendering = false;

    /** @var array<string, mixed> #[Computed] values worked out during this render */
    public array $computed = [];

    /** @var array<string, string> the slots its parent passed: name => HTML ($slot, $slots['name'] in its view) */
    public array $slots = [];

    /** Whether dehydrate() ran in this request. */
    public bool $dehydrated = false;

    /** @var list<string> JavaScript for the browser to run once it has the state ($this->js()) */
    public array $js = [];

    /** @var list<string>|null the page properties for the browser to reload after a #[Server] call (all of them: []) */
    public ?array $reload = null;

    /** @var array<string, array{mode: string, path: ?string, match: list<string>}> merge props a partial reload sends, with their rule (Fusion::render()'s) */
    public array $merges = [];

    /** @var array<string, \Nitro\Fusion\Merge> merge props from mount() ($this->merge(), ...): their rule is read when the page answers */
    public array $mergeProps = [];

    /** @var list<string> the page's properties loaded after it is shown ($this->defer()) */
    public array $deferred = [];

    /**
     * Every merge prop's rule, from a controller (Fusion::render()) or mount() ($this->merge(), ...).
     *
     * @return array<string, array{mode: string, path: ?string, match: list<string>}>
     */
    public function mergeRules(): array
    {
        return [...$this->merges, ...array_map(fn (\Nitro\Fusion\Merge $merge) => $merge->rule(), $this->mergeProps)];
    }

    public function __construct()
    {
        $this->errors = new MessageBag;
    }

    /**
     * Whether any of the properties is still to be loaded (any deferred property, when none are
     * given): @deferred's condition.
     *
     * @param  list<string>  $properties
     */
    public function deferring(array $properties = []): bool
    {
        return $properties === []
            ? $this->deferred !== []
            : array_intersect($properties, $this->deferred) !== [];
    }
}
