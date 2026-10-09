<?php

namespace Nitro\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What Nitro signs, signed in one place: component state (a snapshot), an upload's token. Each
 * is signed for its purpose, so one cannot pass for another.
 *
 * The key comes from the application key (APP_KEY), mixed with this session's own secret
 * (nitro.state.bind_to_session) and who is logged in: a signature is good in the session it
 * was made for, for the user it was made for; not another visitor's, not after a logout, not for
 * the next user to log in. Without a session, the application key's alone.
 *
 * A signature from before APP_KEY was rotated still verifies while the old key is in
 * APP_PREVIOUS_KEYS (app.previous_keys), as Laravel's encrypter does: open pages keep working.
 * New signatures are made with the current key only.
 */
final class Signer
{
    public function __construct(private readonly Application $app)
    {
    }

    public function sign(string $purpose, string $payload): string
    {
        return hash_hmac('sha256', $purpose.'|'.$payload, $this->bound($this->derived($this->currentAppKey())));
    }

    public function verifies(string $purpose, string $payload, string $signature): bool
    {
        foreach ($this->appKeys() as $appKey) {
            if (hash_equals(hash_hmac('sha256', $purpose.'|'.$payload, $this->bound($this->derived($appKey))), $signature)) {
                return true;
            }
        }

        return false;
    }

    /** Nitro's key, from the current application key: for what is not a signature (an obfuscated build's names). */
    public function key(): string
    {
        return $this->derived($this->currentAppKey());
    }

    private function derived(string $appKey): string
    {
        return hash_hmac('sha256', 'nitro:state', $appKey);
    }

    /** The key, bound to this session and who is logged in (when state is bound to the session). */
    private function bound(string $key): string
    {
        $secret = $this->sessionSecret();

        return $secret === null ? $key : hash_hmac('sha256', 'nitro:session|'.$secret.'|'.$this->authIdentity(), $key);
    }

    private function currentAppKey(): string
    {
        $appKey = (string) $this->app->make('config')->get('app.key');

        if ($appKey === '') {
            throw new RuntimeException('Nitro signs component state with the application key: set APP_KEY.');
        }

        return $appKey;
    }

    /** @return list<string> the current application key, then the previous ones (APP_PREVIOUS_KEYS) */
    private function appKeys(): array
    {
        $previous = array_values(array_filter((array) $this->app->make('config')->get('app.previous_keys', []), fn (mixed $key) => is_string($key) && $key !== ''));

        return [$this->currentAppKey(), ...$previous];
    }

    /** Who is logged in (the default guard): '' for a guest. */
    private function authIdentity(): string
    {
        if (! $this->app->bound('auth')) {
            return '';
        }

        try {
            $id = $this->app->make('auth')->guard()->id();
        } catch (\Throwable) {
            return '';
        }

        return is_scalar($id) ? 'user:'.$id : '';
    }

    /**
     * This session's secret, made the first time it is needed and kept in the session: a login
     * keeps it (the session migrates), a logout ends it (the session is flushed). Null when state
     * is not bound to the session, or there is no session.
     */
    private function sessionSecret(): ?string
    {
        $session = $this->app->make('config')->get('nitro.state.bind_to_session') ? CurrentRequest::session($this->app) : null;

        if ($session === null) {
            return null;
        }

        $secret = $session->get('nitro.secret');

        if (! is_string($secret) || $secret === '') {
            $secret = Str::random(40);
            $session->put('nitro.secret', $secret);
        }

        return $secret;
    }
}
