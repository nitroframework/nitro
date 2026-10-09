<?php

namespace Nitro\View\ServerRendering;

use JsonSerializable;
use Nitro\Compiler\ComponentMeta;
use Nitro\Component;
use Nitro\Support\Wire;

/**
 * What a JavaScript view renders from on the server: what the browser's handle gives it on the
 * first render, by the PHP's names. Values go in the wire format (Support\Wire) the browser's
 * state arrives in, so the render service reads them exactly as the browser does.
 */
final class ViewState implements JsonSerializable
{
    /**
     * @param  string  $id  the component's id: a view's own on the page (Solid keys its hydration by it)
     * @param  string  $engine  the view's engine, as nitro.views names it
     * @param  string  $view  the view's name: 'cart/badge'
     * @param  array<string, mixed>  $properties  name => value (wire format)
     * @param  array<string, mixed>  $computed  #[Computed] name => value (wire format)
     * @param  list<string>  $methods  the public methods the view may call
     * @param  list<string>  $server  the #[Server] methods
     * @param  array<string, list<string>>  $errors  the validation messages, by field
     */
    public function __construct(
        public readonly string $id,
        public readonly string $engine,
        public readonly string $view,
        public readonly array $properties,
        public readonly array $computed,
        public readonly array $methods,
        public readonly array $server,
        public readonly array $errors,
    ) {
    }

    /** The component as its view sees it now. */
    public static function of(Component $component, ComponentMeta $meta): self
    {
        $properties = [];

        foreach (array_keys($meta->properties) as $name) {
            $properties[$name] = Wire::encode($component->{$name} ?? null);
        }

        $computed = [];

        foreach ($meta->computed as $name) {
            $computed[$name] = Wire::encode($component->{$name});
        }

        /** @var array<string, list<string>> $errors */
        $errors = $component->nitro()->errors->toArray();

        return new self($component->getId(), $meta->engine, $meta->view, $properties, $computed, $meta->publicMethods(), $meta->serverMethods, $errors);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'engine' => $this->engine,
            'view' => $this->view,
            /** Objects even when empty: the service reads them by name. */
            'properties' => (object) $this->properties,
            'computed' => (object) $this->computed,
            'methods' => $this->methods,
            'server' => $this->server,
            'errors' => (object) $this->errors,
        ];
    }
}
