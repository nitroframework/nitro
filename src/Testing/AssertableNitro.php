<?php

namespace Nitro\Testing;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use Nitro\Rendering\PageData;
use Nitro\State\Snapshot;
use Nitro\State\Snapshots;
use Nitro\Support\Protocol;
use Nitro\Support\Wire;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * A Nitro page in a test response, as Inertia's AssertableInertia: the page's name and state
 * (its public properties, as the browser receives them) and its validation errors, from a
 * rendered page, a nitro:navigate visit's page data, or a #[Server] call's answer
 * (InteractsWithNitro::callNitro()).
 *
 *   $response->assertNitro(fn (AssertableNitro $page) => $page
 *       ->page('pages::orders.index')
 *       ->has('orders', 12)
 *       ->where('orders.0.status', 'new'));
 */
final class AssertableNitro
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $deferred
     * @param  array<string, list<string>>  $errors  by property (path)
     */
    private function __construct(
        private readonly string $name,
        private readonly array $data,
        private readonly array $deferred = [],
        private readonly array $errors = [],
    ) {
    }

    /** @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response */
    public static function fromResponse(TestResponse $response): self
    {
        $content = (string) $response->getContent();
        $page = json_decode($content, true);

        /** A visit's page data, or a #[Server] call's answer: each carries the signed state. */
        if (is_array($page) && (($page['nitro'] ?? null) === 1 || isset($page['snapshot']))) {
            return self::fromEnvelope($page['snapshot'] ?? null);
        }

        $page = preg_quote(Protocol::attribute('page'), '/');
        PHPUnit::assertMatchesRegularExpression('/<[a-zA-Z][^>]*\s'.$page.'="[^"]*"[^>]*>/', $content, 'Not a Nitro page: no element is marked '.Protocol::attribute('page').'.');

        return self::fromEnvelope(app(PageData::class)->pageState($content));
    }

    private static function fromEnvelope(mixed $envelope): self
    {
        PHPUnit::assertIsArray($envelope, 'The Nitro page has no snapshot.');
        $state = Wire::fromJson((string) ($envelope['state'] ?? ''));
        PHPUnit::assertIsArray($state, 'The Nitro page\'s snapshot cannot be read.');

        /** An obfuscated build names the page and its properties by aliases: the page and the properties they stand for. */
        $snapshot = app(Snapshots::class)->ownNames(new Snapshot((string) $state['name'], (string) ($state['id'] ?? ''), (array) ($state['data'] ?? []), (array) ($state['memo'] ?? [])));

        return new self($snapshot->name, $snapshot->data, array_values((array) ($snapshot->memo['deferred'] ?? [])), (array) ($snapshot->memo['errors'] ?? []));
    }

    /** The page is the given one (its name, such as "pages::orders.index"). */
    public function page(string $name): self
    {
        PHPUnit::assertSame($name, $this->name, "The Nitro page is [{$this->name}], not [{$name}].");

        return $this;
    }

    /** The state has the key (dot notation), with this many items when a count is given. */
    public function has(string $key, ?int $count = null): self
    {
        PHPUnit::assertTrue(Arr::has($this->data, $key), "The Nitro page [{$this->name}] has no [{$key}].");

        if ($count !== null) {
            $value = Arr::get($this->data, $key);
            PHPUnit::assertIsArray($value, "[{$key}] of the Nitro page [{$this->name}] is not an array.");
            PHPUnit::assertCount($count, $value, "[{$key}] of the Nitro page [{$this->name}] does not have {$count} items.");
        }

        return $this;
    }

    /** The state does not have the key (dot notation). */
    public function missing(string $key): self
    {
        PHPUnit::assertFalse(Arr::has($this->data, $key), "The Nitro page [{$this->name}] has [{$key}].");

        return $this;
    }

    /** The key's value is the expected one, or the closure returns true for it. */
    public function where(string $key, mixed $expected): self
    {
        $this->has($key);
        $value = Arr::get($this->data, $key);

        if ($expected instanceof Closure) {
            PHPUnit::assertTrue($expected($value) === true, "[{$key}] of the Nitro page [{$this->name}] does not pass the check.");
        } else {
            PHPUnit::assertSame($expected, $value, "[{$key}] of the Nitro page [{$this->name}] is not the expected value.");
        }

        return $this;
    }

    /** The properties are deferred: loaded after the page is shown (all of them, when none are given: some are). */
    public function deferred(string ...$properties): self
    {
        if ($properties === []) {
            PHPUnit::assertNotEmpty($this->deferred, "The Nitro page [{$this->name}] defers nothing.");
        }

        foreach ($properties as $property) {
            PHPUnit::assertContains($property, $this->deferred, "The Nitro page [{$this->name}] does not defer [{$property}].");
        }

        return $this;
    }

    /** Nothing is deferred (a page as the browser has it once its deferred properties are loaded). */
    public function notDeferred(): self
    {
        PHPUnit::assertSame([], $this->deferred, "The Nitro page [{$this->name}] defers [".implode(', ', $this->deferred).'].');

        return $this;
    }

    /** Validation messages for these properties (paths: "form.email"); for any, when none are given. */
    public function hasErrors(string ...$keys): self
    {
        if ($keys === []) {
            PHPUnit::assertNotEmpty($this->errors, "The Nitro page [{$this->name}] has no validation errors.");
        }

        foreach ($keys as $key) {
            PHPUnit::assertArrayHasKey($key, $this->errors, "The Nitro page [{$this->name}] has no error for [{$key}]; it has: [".implode(', ', array_keys($this->errors)).'].');
        }

        return $this;
    }

    /** No validation messages. */
    public function hasNoErrors(): self
    {
        PHPUnit::assertSame([], $this->errors, "The Nitro page [{$this->name}] has validation errors: [".implode(', ', array_keys($this->errors)).'].');

        return $this;
    }

    /**
     * The validation messages, by property.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /** The page's state, or one value of it (dot notation). */
    public function state(?string $key = null): mixed
    {
        return $key === null ? $this->data : Arr::get($this->data, $key);
    }

    /** The page's name. */
    public function name(): string
    {
        return $this->name;
    }
}
