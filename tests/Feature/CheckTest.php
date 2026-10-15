<?php

namespace Nitro\Tests\Feature;

use Nitro\Build\Bundle;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentReader;
use Nitro\Support\Wire;
use Nitro\Tests\Fixtures\Components\Store;
use Nitro\Tests\Fixtures\Invalid\BlurInStore;
use Nitro\Tests\Fixtures\Invalid\BlurLocked;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * A check (#[Validate(onBlur: true)]): the values the browser sent, laid over the state the server
 * signed and checked as a call's are, against the rules of the paths named. Nothing of the
 * component runs and nothing is kept: the answer is those paths' messages only.
 */
class CheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Store::$log = [];
    }

    protected function tearDown(): void
    {
        if ($this->app !== null && config('nitro.build.obfuscate')) {
            $this->console()->call('nitro:clear');
        }

        parent::tearDown();
    }

    /** The sign-up page's signed state, then a check of these paths with these values. */
    private function check(mixed $paths, array $updates = [], ?array $snapshot = null, array $extra = []): Response
    {
        $snapshot ??= $this->snapshots((string) $this->get('/sign-up')->getContent())[0]['envelope'];
        $this->app->forgetScopedInstances();
        Store::$log = [];

        return $this->update([
            'snapshot' => $snapshot,
            'updates' => array_map(Wire::encode(...), $updates),
            'check' => $paths,
        ] + $extra);
    }

    /** @return array<string, list<string>> */
    private function errors(Response $response): array
    {
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** In the wire format, as every PHP array the browser gets. */
        return Wire::decode($this->json($response)['errors']);
    }

    #[DataProvider('frameworks')]
    public function test_only_the_paths_checked_answer(string $framework): void
    {
        $this->boot($framework);

        /** The password is still being typed: it is sent, but not checked. */
        $errors = $this->errors($this->check(['email'], ['email' => 'not an email', 'password' => 'short']));

        $this->assertSame(['email' => ['The email address field must be a valid email address.']], $errors);
    }

    #[DataProvider('frameworks')]
    public function test_a_value_within_the_rules_answers_no_messages(string $framework): void
    {
        $this->boot($framework);

        $this->assertSame([], $this->errors($this->check(['email'], ['email' => 'ada@example.com'])));
    }

    #[DataProvider('frameworks')]
    public function test_nothing_of_the_component_runs_and_nothing_comes_back_but_messages(string $framework): void
    {
        $this->boot($framework);

        $response = $this->check(['email'], ['email' => 'ada@example.com']);

        $this->assertSame([], Store::$log, 'no boot() or hydrate()');
        $this->assertSame(['errors'], array_keys($this->json($response)), 'no state, no effects');

        /** The same values sent as a call: its hooks run. */
        $snapshot = $this->snapshots((string) $this->get('/sign-up')->getContent())[0]['envelope'];
        $this->app->forgetScopedInstances();
        Store::$log = [];
        $this->update(['snapshot' => $snapshot, 'updates' => ['email' => Wire::encode('ada@example.com')], 'call' => null]);
        $this->assertSame(['boot', 'hydrate'], Store::$log);
    }

    #[DataProvider('frameworks')]
    public function test_a_rule_reads_the_other_fields_sent(string $framework): void
    {
        $this->boot($framework);

        $this->assertSame(
            ['password' => ['The password field confirmation does not match.']],
            $this->errors($this->check(['password'], ['password' => 'secret-words', 'password_confirmation' => 'other-words'])),
        );
        $this->assertSame([], $this->errors($this->check(['password'], ['password' => 'secret-words', 'password_confirmation' => 'secret-words'])));
    }

    #[DataProvider('frameworks')]
    public function test_a_row_of_a_list_is_checked_by_the_lists_rules(string $framework): void
    {
        $this->boot($framework);

        $lines = [['qty' => 2], ['qty' => 0], ['qty' => -1]];

        /** lines.*.qty covers every row; the answer is the row left only. */
        $this->assertSame(['lines.1.qty'], array_keys($this->errors($this->check(['lines.1.qty'], ['lines' => $lines]))));
        $this->assertSame([], $this->errors($this->check(['lines.0.qty'], ['lines' => $lines])));
    }

    #[DataProvider('frameworks')]
    public function test_a_property_without_rules_answers_no_messages(string $framework): void
    {
        $this->boot($framework);

        $this->assertSame([], $this->errors($this->check(['password_confirmation'], ['password_confirmation' => 'x'])));
    }

    #[DataProvider('frameworks')]
    public function test_its_state_and_values_are_checked_as_a_calls_are(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshots((string) $this->get('/sign-up')->getContent())[0]['envelope'];

        $forged = $snapshot;
        $forged['state'] = str_replace('free', 'gold', $forged['state']);
        $this->assertSame(419, $this->check(['email'], snapshot: $forged)->getStatusCode(), 'state the server did not sign');

        $this->assertSame(403, $this->check(['email'], ['plan' => 'gold'], $snapshot)->getStatusCode(), 'a locked property');
        $this->assertSame(403, $this->check(['email'], ['email' => 5], $snapshot)->getStatusCode(), 'a value not of its type');
        $this->assertSame(403, $this->check(['nothing'], [], $snapshot)->getStatusCode(), 'a path of no property');
        $this->assertSame([], Store::$log);
    }

    #[DataProvider('frameworks')]
    public function test_a_malformed_check_is_refused(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshots((string) $this->get('/sign-up')->getContent())[0]['envelope'];

        foreach ([[], 'email', [5], [''], ['email' => 'email'], array_fill(0, 51, 'email')] as $paths) {
            $this->assertSame(400, $this->check($paths, [], $snapshot)->getStatusCode(), json_encode($paths));
        }

        /** Of a store made from its defaults: there is no signed state to check, and nothing is made for it. */
        $this->app->forgetScopedInstances();
        $this->assertSame(400, $this->update(['snapshot' => null, 'store' => 'stores::cart', 'check' => ['items']])->getStatusCode());
    }

    #[DataProvider('frameworks')]
    public function test_on_blur_is_marked_for_the_browser(string $framework): void
    {
        $this->boot($framework);

        $chunk = $this->app->make(Bundle::class)->compileAll()['sign-up'];

        $this->assertStringContainsString('"email":{"locked":false,"default":"","blur":true}', $chunk);
        $this->assertStringContainsString('"password_confirmation":{"locked":false,"default":""}', $chunk, 'no rules, no check');
        $this->assertStringContainsString('"lines":{"locked":false,"default":[]}', $chunk, 'rules, but not on blur');
    }

    public function test_on_blur_needs_a_property_the_browser_can_change(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('$total is #[Validate(onBlur: true)]: a field bound to it is checked as it is left, so it is a plain value the browser can change (not #[Locked], not a model or an upload)');

        ComponentReader::read(BlurLocked::class, 'blur-locked', 'nitro.components.blur-locked');
    }

    public function test_on_blur_is_a_components_not_a_stores(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('#[Validate(onBlur: true)] is for components: $email of the store');

        ComponentReader::read(BlurInStore::class, 'stores::blur-in-store', 'nitro.stores.blur-in-store');
    }

    #[DataProvider('frameworks')]
    public function test_an_obfuscated_build_checks_by_the_browsers_names(string $framework): void
    {
        $this->boot($framework);
        config(['nitro.build.obfuscate' => true]);
        $this->app->forgetScopedInstances();
        $this->assertSame(0, $this->console()->call('nitro:build'), $this->console()->output());
        $names = $this->app->make(Bundle::class)->obfuscator() ?? $this->fail('the build is not obfuscated');

        $email = $names->property('email');
        $errors = $this->errors($this->check([$email], [$email => 'not an email']));

        $this->assertSame([$email], array_keys($errors), 'the field by the browser\'s name');
    }
}
