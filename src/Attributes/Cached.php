<?php

namespace Nitro\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * A method's result, kept for a time or until one of its tags expires (Nitro::expire()), in the
 * application's cache. Nitro runs the method once and answers from the cache after:
 *
 *  - on mount(): the state it left, for the same inputs (props, route parameters, #[Url] values,
 *    mount()'s arguments). The view still renders for each request; only mount()'s work is kept,
 *    so it should only load state (a title, a share() or a flash in it would happen once).
 *  - on a #[Server] method: what it did, for the same arguments and state: the properties it
 *    changed and what it returned, done again without running it. For a method that loads data
 *    (a lookup, a search); one that saves runs every time, so it is not #[Cached].
 *
 *     #[Cached(300, tags: ['students'])]
 *     public function mount(): void
 *     {
 *         $this->students = Student::with('plan')->orderBy('name')->get()->all();
 *     }
 *
 * A result is kept by the method's arguments and the state it saw: all of it, or with $by only
 * the properties it depends on, so a lookup is found again whatever else changed since:
 *
 *     #[Server]
 *     #[Cached(300, tags: ['students'], by: ['status'])]
 *     public function lookUp(string $grade): void
 *
 * $perUser keeps one result for each signed-in user (and one for guests).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Cached
{
    /**
     * @param  int  $seconds  how long a result is kept
     * @param  list<string>  $tags  Nitro::expire() with one of them forgets it
     * @param  list<string>|null  $by  the properties the result depends on; null: the whole state
     */
    public function __construct(
        public readonly int $seconds = 60,
        public readonly array $tags = [],
        public readonly bool $perUser = false,
        public readonly ?array $by = null,
    ) {
        if ($seconds < 1) {
            throw new InvalidArgumentException('#[Cached] keeps a result for at least a second.');
        }

        foreach ([...$tags, ...($by ?? [])] as $name) {
            if (! is_string($name) || $name === '') {
                throw new InvalidArgumentException('#[Cached] tags and properties are names (non-empty strings).');
            }
        }
    }
}
