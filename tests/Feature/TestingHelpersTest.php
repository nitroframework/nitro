<?php

namespace Nitro\Fusion\Tests\Feature;

use Illuminate\Testing\TestResponse;
use Nitro\Fusion\Testing\AssertableFusion;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * $response->assertFusion() and $response->fusionState(), as Inertia's assertInertia(): on a
 * rendered page and on a fusion:navigate visit's page data.
 */
class TestingHelpersTest extends TestCase
{
    /** A request of its own, as in production (Fusion's per-request state starts afresh). */
    private function response(string $uri, bool $visit = false): TestResponse
    {
        $this->app->forgetScopedInstances();

        return TestResponse::fromBaseResponse($visit
            ? $this->request('GET', $uri, ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_FUSION_NAVIGATE' => '1'])
            : $this->get($uri));
    }

    #[DataProvider('frameworks')]
    public function test_a_page_and_its_state_are_asserted(string $framework): void
    {
        $this->boot($framework);

        foreach ([false, true] as $visit) {
            $this->response('/students/1', $visit)->assertFusion(fn (AssertableFusion $page) => $page
                ->page('pages::student-page')
                ->has('student')
                ->has('note')
                ->where('student.name', 'Ada Lovelace')
                ->where('note', fn ($note) => $note === '')
                ->missing('student.secret')
                ->missing('nothing'));
        }

        $this->assertSame('Ada Lovelace', $this->response('/students/1')->fusionState('student.name'));
        $this->assertSame(0, $this->response('/counter')->fusionState('count'));
    }

    #[DataProvider('frameworks')]
    public function test_counts_are_asserted(string $framework): void
    {
        $this->boot($framework);

        $this->response('/counter')->assertFusion(fn (AssertableFusion $page) => $page
            ->page('pages::counter')
            ->has('history', 0));
    }

    #[DataProvider('frameworks')]
    public function test_a_failed_expectation_fails_the_test(string $framework): void
    {
        $this->boot($framework);
        $response = $this->response('/students/1');

        foreach ([
            fn (AssertableFusion $page) => $page->page('pages::counter'),
            fn (AssertableFusion $page) => $page->has('nothing'),
            fn (AssertableFusion $page) => $page->where('student.name', 'Grace Hopper'),
            fn (AssertableFusion $page) => $page->missing('student'),
        ] as $expectation) {
            try {
                $response->assertFusion($expectation);
                $this->fail('The expectation must fail.');
            } catch (AssertionFailedError $e) {
                $this->assertStringContainsString('pages::student-page', $e->getMessage());
            }
        }
    }

    #[DataProvider('frameworks')]
    public function test_a_response_that_is_not_a_page_fails(string $framework): void
    {
        $this->boot($framework);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Not a Fusion page');

        $this->response('/login')->assertFusion();
    }
}
