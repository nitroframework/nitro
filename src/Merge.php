<?php

namespace Nitro\Fusion;

use Closure;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use InvalidArgumentException;

/**
 * A page property merged into the value the page has on a partial reload, instead of replacing
 * it, as Inertia's merge props ($this->merge(), $this->deepMerge(), $this->scroll() in mount()).
 * A first render or a visit sets it as usual.
 *
 * The browser merges the value it is sent; the signed partial reload carries the rule, and the
 * server merges it the same way when the next #[Server] call lays it over the snapshot. merge.js
 * mirrors apply().
 */
final class Merge
{
    private string $mode = 'append';

    private ?string $path = null;

    /** @var list<string> */
    private array $match = [];

    /** $this->scroll(): the value is a paginator, sent as ['data' => items, 'scroll' => where it is]. */
    private bool $scroll = false;

    public function __construct(private readonly mixed $value)
    {
    }

    /** New items go after the ones the page has (of the list at $path, dot notation, when given). */
    public function append(?string $path = null): self
    {
        $this->mode = 'append';
        $this->path = $path ?? $this->path;

        return $this;
    }

    /** New items go before the ones the page has. */
    public function prepend(?string $path = null): self
    {
        $this->mode = 'prepend';
        $this->path = $path ?? $this->path;

        return $this;
    }

    /** Arrays are merged key by key, all the way down; lists are appended. */
    public function deep(): self
    {
        $this->mode = 'deep';

        return $this;
    }

    /** An item with the same value for these keys replaces the one the page has, in its place. */
    public function matchOn(string ...$keys): self
    {
        $this->match = array_values($keys);

        return $this;
    }

    /** @internal $this->scroll() */
    public function asScroll(): self
    {
        $this->scroll = true;
        $this->mode = 'append';
        $this->path = 'data';

        return $this;
    }

    /**
     * How to merge: what the browser and the server's partial reload follow.
     *
     * @return array{mode: string, path: ?string, match: list<string>}
     */
    public function rule(): array
    {
        return ['mode' => $this->mode, 'path' => $this->path, 'match' => $this->match];
    }

    /** The value: the closure called, a paginator as the page holds it. */
    public function resolve(callable $call): mixed
    {
        $value = $this->value instanceof Closure ? $call($this->value) : $this->value;

        return $this->scroll ? self::paginated($value) : $value;
    }

    /**
     * A paginator as a property holds it: its items, and where it is (the page name and the
     * pages around it) for $loadMore.
     *
     * @return array{data: array, scroll: array{pageName: string, currentPage: int|string|null, nextPage: int|string|null, previousPage: int|string|null}}
     */
    public static function paginated(mixed $paginator): array
    {
        return match (true) {
            $paginator instanceof LengthAwarePaginator, $paginator instanceof Paginator => [
                'data' => $paginator->toArray()['data'],
                'scroll' => [
                    'pageName' => $paginator->getPageName(),
                    'currentPage' => $paginator->currentPage(),
                    'nextPage' => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
                    'previousPage' => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
                ],
            ],
            $paginator instanceof AbstractCursorPaginator => [
                'data' => $paginator->toArray()['data'],
                'scroll' => [
                    'pageName' => $paginator->getCursorName(),
                    'currentPage' => $paginator->cursor()?->encode(),
                    'nextPage' => $paginator->nextCursor()?->encode(),
                    'previousPage' => $paginator->previousCursor()?->encode(),
                ],
            ],
            default => throw new InvalidArgumentException('$this->scroll() takes a paginator (paginate(), simplePaginate() or cursorPaginate()).'),
        };
    }

    /**
     * The value the page has, with the new one merged in by the rule.
     *
     * @param  array{mode: string, path: ?string, match: list<string>}  $rule
     */
    public static function apply(mixed $current, mixed $new, array $rule): mixed
    {
        if ($rule['mode'] === 'deep') {
            return self::deepMerge($current, $new, $rule['match']);
        }

        $prepend = $rule['mode'] === 'prepend';

        if ($rule['path'] === null) {
            return self::lists($current, $new, $prepend, $rule['match']);
        }

        if (! is_array($new)) {
            return $new;
        }

        data_set($new, $rule['path'], self::lists(data_get($current, $rule['path']), data_get($new, $rule['path']), $prepend, $rule['match']));

        return $new;
    }

    /** @param  list<string>  $match */
    private static function lists(mixed $current, mixed $new, bool $prepend, array $match): mixed
    {
        if (! is_array($current) || ! is_array($new) || ! array_is_list($current) || ! array_is_list($new)) {
            return $new;
        }

        if ($match !== []) {
            $at = [];
            foreach ($current as $index => $item) {
                $key = self::matchKey($item, $match);
                if ($key !== null) {
                    $at[$key] = $index;
                }
            }

            $added = [];
            foreach ($new as $item) {
                $key = self::matchKey($item, $match);
                if ($key !== null && isset($at[$key])) {
                    $current[$at[$key]] = $item;
                } else {
                    $added[] = $item;
                }
            }

            $new = $added;
        }

        return $prepend ? [...$new, ...$current] : [...$current, ...$new];
    }

    /** @param  list<string>  $match */
    private static function deepMerge(mixed $current, mixed $new, array $match): mixed
    {
        if (! is_array($current) || ! is_array($new)) {
            return $new;
        }

        if (array_is_list($current) && array_is_list($new)) {
            return self::lists($current, $new, false, $match);
        }

        foreach ($new as $key => $value) {
            $current[$key] = self::deepMerge($current[$key] ?? null, $value, $match);
        }

        return $current;
    }

    /**
     * An item's identity for matchOn: its values for the keys, as JSON (null when it has none).
     *
     * @param  list<string>  $match
     */
    private static function matchKey(mixed $item, array $match): ?string
    {
        if (! is_array($item)) {
            return null;
        }

        $values = [];
        foreach ($match as $key) {
            if (! array_key_exists($key, $item)) {
                return null;
            }
            $values[] = $item[$key];
        }

        return json_encode($values, JSON_THROW_ON_ERROR);
    }
}
