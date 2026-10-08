<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Tests\Fixtures\Components\Store;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * #[Validate] on properties, checked on the server: what the browser changed is checked against
 * its rules before a #[Server] method runs, and a failure keeps the method from running, its
 * messages in $errors. $this->validate() with no rules uses them all.
 */
class ValidateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Store::$log = [];
    }

    /** A #[Server] call on /students/1 with these updates: the state it answers with. */
    private function call(string $method, array $updates = []): array
    {
        $snapshot = $this->snapshots((string) $this->get('/students/1')->getContent())[0]['envelope'];
        $this->app->forgetScopedInstances();

        $response = $this->update([
            'snapshot' => $snapshot,
            'updates' => array_map(Wire::encode(...), $updates),
            'call' => ['method' => $method, 'params' => []],
        ]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return Wire::fromJson($this->json($response)['snapshot']['state']);
    }

    #[DataProvider('frameworks')]
    public function test_a_value_against_the_rules_keeps_the_method_from_running(string $framework): void
    {
        $this->boot($framework);

        $state = $this->call('order', ['copies' => -500]);

        $this->assertSame([], Store::$log, 'the method did not run');
        $this->assertSame(['The number of copies field must be at least 1.'], $state['memo']['errors']['copies']);
    }

    #[DataProvider('frameworks')]
    public function test_a_value_within_the_rules_passes(string $framework): void
    {
        $this->boot($framework);

        $state = $this->call('order', ['copies' => 4]);

        $this->assertSame(['ordered 4'], Store::$log);
        $this->assertArrayNotHasKey('errors', $state['memo']);
    }

    #[DataProvider('frameworks')]
    public function test_rules_by_key_check_an_arrays_items(string $framework): void
    {
        $this->boot($framework);

        $state = $this->call('order', ['tags' => ['ok', 'x']]);

        $this->assertSame([], Store::$log);
        $this->assertArrayHasKey('tags.1', $state['memo']['errors']);
    }

    #[DataProvider('frameworks')]
    public function test_only_what_the_browser_changed_is_checked(string $framework): void
    {
        $this->boot($framework);

        /** A property without rules: as before, its type only. */
        $this->call('order', ['note' => str_repeat('a', 500)]);

        $this->assertSame(['ordered 1'], Store::$log);
    }

    #[DataProvider('frameworks')]
    public function test_validate_with_no_rules_uses_every_property_rules(string $framework): void
    {
        $this->boot($framework);

        $this->call('checkAll');
        $this->assertSame(['all valid'], Store::$log);
    }
}
