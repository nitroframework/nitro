<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed component state, hardened (fusion.state): bound to the session it was made in, so
 * another visitor's snapshot or one from before a logout is refused; signed with the time it was
 * issued, so with a lifetime an old one is refused too.
 */
class StateSecurityTest extends TestCase
{
    /** The page's snapshot, as this session's browser has it. */
    private function snapshot(): array
    {
        return $this->snapshots((string) $this->get('/students/1')->getContent())[0]['envelope'];
    }

    private function call(array $snapshot): Response
    {
        $this->app->forgetScopedInstances();

        return $this->update(['snapshot' => $snapshot, 'updates' => [], 'call' => ['method' => 'rename', 'params' => ['Ada King']]]);
    }

    /**
     * Another visitor: a session of their own. Its cookie gone, and a fresh session store, as each
     * request has in production (the tests' application otherwise keeps the last one's data).
     */
    private function anotherSession(): void
    {
        $this->cookies = [];
        $this->app->make('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }

    /** The snapshot re-signed as the server would sign it now, with its issued time moved back. */
    private function issuedAgo(array $snapshot, int $seconds): array
    {
        $state = Wire::fromJson($snapshot['state']);
        $state['issued'] -= $seconds;
        $json = Wire::json($state);

        return ['state' => $json, 'checksum' => hash_hmac('sha256', $json, $this->fusion()->stateKey())];
    }

    #[DataProvider('frameworks')]
    public function test_a_snapshot_works_in_its_own_session(string $framework): void
    {
        $this->boot($framework);

        $this->assertSame(200, $this->call($this->snapshot())->getStatusCode());
    }

    #[DataProvider('frameworks')]
    public function test_another_sessions_snapshot_is_refused(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshot();

        $this->anotherSession();
        $this->get('/students/1');
        $response = $this->call($snapshot);

        $this->assertSame(419, $response->getStatusCode());
        $this->assertStringContainsString('from another session', (string) $response->getContent());
    }

    #[DataProvider('frameworks')]
    public function test_a_login_keeps_snapshots_and_a_logout_ends_them(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshot();

        $this->get('/_test/login');
        $this->assertSame(200, $this->call($snapshot)->getStatusCode(), 'the session moved, its secret with it');

        $this->get('/_test/logout');
        $this->assertSame(419, $this->call($snapshot)->getStatusCode(), 'a new session: the old state is no good');
    }

    #[DataProvider('frameworks')]
    public function test_a_lifetime_refuses_older_snapshots(string $framework): void
    {
        $this->boot($framework);
        $this->app->make('config')->set('fusion.state.lifetime', 60);
        $snapshot = $this->snapshot();

        $this->assertSame(200, $this->call($this->issuedAgo($snapshot, 30))->getStatusCode(), 'within its lifetime');

        $response = $this->call($this->issuedAgo($snapshot, 120));
        $this->assertSame(419, $response->getStatusCode());
        $this->assertStringContainsString('has expired', (string) $response->getContent());
    }

    #[DataProvider('frameworks')]
    public function test_without_a_lifetime_an_old_snapshot_of_the_session_still_works(string $framework): void
    {
        $this->boot($framework);

        $this->assertSame(200, $this->call($this->issuedAgo($this->snapshot(), 86400))->getStatusCode());
    }

    #[DataProvider('frameworks')]
    public function test_unbound_state_works_across_sessions(string $framework): void
    {
        $this->boot($framework);
        $this->app->make('config')->set('fusion.state.bind_to_session', false);
        $snapshot = $this->snapshot();

        $this->anotherSession();
        $this->get('/students/1');

        $this->assertSame(200, $this->call($snapshot)->getStatusCode(), 'a page cached for everyone');
    }

    #[DataProvider('frameworks')]
    public function test_binding_cannot_be_stripped_by_signing_with_the_application_key(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshot();

        /** Only the server has the application key; the session's secret never leaves the session. Re-signing needs one of them: a forged checksum is refused. */
        $forged = ['state' => $snapshot['state'], 'checksum' => hash_hmac('sha256', $snapshot['state'], 'a guessed key')];

        $this->assertSame(419, $this->call($forged)->getStatusCode());
    }
}
