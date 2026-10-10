<?php

namespace Nitro\Tests\Feature;

use Illuminate\Testing\TestResponse;
use Nitro\Support\Wire;
use Nitro\Testing\AssertableNitro;
use Nitro\Tests\Fixtures\Models\User;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * $response->assertNitro() and $response->nitroState(), as Inertia's assertInertia(): on a
 * rendered page and on a nitro:navigate visit's page data.
 */
class TestingHelpersTest extends TestCase
{
    /** A request of its own, as in production (Nitro's per-request state starts afresh). */
    private function response(string $uri, bool $visit = false): TestResponse
    {
        $this->app->forgetScopedInstances();

        return TestResponse::fromBaseResponse($visit
            ? $this->request('GET', $uri, ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_NITRO_NAVIGATE' => '1'])
            : $this->get($uri));
    }

    #[DataProvider('frameworks')]
    public function test_a_page_and_its_state_are_asserted(string $framework): void
    {
        $this->boot($framework);

        foreach ([false, true] as $visit) {
            $this->response('/students/1', $visit)->assertNitro(fn (AssertableNitro $page) => $page
                ->page('pages::student-page')
                ->has('student')
                ->has('note')
                ->where('student.name', 'Ada Lovelace')
                ->where('note', fn ($note) => $note === '')
                ->missing('student.secret')
                ->missing('nothing'));
        }

        $this->assertSame('Ada Lovelace', $this->response('/students/1')->nitroState('student.name'));
        $this->assertSame(0, $this->response('/counter')->nitroState('count'));
    }

    #[DataProvider('frameworks')]
    public function test_counts_are_asserted(string $framework): void
    {
        $this->boot($framework);

        $this->response('/counter')->assertNitro(fn (AssertableNitro $page) => $page
            ->page('pages::counter')
            ->has('history', 0));
    }

    #[DataProvider('frameworks')]
    public function test_a_failed_expectation_fails_the_test(string $framework): void
    {
        $this->boot($framework);
        $response = $this->response('/students/1');

        foreach ([
            fn (AssertableNitro $page) => $page->page('pages::counter'),
            fn (AssertableNitro $page) => $page->has('nothing'),
            fn (AssertableNitro $page) => $page->where('student.name', 'Grace Hopper'),
            fn (AssertableNitro $page) => $page->missing('student'),
        ] as $expectation) {
            try {
                $response->assertNitro($expectation);
                $this->fail('The expectation must fail.');
            } catch (AssertionFailedError $e) {
                $this->assertStringContainsString('pages::student-page', $e->getMessage());
            }
        }
    }

    /** A #[Server] call's answer reads as a page does, with its validation errors. */
    #[DataProvider('frameworks')]
    public function test_a_calls_answer_and_its_errors_are_asserted(string $framework): void
    {
        $this->boot($framework);
        $user = User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x']);
        $this->app->instance('request', \Illuminate\Http\Request::create('/'));
        $this->app->make('auth')->guard('web')->setUser($user);
        $wallet = $this->snapshots((string) $this->get('/secure')->getContent())[0]['envelope'];
        $call = fn (array $snapshot, int $amount) => TestResponse::fromBaseResponse($this->update([
            'snapshot' => $snapshot,
            'updates' => [],
            'call' => ['method' => 'withdraw', 'params' => array_map(Wire::encode(...), [$amount])],
        ]));

        $refused = $call($wallet, 500);
        $refused->assertNitro(fn (AssertableNitro $page) => $page->hasErrors('amount')->hasErrors()->where('balance', 100));
        $this->assertSame(['amount' => ['Not enough money.']], AssertableNitro::fromResponse($refused)->errors());

        $call($refused->json('snapshot'), 5)->assertNitro(fn (AssertableNitro $page) => $page->hasNoErrors());

        $this->expectException(AssertionFailedError::class);
        $refused->assertNitro(fn (AssertableNitro $page) => $page->hasNoErrors());
    }

    #[DataProvider('frameworks')]
    public function test_a_response_that_is_not_a_page_fails(string $framework): void
    {
        $this->boot($framework);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Not a Nitro page');

        $this->response('/login')->assertNitro();
    }
}
