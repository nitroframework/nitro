<?php

namespace Nitro\Fusion\Rendering;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Compiler\PropertyMeta;
use Nitro\Fusion\Component;
use Nitro\Fusion\Merge;
use Nitro\Fusion\State;
use Nitro\Fusion\Support\Hooks;
use Nitro\Fusion\Support\Wire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A component's state as the browser holds it, signed by the server.
 *
 * The browser gets {"state": "<json>", "checksum": "<hmac>"}: it reads the state to render and
 * sends the envelope back unchanged with each #[Server] call; its own changes travel separately
 * as updates (checked against each property's type and lock). The checksum covers the exact
 * bytes of the state, so nothing in it (the component's name, a locked property, the models it
 * refers to) can be changed without the server noticing.
 *
 * Models are sent as their attributes (what the view shows) and remembered by class and key:
 * the server always reads them again from the database, never from what the browser returns.
 */
final class Snapshot
{
    /**
     * @param  array<string, mixed>  $data  property => value (PHP values; models as attribute arrays)
     * @param  array<string, mixed>  $memo  models, errors, the page route (for persistent middleware)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $id,
        public readonly array $data,
        public readonly array $memo,
    ) {
    }

    public static function capture(Component $component, ComponentMeta $meta, array $memo = []): self
    {
        Hooks::dehydrate($component);
        $data = [];
        $models = [];

        foreach (State::of($component) as $name => $value) {
            $property = $meta->property($name);

            if ($property === null) {
                continue;
            }

            if ($value instanceof Model) {
                $models[$name] = self::modelReference($value, $meta->name, $name);
                $data[$name] = $value->toArray();
            } elseif ($value instanceof EloquentCollection) {
                $models[$name] = [
                    'collection' => true,
                    'class' => $value->isEmpty() ? null : get_class($value->first()),
                    'keys' => $value->map(fn (Model $m) => self::modelReference($m, $meta->name, $name)['key'])->all(),
                    'connection' => $value->first()?->getConnectionName(),
                ];
                $data[$name] = $value->toArray();
            } else {
                $data[$name] = $value;
            }
        }

        $context = $component->fusion();

        return new self($context->name, $context->id, $data, array_filter([
            ...$memo,
            'models' => $models,
            'errors' => $context->errors->toArray(),
            'deferred' => $context->deferred,
        ], fn ($v) => $v !== [] && $v !== null));
    }

    private static function modelReference(Model $model, string $component, string $property): array
    {
        if (! $model->exists) {
            throw new LogicException("Fusion component [{$component}]: \${$property} holds an unsaved model; only saved models can be part of the state.");
        }

        return ['class' => get_class($model), 'key' => $model->getKey(), 'connection' => $model->getConnectionName()];
    }

    /**
     * The named properties only, for a partial reload: the browser applies them, and sends the
     * envelope with its next #[Server] call, which lays it over the component's snapshot. Marked
     * partial: it never stands for a component's whole state. A merge prop goes with its rule
     * (Merge): the value is merged into the one the component has, not set.
     *
     * @param  list<string>  $properties
     * @param  array<string, array{mode: string, path: ?string, match: list<string>}>  $merges
     */
    public function only(array $properties, array $merges = []): self
    {
        $names = array_flip($properties);

        return new self($this->name, $this->id, array_intersect_key($this->data, $names), array_filter([
            'models' => array_intersect_key($this->memo['models'] ?? [], $names),
            'merge' => array_intersect_key($merges, $names),
            'partial' => true,
        ]));
    }

    /** Whether it holds some properties only (only()). */
    public function isPartial(): bool
    {
        return ($this->memo['partial'] ?? false) === true;
    }

    /** The envelope sent to the browser. */
    public function toJson(string $key): string
    {
        $state = Wire::json(['name' => $this->name, 'id' => $this->id, 'data' => $this->data, 'memo' => $this->memo]);

        return json_encode(['state' => $state, 'checksum' => self::sign($state, $key)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The envelope the browser sent back, verified.
     *
     * @throws HttpException 419 when it was not signed by this application or was changed
     */
    public static function fromEnvelope(mixed $envelope, string $key): self
    {
        if (! is_array($envelope) || ! is_string($envelope['state'] ?? null) || ! is_string($envelope['checksum'] ?? null)
            || ! hash_equals(self::sign($envelope['state'], $key), $envelope['checksum'])) {
            throw new HttpException(419, 'The component state was changed or has expired: reload the page.');
        }

        try {
            $state = Wire::fromJson($envelope['state']);
        } catch (JsonException|InvalidArgumentException) {
            throw new HttpException(419, 'The component state is corrupt.');
        }

        return new self($state['name'], $state['id'], $state['data'] ?? [], $state['memo'] ?? []);
    }

    /**
     * Put the state into a fresh instance: values as they were, models read again.
     */
    public function restore(Component $component, ComponentMeta $meta): void
    {
        $models = $this->memo['models'] ?? [];
        /** A partial reload's merge props: merged into the value the component has (Merge). */
        $merges = $this->isPartial() ? (array) ($this->memo['merge'] ?? []) : [];

        foreach ($meta->properties as $name => $property) {
            if (! array_key_exists($name, $this->data)) {
                continue;
            }

            $component->{$name} = match (true) {
                in_array($property->kind, [PropertyMeta::MODEL, PropertyMeta::COLLECTION], true) => isset($models[$name]) ? self::loadModels($models[$name]) : null,
                isset($merges[$name]) => Merge::apply($component->{$name}, $this->data[$name], $merges[$name]),
                default => $this->data[$name],
            };
        }

        $context = $component->fusion();
        $context->id = $this->id;
        $context->name = $this->name;
        $context->errors->merge($this->memo['errors'] ?? []);
    }

    /** @return Model|EloquentCollection<int, Model> */
    private static function loadModels(array $reference): Model|EloquentCollection
    {
        if ($reference['collection'] ?? false) {
            if ($reference['class'] === null) {
                return new EloquentCollection;
            }

            $models = $reference['class']::on($reference['connection'])->whereKey($reference['keys'])->get()->keyBy(fn (Model $m) => $m->getKey());

            return new EloquentCollection(array_values(array_filter(array_map(fn ($key) => $models[$key] ?? null, $reference['keys']))));
        }

        return $reference['class']::on($reference['connection'])->findOrFail($reference['key']);
    }

    private static function sign(string $state, string $key): string
    {
        return hash_hmac('sha256', $state, $key);
    }
}
