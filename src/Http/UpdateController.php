<?php

namespace Nitro\Fusion\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Nitro\Fusion\Component;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Fusion;
use Nitro\Fusion\Rendering\Snapshot;
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

        $snapshot = Snapshot::fromEnvelope($payload['snapshot'] ?? null, $this->fusion()->key());

        if ($snapshot->isPartial()) {
            throw new HttpException(403, 'A partial reload is not a component\'s state.');
        }

        if (! $this->fusion()->has($snapshot->name)) {
            throw new HttpException(404, 'Unknown component.');
        }

        $this->persistent->check($snapshot->memo['route'] ?? [], $request);

        $meta = $this->fusion()->meta($snapshot->name);

        /** @var Component $component */
        $component = $this->container->make($meta->class);
        $snapshot->restore($component, $meta);
        $this->applyPartials($component, $meta, $snapshot, $payload['partials'] ?? []);
        Hooks::call($component, 'boot');
        Hooks::call($component, 'hydrate');

        $this->applyUpdates($component, $meta, $payload['updates'] ?? []);

        $returned = null;
        $call = $payload['call'] ?? null;

        if ($call !== null) {
            $returned = $this->call($component, $meta, $call);
        }

        $memo = array_intersect_key($snapshot->memo, ['route' => true]);
        $next = Snapshot::capture($component, $meta, $memo);
        $context = $component->fusion();

        return new JsonResponse([
            'snapshot' => json_decode($next->toJson($this->fusion()->key()), true),
            /** Fusion::share()'s data as it is now (a #[Server] call may have changed it: a flash message). */
            'shared' => $this->fusion()->sharedForBrowser(),
            'once' => (object) $this->fusion()->onceForBrowser(),
            /** Fusion::flash()'s data: from this call, or waiting since a redirect; a call that redirects leaves it for the page it goes to. */
            'flash' => $context->redirect === null ? $this->fusion()->flashForBrowser() : null,
            'effects' => array_filter([
                'dispatches' => array_map(fn ($d) => array_filter([
                    'event' => $d['event'],
                    'params' => Wire::encode($d['params']),
                    'to' => $d['to'] ?? null,
                    'self' => ($d['self'] ?? false) ?: null,
                ], fn ($v) => $v !== null), $context->dispatches),
                'redirect' => $context->redirect,
                'js' => $context->js,
                /** $this->reload(): an object, so that "all of them" ([]) is not filtered out. */
                'reload' => $context->reload === null ? null : ['only' => $context->reload],
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
            $partial = Snapshot::fromEnvelope($envelope, $this->fusion()->key());

            if (! $partial->isPartial() || $partial->id !== $snapshot->id || $partial->name !== $snapshot->name) {
                throw new HttpException(403, 'This partial reload belongs to another component.');
            }

            $partial->restore($component, $meta);
        }
    }

    private function applyUpdates(Component $component, ComponentMeta $meta, mixed $updates): void
    {
        if (! is_array($updates)) {
            throw new HttpException(400, 'Malformed component update.');
        }

        foreach ($updates as $name => $encoded) {
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
        }
    }

    private function call(Component $component, ComponentMeta $meta, mixed $call): mixed
    {
        $method = is_array($call) ? ($call['method'] ?? null) : null;
        $params = is_array($call) ? ($call['params'] ?? []) : null;

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
     * Values from the browser fill the scalar and array parameters, in order, each checked
     * against its type; parameters typed with a class come from the container.
     *
     * @param  list<array{name: string, type: ?string, nullable: bool, optional: bool, variadic: bool}>  $parameters
     */
    private function arguments(array $parameters, array $values): array
    {
        $arguments = [];

        foreach ($parameters as $parameter) {
            $type = $parameter['type'];

            if ($type !== null && ! in_array($type, ['int', 'float', 'string', 'bool', 'array', 'mixed'], true)) {
                $arguments[] = $this->container->make($type);

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

            $arguments[] = $this->checked(array_shift($values), $parameter);
        }

        if ($values !== []) {
            throw new HttpException(403, 'Too many arguments.');
        }

        return $arguments;
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
