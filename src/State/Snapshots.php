<?php

namespace Nitro\State;

use Nitro\Build\BrowserNames;
use Nitro\Compiler\Components;
use Nitro\Support\Protocol;
use Nitro\Support\Signer;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A component's or store's state as the browser holds it: sealed (signed for this session, named
 * as the browser knows it, with an obfuscated build's names) on the way out, and opened (verified,
 * within nitro.state.lifetime, read by its component's own names) on the way back. The one
 * place either happens.
 */
final class Snapshots
{
    /** @param  ?int  $lifetime  nitro.state.lifetime: how long a snapshot is good for, in seconds; null: as long as its session */
    public function __construct(
        private readonly Signer $signer,
        private readonly BrowserNames $names,
        private readonly Components $components,
        private readonly ?int $lifetime,
    ) {
    }

    /**
     * The envelope for the browser, as JSON, for the component or store named $name; without the
     * #[Html] values the browser has already ($known: its names => fingerprints).
     *
     * @param  array<string, string>  $known
     */
    public function json(Snapshot $snapshot, string $name, array $known = []): string
    {
        return $snapshot->toJson($this->signer, $this->names->component($name), $this->names->obfuscator(), $known);
    }

    /**
     * The envelope for the browser, for a JSON response.
     *
     * @param  array<string, string>  $known
     * @return array<string, mixed>
     */
    public function envelope(Snapshot $snapshot, string $name, array $known = []): array
    {
        return json_decode($this->json($snapshot, $name, $known), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The fingerprints of the #[Html] values a call says the browser has (its payload's
     * snapshot.html field): its names => strings, anything else dropped (they only ever leave
     * HTML out, so nothing in them is trusted).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public static function known(array $payload): array
    {
        $fingerprints = $payload[Protocol::get('snapshot')['html']] ?? null;

        return is_array($fingerprints) ? array_filter($fingerprints, fn ($fingerprint, $name) => is_string($name) && is_string($fingerprint), ARRAY_FILTER_USE_BOTH) : [];
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

    /**
     * A state as the browser named it, by its own names: the component's (an obfuscated build's
     * alias read as the name it stands for) and its properties' (none read for a component Nitro
     * does not know).
     */
    public function ownNames(Snapshot $snapshot): Snapshot
    {
        $snapshot = $snapshot->withName($this->names->componentName($snapshot->name));
        $properties = $this->components->has($snapshot->name) ? array_keys($this->components->meta($snapshot->name)->properties) : [];

        return $snapshot->fromBrowser($this->names->obfuscator(), $properties);
    }
}
