<?php

namespace Nitro\Fusion\Testing;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use Nitro\Fusion\Rendering\PageData;
use Nitro\Fusion\State\Snapshot;
use Nitro\Fusion\State\Snapshots;
use Nitro\Fusion\Support\Protocol;
use Nitro\Fusion\Support\Wire;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * A Fusion page in a test response, as Inertia's AssertableInertia: the page's name and state
 * (its public properties, as the browser receives them), from a rendered page or from a
 * fusion:navigate visit's page data.
 *
 *   $response->assertFusion(fn (AssertableFusion $page) => $page
 *       ->page('pages::orders.index')
 *       ->has('orders', 12)
 *       ->where('orders.0.status', 'new'));
 */
final class AssertableFusion
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $deferred
     */
    private function __construct(private readonly string $name, private readonly array $data, private readonly array $deferred = [])
    {
    }

    /** @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response */
    public static function fromResponse(TestResponse $response): self
    {
        $content = (string) $response->getContent();
        $page = json_decode($content, true);

        if (is_array($page) && ($page['fusion'] ?? null) === 1) {
            return self::fromEnvelope($page['snapshot'] ?? null);
        }

        $page = preg_quote(Protocol::attribute('page'), '/');
        PHPUnit::assertMatchesRegularExpression('/<[a-zA-Z][^>]*\s'.$page.'="[^"]*"[^>]*>/', $content, 'Not a Fusion page: no element is marked '.Protocol::attribute('page').'.');

        return self::fromEnvelope(app(PageData::class)->pageState($content));
    }

    private static function fromEnvelope(mixed $envelope): self
    {
        PHPUnit::assertIsArray($envelope, 'The Fusion page has no snapshot.');
        $state = Wire::fromJson((string) ($envelope['state'] ?? ''));
        PHPUnit::assertIsArray($state, 'The Fusion page\'s snapshot cannot be read.');

        /** An obfuscated build names the page and its properties by aliases: the page and the properties they stand for. */
        $snapshot = app(Snapshots::class)->ownNames(new Snapshot((string) $state['name'], (string) ($state['id'] ?? ''), (array) ($state['data'] ?? []), (array) ($state['memo'] ?? [])));

        return new self($snapshot->name, $snapshot->data, array_values((array) ($snapshot->memo['deferred'] ?? [])));
    }

    /** The page is the given one (its name, such as "pages::orders.index"). */
    public function page(string $name): self
    {
        PHPUnit::assertSame($name, $this->name, "The Fusion page is [{$this->name}], not [{$name}].");

        return $this;
    }

    /** The state has the key (dot notation), with this many items when a count is given. */
    public function has(string $key, ?int $count = null): self
    {
        PHPUnit::assertTrue(Arr::has($this->data, $key), "The Fusion page [{$this->name}] has no [{$key}].");

        if ($count !== null) {
            $value = Arr::get($this->data, $key);
            PHPUnit::assertIsArray($value, "[{$key}] of the Fusion page [{$this->name}] is not an array.");
            PHPUnit::assertCount($count, $value, "[{$key}] of the Fusion page [{$this->name}] does not have {$count} items.");
        }

        return $this;
    }

    /** The state does not have the key (dot notation). */
    public function missing(string $key): self
    {
        PHPUnit::assertFalse(Arr::has($this->data, $key), "The Fusion page [{$this->name}] has [{$key}].");

        return $this;
    }

    /** The key's value is the expected one, or the closure returns true for it. */
    public function where(string $key, mixed $expected): self
    {
        $this->has($key);
        $value = Arr::get($this->data, $key);

        if ($expected instanceof Closure) {
            PHPUnit::assertTrue($expected($value) === true, "[{$key}] of the Fusion page [{$this->name}] does not pass the check.");
        } else {
            PHPUnit::assertSame($expected, $value, "[{$key}] of the Fusion page [{$this->name}] is not the expected value.");
        }

        return $this;
    }

    /** The properties are deferred: loaded after the page is shown (all of them, when none are given: some are). */
    public function deferred(string ...$properties): self
    {
        if ($properties === []) {
            PHPUnit::assertNotEmpty($this->deferred, "The Fusion page [{$this->name}] defers nothing.");
        }

        foreach ($properties as $property) {
            PHPUnit::assertContains($property, $this->deferred, "The Fusion page [{$this->name}] does not defer [{$property}].");
        }

        return $this;
    }

    /** Nothing is deferred (a page as the browser has it once its deferred properties are loaded). */
    public function notDeferred(): self
    {
        PHPUnit::assertSame([], $this->deferred, "The Fusion page [{$this->name}] defers [".implode(', ', $this->deferred).'].');

        return $this;
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
