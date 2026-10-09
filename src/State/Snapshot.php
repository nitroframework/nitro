<?php

namespace Nitro\State;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Nitro\Compiler\ComponentMeta;
use Nitro\Compiler\PropertyMeta;
use Closure;
use Nitro\Compiler\Obfuscator;
use Nitro\Compiler\StateProperties;
use Nitro\Component;
use Nitro\Merge;
use Nitro\Support\Signer;
use Nitro\Support\Wire;
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
 * Models are sent as their attributes (what the view shows, Wire marks them as models) and remembered by class and key:
 * the server always reads them again from the database, never from what the browser returns.
 */
final class Snapshot
{
    /** What a snapshot's signature is for (Signer): it cannot pass for another signed thing. */
    private const PURPOSE = 'state';

    /**
     * @param  array<string, mixed>  $data  property => value (PHP values: models as they are when captured, their attributes when read back)
     * @param  array<string, mixed>  $memo  models, errors, the page route (for persistent middleware)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $id,
        public readonly array $data,
        public readonly array $memo,
    ) {
    }

    /** The component's state as it is: the caller runs its dehydrate hooks first (Features::dehydrate()). */
    public static function capture(Component $component, ComponentMeta $meta, array $memo = []): self
    {
        $data = [];
        $models = [];

        foreach (StateProperties::values($component) as $name => $value) {
            $property = $meta->property($name);

            if ($property === null) {
                continue;
            }

            if ($value instanceof Model) {
                $models[$name] = self::modelReference($value, $meta->name, $name);
                $data[$name] = $value;
            } elseif ($value instanceof EloquentCollection) {
                $references = $value->map(fn (Model $model) => self::modelReference($model, $meta->name, $name))->values()->all();
                $classes = array_unique(array_column($references, 'class'));

                /** One class (the usual): its keys. Several (a polymorphic relation's): each model's class and key, in order. */
                $models[$name] = count($classes) > 1
                    ? ['collection' => true, 'items' => array_map(fn (array $reference) => [$reference['class'], $reference['key'], $reference['connection']], $references)]
                    : array_filter([
                        'collection' => true,
                        'class' => $value->isEmpty() ? null : get_class($value->first()),
                        'keys' => array_column($references, 'key'),
                        'connection' => $value->first()?->getConnectionName(),
                        /** The relations every model has loaded: loaded again with them. */
                        'relations' => $value->getQueueableRelations(),
                    ], fn ($value, $key) => $key !== 'relations' || $value !== [], ARRAY_FILTER_USE_BOTH);
                $data[$name] = $value;
            } else {
                $data[$name] = $value;
            }
        }

        $context = $component->nitro();

        return new self($context->name, $context->id, $data, array_filter([
            ...$memo,
            'models' => $models,
            'errors' => $context->errors->toArray(),
            'deferred' => $context->deferred,
            'scroll' => $context->scroll,
        ], fn ($value) => $value !== [] && $value !== null));
    }

    private static function modelReference(Model $model, string $component, string $property): array
    {
        if (! $model->exists) {
            throw new LogicException("Nitro component [{$component}]: \${$property} holds an unsaved model; only saved models can be part of the state.");
        }

        $relations = $model->getQueueableRelations();

        return ['class' => get_class($model), 'key' => $model->getKey(), 'connection' => $model->getConnectionName(), ...($relations === [] ? [] : ['relations' => $relations])];
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
            'scroll' => array_intersect_key($this->memo['scroll'] ?? [], $names),
            /** The page it was rendered for: it can only be laid over a snapshot of that page (UpdateController). */
            'route' => $this->memo['route'] ?? null,
            'partial' => true,
        ]));
    }

    /**
     * The snapshot the browser sent back, by the component's own property names (an obfuscated
     * build's aliases resolved).
     *
     * @param  list<string>  $properties  the component's
     */
    public function fromBrowser(?Obfuscator $names, array $properties): self
    {
        if ($names === null) {
            return $this;
        }

        [$data, $memo] = $this->renamed(
            fn (string $name) => $names->propertyName($name, $properties) ?? $name,
            fn (string $path) => $names->propertyPath($path, $properties),
        );

        return new self($this->name, $this->id, $data, $memo);
    }

    /**
     * Its data and memo with the properties renamed: keys of the data, models, merge rules and
     * scroll positions, error fields (paths), deferred properties.
     *
     * @param  Closure(string): string  $property
     * @param  Closure(string): string  $path
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function renamed(Closure $property, Closure $path): array
    {
        $keys = fn (array $values, Closure $rename) => array_combine(array_map(fn ($key) => $rename((string) $key), array_keys($values)), $values);
        $memo = $this->memo;

        foreach (['models', 'merge', 'scroll'] as $entry) {
            if (isset($memo[$entry]) && is_array($memo[$entry])) {
                $memo[$entry] = $keys($memo[$entry], $property);
            }
        }

        if (isset($memo['errors']) && is_array($memo['errors'])) {
            $memo['errors'] = $keys($memo['errors'], $path);
        }

        if (isset($memo['deferred']) && is_array($memo['deferred'])) {
            $memo['deferred'] = array_map(fn ($name) => $property((string) $name), $memo['deferred']);
        }

        return [$keys($this->data, $property), $memo];
    }

    /** The same snapshot, for the component by this name (a browser's alias resolved). */
    public function withName(string $name): self
    {
        return new self($name, $this->id, $this->data, $this->memo);
    }

    /** Whether it holds some properties only (only()). */
    public function isPartial(): bool
    {
        return ($this->memo['partial'] ?? false) === true;
    }

    /**
     * The envelope sent to the browser, naming the component as the browser knows it ($name), and
     * with an obfuscated build's names ($names), its properties by theirs: in its state, and where
     * its memo names them (models, errors, deferred properties, merge rules).
     */
    public function toJson(Signer $signer, ?string $name = null, ?Obfuscator $names = null): string
    {
        [$data, $memo] = $names === null ? [$this->data, $this->memo] : $this->renamed($names->property(...), $names->path(...));
        /** issued: when it was signed, for nitro.state.lifetime. */
        $state = Wire::json(['name' => $name ?? $this->name, 'id' => $this->id, 'data' => $data, 'memo' => $memo, 'issued' => time()]);

        return json_encode(['state' => $state, 'checksum' => $signer->sign(self::PURPOSE, $state)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The envelope the browser sent back, verified: signed for this session (Signer), and, with a
     * $lifetime (seconds), signed no longer ago than that.
     *
     * @throws HttpException 419 when it was changed, belongs to another session, or has expired
     */
    public static function fromEnvelope(mixed $envelope, Signer $signer, ?int $lifetime = null): self
    {
        $signed = is_array($envelope) && is_string($envelope['state'] ?? null) && is_string($envelope['checksum'] ?? null)
            && $signer->verifies(self::PURPOSE, $envelope['state'], $envelope['checksum']);

        if (! $signed) {
            throw new HttpException(419, 'The component state is not valid here (changed, or from another session): reload the page.');
        }

        try {
            $state = Wire::fromJson($envelope['state']);
        } catch (JsonException|InvalidArgumentException) {
            throw new HttpException(419, 'The component state is corrupt.');
        }

        if ($lifetime !== null && (! is_int($state['issued'] ?? null) || $state['issued'] + $lifetime < time())) {
            throw new HttpException(419, 'The component state has expired: reload the page.');
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
                in_array($property->kind, [PropertyMeta::MODEL, PropertyMeta::COLLECTION], true) => isset($models[$name]) ? self::loadModels($models[$name], $property->nullable) : null,
                isset($merges[$name]) => Merge::apply($component->{$name}, $this->data[$name], $merges[$name]),
                /** An enum: the case its value names (the state carries the value). */
                $property->kind === PropertyMeta::ENUM => $property->cast($this->data[$name]),
                default => $this->data[$name],
            };
        }

        $context = $component->nitro();
        $context->id = $this->id;
        $context->name = $this->name;
        $context->errors->merge($this->memo['errors'] ?? []);
        $context->scroll = [...$context->scroll, ...(array) ($this->memo['scroll'] ?? [])];
    }

    /**
     * A model property read again from the database. A collection keeps its order and leaves out
     * the rows gone since. A single model whose row is gone: null for a nullable property (the
     * component can tell, and the view show it), a 404 otherwise.
     *
     * @param  array{collection?: bool, class?: class-string<Model>|null, key?: int|string, keys?: list<int|string>, items?: list<array{0: class-string<Model>, 1: int|string, 2: ?string}>, connection?: ?string, relations?: list<string>}  $reference
     * @return Model|EloquentCollection<int, Model>|null
     */
    private static function loadModels(array $reference, bool $nullable = false): Model|EloquentCollection|null
    {
        if ($reference['collection'] ?? false) {
            /** Several classes: each class's models in one query, then in the order they were in. */
            if (isset($reference['items'])) {
                $found = [];

                foreach (collect($reference['items'])->groupBy(fn (array $item) => $item[0].'|'.$item[2]) as $group) {
                    [$class, , $connection] = $group->first();

                    foreach ($class::on($connection)->whereKey($group->pluck(1)->all())->get() as $model) {
                        $found[$class.'|'.$model->getKey()] = $model;
                    }
                }

                return new EloquentCollection(array_values(array_filter(array_map(fn (array $item) => $found[$item[0].'|'.$item[1]] ?? null, $reference['items']))));
            }

            if ($reference['class'] === null) {
                return new EloquentCollection;
            }

            $models = $reference['class']::on($reference['connection'])->with($reference['relations'] ?? [])->whereKey($reference['keys'])->get()->keyBy(fn (Model $model) => $model->getKey());

            return new EloquentCollection(array_values(array_filter(array_map(fn ($key) => $models[$key] ?? null, $reference['keys']))));
        }

        $query = $reference['class']::on($reference['connection'])->with($reference['relations'] ?? []);

        return $nullable ? $query->find($reference['key']) : $query->findOrFail($reference['key']);
    }
}
