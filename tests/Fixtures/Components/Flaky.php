<?php

namespace Nitro\Tests\Fixtures\Components;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Nitro\Attributes\Server;
use Nitro\Component;
use RuntimeException;
use Throwable;

/**
 * A component that fails when told to (its mount(), a #[Server] call), with a fallback of its own;
 * or that refuses the request (abort(), a policy, a missing model, a guest, a redirect).
 */
class Flaky extends Component
{
    public int $saves = 0;

    public function mount(bool $fail = false, string $refuse = ''): void
    {
        $this->refuse($refuse);

        if ($fail) {
            throw new RuntimeException('The flaky service is down.');
        }
    }

    #[Server]
    public function save(): void
    {
        throw new RuntimeException('The flaky service is down.');
    }

    #[Server]
    public function open(string $refuse): void
    {
        $this->refuse($refuse);
    }

    public function fallback(Throwable $exception): string
    {
        return 'Flaky is resting <for now>.';
    }

    private function refuse(string $how): void
    {
        match ($how) {
            'abort' => abort(403),
            'gate' => throw new AuthorizationException('This action is unauthorized.'),
            'missing' => throw new ModelNotFoundException('No query results.'),
            'guest' => throw new AuthenticationException('Unauthenticated.', [], '/sign-in'),
            'redirect' => throw new HttpResponseException(redirect('/elsewhere')),
            default => null,
        };
    }
}
