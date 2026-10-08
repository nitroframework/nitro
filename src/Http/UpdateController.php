<?php

namespace Nitro\Fusion\Http;

use BackedEnum;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Nitro\Fusion\Build\BrowserNames;
use Nitro\Fusion\Build\ServerChildren;
use Nitro\Fusion\Compiler\ComponentMeta;
use Nitro\Fusion\Compiler\Components;
use Nitro\Fusion\Compiler\PropertyMeta;
use Nitro\Fusion\Component;
use Nitro\Fusion\Http\IncomingState;
use Nitro\Fusion\Lifecycle\Features;
use Nitro\Fusion\Rendering\ComponentRenderer;
use Nitro\Fusion\State\MethodCache;
use Nitro\Fusion\State\SharedData;
use Nitro\Fusion\State\Snapshot;
use Nitro\Fusion\State\Snapshots;
use Nitro\Fusion\State\Stores;
use Nitro\Fusion\Store;
use Nitro\Fusion\Support\ComponentHooks;
use Nitro\Fusion\Support\Config;
use Nitro\Fusion\Support\Naming;
use Nitro\Fusion\Support\PropertyHooks;
use Nitro\Fusion\Support\Types;
use Nitro\Fusion\Support\Wire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * POST {endpoint}/update: the browser calls a #[Server] method.
 *
 *   {"snapshot": {...signed envelope...}, "partials": [...], "updates": {"prop": value}, "call": {"method": "save", "params": [...]}}
 *
 * partials: the page's partial reloads since its snapshot (signed envelopes of some properties),
 * laid over it in order. children: true asks for the signed state of the children its view places
 * that need one (ComponentRenderer::childrenOf()), for children the browser made itself.
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
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > (int) app(Config::class)->get('max_payload')) {
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

        /**
         * A store the browser made from its defaults, with no state from the server yet (no
         * component the server rendered used it): made here, with its mount(), as for a page.
         */
        if (($payload['snapshot'] ?? null) === null && is_string($payload['store'] ?? null)) {
            $name = $this->names()->componentName($payload['store']);

            if (! Naming::isStore($name) || ! $this->components()->has($name)) {
                throw new HttpException(404, 'Unknown store.');
            }

            $meta = $this->components()->meta($name);
            $component = app(Stores::class)->store($meta->class);
            $memo = [];
        } else {
            $snapshot = $this->incoming()->snapshot($payload['snapshot'] ?? null, $request);

            $meta = $this->components()->meta($snapshot->name);
            $memo = array_intersect_key($snapshot->memo, ['route' => true]);

            /** @var Component $component */
            $component = $this->container->make($meta->class);
            $snapshot->restore($component, $meta);
            $this->applyPartials($component, $meta, $snapshot, $payload['partials'] ?? []);

            /** A store's own call: the store this request's components would use. */
            if ($component instanceof Store) {
                app(Stores::class)->adopt($component, $memo);
            }
        }

        /** The stores it uses, as the browser sent them (each checked as a component's state is), before its hooks run. */
        $storesValid = $this->restoreStores($component, $meta, $payload['stores'] ?? [], $request);

        if (! $component instanceof Store || isset($snapshot)) {
            $this->features()->boot($component);
        }

        $this->features()->hydrate($component);

        $changed = $this->applyUpdates($component, $meta, $payload['updates'] ?? []);

        $returned = null;
        $call = $payload['call'] ?? null;

        /** What the browser changed, as the features check it (#[Validate]'s rules): a failure keeps the method from running. */
        $valid = $this->features()->updated($component, $changed) && $storesValid;

        if ($valid && $call !== null) {
            $returned = $this->call($component, $meta, $call);
        }

        /** Asked for (a child made in the browser), or a view placing children with mount(): its new state may place one, which only the server can create. */
        $asked = ($payload['children'] ?? false) === true && app(ServerChildren::class)->placedBy($meta);
        /** Not from a state that failed its rules: the view would render what they are there to keep out. */
        $children = $valid && ($asked || app(ServerChildren::class)->placedBy($meta, mountOnly: true)) ? app(ComponentRenderer::class)->childrenOf($component, $meta, $memo) : null;
        $this->features()->dehydrate($component);
        $next = Snapshot::capture($component, $meta, $memo);
        $context = $component->fusion();

        return new JsonResponse([
            'snapshot' => $this->snapshots()->envelope($next, $meta->name),
            'children' => $children === null ? null : (object) $children,
            /** The stores it uses, as its method left them (a store's own call: its snapshot is the state). */
            'stores' => $meta->stores === [] ? null : (object) array_intersect_key(app(Stores::class)->snapshots(app(ComponentRenderer::class)->pageMemo()), array_flip(array_map(fn (string $class) => $this->names()->component($this->components()->meta($class)->name), $meta->stores))),
            /** Shared data as it is now (a #[Server] call may have changed it); flash data unless the call redirects (the page it goes to gets it). */
            ...app(SharedData::class)->forBrowser(flash: $context->redirect === null),
            'effects' => array_filter([
                'dispatches' => array_map(fn ($dispatch) => array_filter([
                    'event' => $dispatch['event'],
                    'params' => Wire::encode($dispatch['params']),
                    'to' => isset($dispatch['to']) ? $this->names()->component($dispatch['to']) : null,
                    'self' => ($dispatch['self'] ?? false) ?: null,
                ], fn ($value) => $value !== null), $context->dispatches),
                'redirect' => $context->redirect,
                /** Reading $fusion.<name> by the browser's names (an obfuscated build). */
                'js' => array_map(fn (string $code) => $this->names()->script($code, array_keys($meta->properties), [...$meta->browserMethods, ...$meta->serverMethods]), $context->js),
                /** $this->reload(): an object, so that "all of them" ([]) is not filtered out. */
                'reload' => $context->reload === null ? null : ['only' => array_map(fn (string $property) => $this->names()->property($property), $context->reload)],
                'returns' => $call !== null ? Wire::encode($returned) : null,
            ], fn ($value) => $value !== null && $value !== []),
        ]);
    }

    /**
     * The stores a component uses, from what the browser sent for each (its signed state and what
     * changed since), checked as a component's state is: the signature, the store it names, the
     * page's middleware, the types and #[Locked] of what changed. Given to the component, and to
     * the request (another component or the answer reads the same one). A store the browser sent
     * nothing for is made here. False when what changed fails its #[Validate] rules.
     */
    private function restoreStores(Component $component, ComponentMeta $meta, mixed $sent, Request $request): bool
    {
        if (! is_array($sent)) {
            throw new HttpException(400, 'Malformed component update.');
        }

        $valid = true;

        foreach ($meta->stores as $property => $class) {
            $storeMeta = $this->components()->meta($class);
            $entry = $sent[$this->names()->component($storeMeta->name)] ?? null;

            if ($entry !== null && ! is_array($entry)) {
                throw new HttpException(400, 'Malformed component update.');
            }

            if (($entry['snapshot'] ?? null) !== null) {
                $snapshot = $this->incoming()->snapshot($entry['snapshot'], $request, $storeMeta->name);

                /** @var Store $store */
                $store = $this->container->make($storeMeta->class);
                $snapshot->restore($store, $storeMeta);
                app(Stores::class)->adopt($store, array_intersect_key($snapshot->memo, ['route' => true]));
                $this->features()->boot($store);
                $this->features()->hydrate($store);
            } else {
                $store = app(Stores::class)->store($storeMeta->class);
            }

            $changed = $this->applyUpdates($store, $storeMeta, $entry['updates'] ?? []);
            $valid = $this->features()->updated($store, $changed) && $valid;
            $component->{$property} = $store;
        }

        return $valid;
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
            $this->incoming()->partial($envelope, $snapshot)->restore($component, $meta);
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
            $name = is_string($name) ? $this->names()->propertyName($name, array_keys($meta->properties)) : $name;
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


    private function call(Component $component, ComponentMeta $meta, mixed $call): mixed
    {
        $method = is_array($call) ? ($call['method'] ?? null) : null;
        $params = is_array($call) ? ($call['params'] ?? []) : null;

        /** An obfuscated build's alias: the #[Server] method it stands for. */
        if (is_string($method)) {
            $method = $this->names()->methodName($method, $meta->serverMethods);
        }

        if (! is_string($method) || ! $meta->isServerMethod($method) || ! is_array($params) || ! array_is_list($params)) {
            throw new HttpException(403, 'This method cannot be called from the browser.');
        }

        try {
            $params = array_map(Wire::decode(...), $params);
        } catch (InvalidArgumentException) {
            throw new HttpException(400, 'Malformed component update.');
        }

        /** A #[Server] property hook is offered its value and key (or path and value): it takes those it declares, as in the browser. */
        if (PropertyHooks::parse($method, array_keys($meta->properties)) !== null) {
            $params = array_slice($params, 0, count($meta->serverParameters[$method]));

            /** updated(): the path names its property by the browser's name (an obfuscated build). */
            if (PropertyHooks::takesPath($method) && is_string($params[0] ?? null)) {
                $params[0] = $this->names()->propertyPath($params[0], array_keys($meta->properties));
            }
        }

        $arguments = $this->arguments($meta->serverParameters[$method], $params);
        $context = $component->fusion();
        $context->errors = new \Illuminate\Support\MessageBag;

        $features = $this->features();
        $features->calling($component, $method, $arguments);

        try {
            $returned = app(MethodCache::class)->call($component, $meta, $method, $arguments, fn () => $component->{$method}(...$arguments));
        } catch (ValidationException $exception) {
            $context->errors = $exception->validator->errors();

            return null;
        } catch (Throwable $exception) {
            if (! method_exists($component, ComponentHooks::EXCEPTION)) {
                throw $exception;
            }

            /** exception(): handled, unless it throws again. The call answers with the state as the hook left it. */
            Features::hook($component, ComponentHooks::EXCEPTION, $exception);

            return null;
        }

        $features->called($component, $method, $returned);

        return $returned;
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
        $type = $parameter['type'] ?? 'mixed';
        $fits = Types::fits($type, $value) || ($type === 'mixed' && $value === null);

        /** Uploads reach a component through its Upload and #[Uploads] properties, never an argument. */
        if ((! $fits && ! ($value === null && $parameter['nullable'])) || PropertyMeta::holdsUpload($value)) {
            throw new HttpException(403, "Invalid argument \${$parameter['name']}.");
        }

        return $value;
    }

    /**
     * This request's Fusion. The router keeps a controller between requests (Octane, Nitro's
     * workers): Fusion, bound per request, is not kept with it.
     */
    private function incoming(): IncomingState
    {
        return app(IncomingState::class);
    }

    private function names(): BrowserNames
    {
        return app(BrowserNames::class);
    }

    private function components(): Components
    {
        return app(Components::class);
    }

    private function features(): Features
    {
        return app(Features::class);
    }

    private function snapshots(): Snapshots
    {
        return app(Snapshots::class);
    }
}
