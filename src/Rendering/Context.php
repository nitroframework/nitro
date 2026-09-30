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

    public function __construct()
    {
        $this->errors = new MessageBag;
    }
}
