<?php

namespace Nitro\Fusion;

use Closure;
use DateInterval;
use DateTimeInterface;

/**
 * Shared data sent to the browser once (Fusion::once(), Fusion::shareOnce()), as Inertia's once
 * props: the browser keeps it across visits and #[Server] calls, says it has it, and the server
 * neither calls the closure for the browser nor sends it again, until it expires.
 */
final class Once
{
    /** When the browser asks for it again: a Unix timestamp, or null for never. */
    private ?int $expiresAt = null;

    /** Sent with every response even when the browser has it. */
    private bool $fresh = false;

    public function __construct(public readonly Closure $value)
    {
    }

    /** Keep it for so many seconds, an interval, or until a moment. */
    public function until(DateTimeInterface|DateInterval|int $when): self
    {
        $this->expiresAt = match (true) {
            $when instanceof DateTimeInterface => $when->getTimestamp(),
            $when instanceof DateInterval => (new \DateTimeImmutable)->add($when)->getTimestamp(),
            default => time() + $when,
        };

        return $this;
    }

    public function expiresAt(): ?int
    {
        return $this->expiresAt;
    }

    public function isFresh(): bool
    {
        return $this->fresh;
    }

    /** Send it again with this response (it changed). */
    public function fresh(bool $fresh = true): self
    {
        $this->fresh = $fresh;

        return $this;
    }
}
