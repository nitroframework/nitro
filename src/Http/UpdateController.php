<?php

namespace Nitro\Fusion\Http;

use BackedEnum;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Nitro\Fusion\Component;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Fusion;
use Nitro\Fusion\Rendering\Snapshot;
use Nitro\Fusion\State;
use Nitro\Fusion\Support\Hooks;
use Nitro\Fusion\Support\Wire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * POST {endpoint}/update: the browser calls a #[Server] method.
 *
 *   {"snapshot": {...signed envelope...}, "partials": [...], "updates": {"prop": value}, "call": {"method": "save", "params": [...]}}
 *
 * partials: the page's partial reloads since its snapshot (signed envelopes of some properties),
 * laid over it in order.
 *
 * The request is refused unless:
 *   - the snapshot is one this application signed, unchanged (419 otherwise)
 *   - the page it came from still admits the visitor (PersistentMiddleware, 403)
 *   - every update names a public property that is not locked, with a value of its type (403)
 *   - the method is a #[Server] method, and each argument has the parameter's type (403)
 *
 * The answer is the new signed snapshot and the effects (dispatched events, a redirect, the
 * method's return value). Validation errors come back in the snapshot as $errors.
 */
final class UpdateController
{
    public function __construct(
        private readonly Container $container,
        private readonly PersistentMiddleware $persistent,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > (int) $this->fusion()->config('max_payload', 1024 * 1024)) {
            throw new HttpException(413, 'The component update is too large.');
        }

        try {
            $payload = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'Malformed component update.');
        }

        if (! is_array($payload)) {
            throw new HttpException(400, 'Malformed component update.');
        }

        $snapshot = Snapshot::fromEnvelope($payload['snapshot'] ?? null, $this->fusion()->stateKeys(), $this->fusion()->stateLifetime());

        if ($snapshot->isPartial()) {
            throw new HttpException(403, 'A partial reload is not a component\'s state.');
        }

        /** An obfuscated build's alias: the component it stands for. */
        $snapshot = $snapshot->withName($this->fusion()->componentName($snapshot->name));

        if (! $this->fusion()->has($snapshot->name)) {
            throw new HttpException(404, 'Unknown component.');
        }

        $this->persistent->check($snapshot->memo['route'] ?? [], $request);

        $meta = $this->fusion()->meta($snapshot->name);

        /** @var Component $component */
        $component = $this->container->make($meta->class);
        $snapshot = $snapshot->fromBrowser($this->fusion()->names(), array_keys($meta->properties));
        $snapshot->restore($component, $meta);
        $this->applyPartials($component, $meta, $snapshot, $payload['partials'] ?? []);
        Hooks::call($component, 'boot');
        Hooks::call($component, 'hydrate');

        $changed = $this->applyUpdates($component, $meta, $payload['updates'] ?? []);

        $returned = null;
        $call = $payload['call'] ?? null;

        /** What the browser changed, against its #[Validate] rules: a failure keeps the method from running. */
        if ($this->validateUpdates($component, $meta, $changed) && $call !== null) {
            $returned = $this->call($component, $meta, $call);
        }

        $memo = array_intersect_key($snapshot->memo, ['route' => true]);
        $next = Snapshot::capture($component, $meta, $memo);
        $context = $component->fusion();

        return new JsonResponse([
            'snapshot' => json_decode($next->toJson($this->fusion()->stateKey(), $this->fusion()->browserName($meta->name), $this->fusion()->names()), true),
            /** Fusion::share()'s data as it is now (a #[Server] call may have changed it: a flash message). */
            'shared' => $this->fusion()->sharedForBrowser(),
            'once' => (object) $this->fusion()->onceForBrowser(),
            /** Fusion::flash()'s data: from this call, or waiting since a redirect; a call that redirects leaves it for the page it goes to. */
            'flash' => $context->redirect === null ? $this->fusion()->flashForBrowser() : null,
            'effects' => array_filter([
                'dispatches' => array_map(fn ($d) => array_filter([
                    'event' => $d['event'],
                    'params' => Wire::encode($d['params']),
                    'to' => isset($d['to']) ? $this->fusion()->browserName($d['to']) : null,
                    'self' => ($d['self'] ?? false) ?: null,
                ], fn ($v) => $v !== null), $context->dispatches),
                'redirect' => $context->redirect,
                /** Reading $fusion.<name> by the browser's names (an obfuscated build). */
                'js' => array_map(fn (string $code) => $this->fusion()->names()?->script($code, array_keys($meta->properties), [...$meta->browserMethods, ...$meta->serverMethods]) ?? $code, $context->js),
                /** $this->reload(): an object, so that "all of them" ([]) is not filtered out. */
                'reload' => $context->reload === null ? null : ['only' => array_map(fn (string $property) => $this->fusion()->names()?->property($property) ?? $property, $context->reload)],
                'returns' => $call !== null ? Wire::encode($returned) : null,
            ], fn ($v) => $v !== null && $v !== []),
        ]);
    }

    /**
     * The partial reloads the page had since its snapshot ($reload, deferred properties): signed
     * by the server for this component, laid over its snapshot in the order they came.
     */
    private function applyPartials(Component $component, ComponentMeta $meta, Snapshot $snapshot, mixed $partials): void
    {
        if (! is_array($partials) || ! array_is_list($partials) || count($partials) > 50) {
            throw new HttpException(400, 'Malformed component update.');
        }

        foreach ($partials as $envelope) {
            $partial = Snapshot::fromEnvelope($envelope, $this->fusion()->stateKeys(), $this->fusion()->stateLifetime());

            if (! $partial->isPartial() || $partial->id !== $snapshot->id || $this->fusion()->componentName($partial->name) !== $snapshot->name) {
                throw new HttpException(403, 'This partial reload belongs to another component.');
            }

            $partial->fromBrowser($this->fusion()->names(), array_keys($meta->properties))->restore($component, $meta);
        }
    }

    /**
     * The browser's changes, each checked against its property's type and lock.
     *
     * @return list<string> the properties changed
     */
    private function applyUpdates(Component $component, ComponentMeta $meta, mixed $updates): array
    {
        if (! is_array($updates)) {
            throw new HttpException(400, 'Malformed component update.');
        }

        $changed = [];

        foreach ($updates as $name => $encoded) {
            /** By the browser's name for it (an obfuscated build). */
            $name = is_string($name) ? ($this->fusion()->names()?->propertyName($name, array_keys($meta->properties)) ?? $name) : $name;
            $property = is_string($name) ? $meta->property($name) : null;

            try {
                $value = Wire::decode($encoded);
            } catch (InvalidArgumentException) {
                throw new HttpException(400, 'Malformed component update.');
            }

            if ($property === null || $property->locked || ! $property->accepts($value)) {
                throw new HttpException(403, 'This property cannot be changed from the browser.');
            }

            $component->{$name} = $property->cast($value);
            $changed[] = $name;
        }

        return $changed;
    }

    /**
     * #[Validate]: the properties the browser changed, against their rules. Whether they pass;
     * when one fails, its messages are the component's $errors (the method does not run).
     *
     * @param  list<string>  $changed
     */
    private function validateUpdates(Component $component, ComponentMeta $meta, array $changed): bool
    {
        [$rules, $attributes] = $meta->rulesFor($changed);

        if ($rules === []) {
            return true;
        }

        $validator = Validator::make(State::of($component), $rules, [], $attributes);

        if ($validator->fails()) {
            $component->fusion()->errors = $validator->errors();

            return false;
        }

        return true;
    }

    private function call(Component $component, ComponentMeta $meta, mixed $call): mixed
    {
        $method = is_array($call) ? ($call['method'] ?? null) : null;
        $params = is_array($call) ? ($call['params'] ?? []) : null;

        /** An obfuscated build's alias: the #[Server] method it stands for. */
        if (is_string($method)) {
            $method = $this->fusion()->methodName($method, $meta->serverMethods);
        }

        if (! is_string($method) || ! $meta->isServerMethod($method) || ! is_array($params) || ! array_is_list($params)) {
            throw new HttpException(403, 'This method cannot be called from the browser.');
        }

        $arguments = $this->arguments($meta->serverParameters[$method], array_map(Wire::decode(...), $params));
        $context = $component->fusion();
        $context->errors = new \Illuminate\Support\MessageBag;

        try {
            return $component->{$method}(...$arguments);
        } catch (ValidationException $e) {
            $context->errors = $e->validator->errors();

            return null;
        }
    }

    /**
     * Values from the browser fill the parameters, in order: scalars and arrays checked against
     * their type, a model from its key, a backed enum from its value. Other classes come from the
     * container and take nothing from the browser.
     *
     * @param  list<array{name: string, type: ?string, kind: string, nullable: bool, optional: bool, variadic: bool}>  $parameters
     */
    private function arguments(array $parameters, array $values): array
    {
        $arguments = [];

        foreach ($parameters as $parameter) {
            /** Not from the browser: the container's. */
            if ($parameter['kind'] === 'service') {
                $arguments[] = $this->container->make($parameter['type']);

                continue;
            }

            if ($parameter['variadic']) {
                foreach ($values as $value) {
                    $arguments[] = $this->checked($value, $parameter);
                }

                $values = [];

                break;
            }

            if ($values === []) {
                if (! $parameter['optional']) {
                    throw new HttpException(403, 'Missing argument.');
                }

                break;
            }

            $value = array_shift($values);
            $arguments[] = match ($parameter['kind']) {
                'model' => $this->model($value, $parameter),
                'enum' => $this->enum($value, $parameter),
                default => $this->checked($value, $parameter),
            };
        }

        if ($values !== []) {
            throw new HttpException(403, 'Too many arguments.');
        }

        return $arguments;
    }

    /**
     * A model parameter, from the key the browser sent, as route model binding resolves one
     * (resolveRouteBinding(): the model's route key, or its own lookup). The row itself, as the
     * browser has it, gives its key too. Not found: 404. Resolving is not authorizing: the method
     * still checks that the visitor may act on it.
     *
     * @param  array{name: string, type: class-string<Model>, nullable: bool}  $parameter
     */
    private function model(mixed $value, array $parameter): ?Model
    {
        if ($value === null && $parameter['nullable']) {
            return null;
        }

        $model = new $parameter['type'];
        $key = is_array($value) ? ($value[$model->getRouteKeyName()] ?? null) : $value;

        if (! is_int($key) && ! is_string($key)) {
            throw new HttpException(403, "Invalid argument \${$parameter['name']}.");
        }

        return $model->resolveRouteBinding($key) ?? throw (new ModelNotFoundException)->setModel($parameter['type'], [$key]);
    }

    /**
     * A backed enum parameter, from its value. Not one of its cases: 403.
     *
     * @param  array{name: string, type: class-string<BackedEnum>, nullable: bool}  $parameter
     */
    private function enum(mixed $value, array $parameter): ?BackedEnum
    {
        if ($value === null && $parameter['nullable']) {
            return null;
        }

        $case = is_int($value) || is_string($value) ? rescue(fn () => $parameter['type']::tryFrom($value), null, false) : null;

        return $case ?? throw new HttpException(403, "Invalid argument \${$parameter['name']}.");
    }

    private function checked(mixed $value, array $parameter): mixed
    {
        $fits = match ($parameter['type']) {
            null, 'mixed' => is_scalar($value) || is_array($value) || $value === null,
            'int' => is_int($value),
            'float' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            default => false,
        };

        if (! $fits && ! ($value === null && $parameter['nullable'])) {
            throw new HttpException(403, "Invalid argument \${$parameter['name']}.");
        }

        return $value;
    }

    /**
     * This request's Fusion. The router keeps a controller between requests (Octane, Nitro's
     * workers): Fusion, bound per request, is not kept with it.
     */
    private function fusion(): Fusion
    {
        return app(Fusion::class);
    }
}
