<?php

namespace Nitro\Fusion\State;

use Nitro\Fusion\Build\BrowserNames;
use Nitro\Fusion\Support\Signer;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A component's or store's state as the browser holds it: sealed (signed for this session, named
 * as the browser knows it, with an obfuscated build's names) on the way out, and opened (verified,
 * within fusion.state.lifetime) on the way back. The one place either happens.
 */
final class Snapshots
{
    /** @param  ?int  $lifetime  fusion.state.lifetime: how long a snapshot is good for, in seconds; null: as long as its session */
    public function __construct(
        private readonly Signer $signer,
        private readonly BrowserNames $names,
        private readonly ?int $lifetime,
    ) {
    }

    /** The envelope for the browser, as JSON, for the component or store named $name. */
    public function json(Snapshot $snapshot, string $name): string
    {
        return $snapshot->toJson($this->signer, $this->names->component($name), $this->names->obfuscator());
    }

    /**
     * The envelope for the browser, for a JSON response.
     *
     * @return array<string, mixed>
     */
    public function envelope(Snapshot $snapshot, string $name): array
    {
        return json_decode($this->json($snapshot, $name), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The envelope the browser sent back, verified.
     *
     * @throws HttpException 419 when it was changed, belongs to another session, or has expired
     */
    public function open(mixed $envelope): Snapshot
    {
        return Snapshot::fromEnvelope($envelope, $this->signer, $this->lifetime);
    }
}
