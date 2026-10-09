<?php

namespace Nitro\Tests\Fixtures\Components;

use Nitro\Attributes\Server;
use Nitro\Component;
use RuntimeException;
use Throwable;

/** A component that fails when told to (its mount(), a #[Server] call), with a fallback of its own. */
class Flaky extends Component
{
    public int $saves = 0;

    public function mount(bool $fail = false, bool $forbidden = false): void
    {
        abort_if($forbidden, 403);

        if ($fail) {
            throw new RuntimeException('The flaky service is down.');
        }
    }

    #[Server]
    public function save(): void
    {
        throw new RuntimeException('The flaky service is down.');
    }

    public function fallback(Throwable $exception): string
    {
        return 'Flaky is resting <for now>.';
    }
}
