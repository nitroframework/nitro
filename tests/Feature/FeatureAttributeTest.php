<?php

namespace Nitro\Tests\Feature;

use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentReader;
use Nitro\Lifecycle\Features;
use Nitro\Tests\Fixtures\Attributes\Note;
use Nitro\Tests\Fixtures\Components\Store;
use Nitro\Tests\Fixtures\Invalid\AuditedBrowserMethod;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Attributes that work as features (FeatureAttribute), where they are placed: on the class every
 * hook, on a property updated() when it changed, on a #[Server] method calling() and called() for
 * it; before #[Validate]. #[Url] is one (RenderingTest, the browser tests).
 */
class FeatureAttributeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Store::$log = [];
    }

    private function features(): Features
    {
        return $this->app->make(Features::class);
    }

    #[DataProvider('frameworks')]
    public function test_each_runs_where_it_is_placed(string $framework): void
    {
        $this->boot($framework);
        $features = $this->features();
        $note = new Note;

        $features->start($note, 'note', 'note');
        $features->hydrate($note);
        $this->assertSame(['stamped boot NULL', 'stamped mounting', 'stamped hydrate'], Store::$log, 'on the class: every hook, its target null');

        Store::$log = [];
        $note->body = 'text';
        $this->assertTrue($features->updated($note, ['body']));
        $this->assertSame(['stamped updated body'], Store::$log, 'a property attribute only when its property changed');

        Store::$log = [];
        $note->title = "  ab \n  c ";
        $this->assertTrue($features->updated($note, ['title', 'body']));
        $this->assertSame(['stamped updated title,body', 'squish title'], Store::$log);
        $this->assertSame('ab c', $note->title);

        Store::$log = [];
        $features->calling($note, 'draft', []);
        $features->called($note, 'draft', null);
        $this->assertSame(['stamped calling draft'], Store::$log, 'a method attribute only for its method');

        Store::$log = [];
        $features->calling($note, 'publish', [3]);
        $features->called($note, 'publish', 'ab c x3');
        $features->dehydrate($note);
        $this->assertSame(['stamped calling publish', 'audit: calling publish(3)', 'audit: publish returned "ab c x3"', 'stamped dehydrate'], Store::$log, 'with its own arguments (as: audit)');
    }

    /** Before #[Validate]: a property's attribute tidies the value the rules then check. */
    #[DataProvider('frameworks')]
    public function test_they_run_before_validation(string $framework): void
    {
        $this->boot($framework);
        $features = $this->features();

        $note = new Note;
        $note->title = '  ab    c  ';
        $this->assertTrue($features->updated($note, ['title']), '"ab c" fits max:5');
        $this->assertSame('ab c', $note->title);

        $long = new Note;
        $long->title = 'abcdefgh';
        $this->assertFalse($features->updated($long, ['title']));
        $this->assertSame(['The title field must not be greater than 5 characters.'], $long->nitro()->errors->get('title'));
    }

    /**
     * Every kind starts in one order: its inputs (a prop, a route parameter), then the features
     * (a #[Url] value over the input), then mount(), which sees what they set.
     */
    #[DataProvider('frameworks')]
    public function test_an_instance_starts_inputs_then_features_then_mount(string $framework): void
    {
        $this->boot($framework);
        $this->app->instance('request', \Illuminate\Http\Request::create('/roster?q=ada'));
        $roster = new \Nitro\Tests\Fixtures\Components\Roster;
        $order = [];

        $this->features()->start(
            $roster,
            'roster-1',
            'roster',
            function () use ($roster, &$order) {
                $order[] = 'inputs';
                $roster->search = 'from the route';
            },
            function () use ($roster, &$order) {
                $order[] = 'mount saw '.$roster->search;
            },
        );

        $this->assertSame(['inputs', 'mount saw ada'], $order);
        $this->assertSame('ada', $roster->search, '#[Url] over the input');
        $this->assertSame(['roster-1', 'roster'], [$roster->nitro()->id, $roster->nitro()->name]);
    }

    /** Each component gets its own instances. */
    #[DataProvider('frameworks')]
    public function test_each_component_has_its_own(string $framework): void
    {
        $this->boot($framework);
        $features = $this->features();
        $one = new Note;
        $two = new Note;

        $features->boot($one);
        $features->boot($two);

        $this->assertSame(['stamped boot NULL', 'stamped boot NULL'], Store::$log);
    }

    /** Through a real #[Server] call: the wallet's withdraw() has #[Audited]; a failed call (a ValidationException) is not "called". */
    #[DataProvider('frameworks')]
    public function test_around_a_real_server_call(string $framework): void
    {
        $this->boot($framework);
        $user = \Nitro\Tests\Fixtures\Models\User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x']);
        $this->app->instance('request', \Illuminate\Http\Request::create('/'));
        $this->app->make('auth')->guard('web')->setUser($user);
        $snapshot = $this->snapshots((string) $this->get('/secure')->getContent())[0]['envelope'];

        $call = function (array $params) use ($snapshot) {
            $this->app->forgetScopedInstances();
            Store::$log = [];

            return $this->update(['snapshot' => $snapshot, 'updates' => [], 'call' => ['method' => 'withdraw', 'params' => $params]]);
        };

        $this->assertSame(200, $call([30, 'rent'])->getStatusCode());
        $this->assertSame(['wallet: calling withdraw(30,rent)', 'wallet: withdraw returned 70'], Store::$log);

        $this->assertSame(200, $call([500, 'car'])->getStatusCode());
        $this->assertSame(['wallet: calling withdraw(500,car)'], Store::$log, 'it threw (not enough money): no called()');
    }

    #[DataProvider('frameworks')]
    public function test_one_on_a_browser_method_fails_the_build(string $framework): void
    {
        $this->boot($framework);

        try {
            ComponentReader::read(AuditedBrowserMethod::class, 'audited-browser-method', 'nitro.components.audited-browser-method');
            $this->fail('Expected a compile error');
        } catch (CompileException $e) {
            $this->assertStringContainsString("#[Audited] on save(): it runs around a #[Server] method's call, and save() runs in the browser; mark it #[Server]", $e->getMessage());
            $this->assertStringEndsWith('AuditedBrowserMethod.php', (string) $e->sourceFile);
            $this->assertSame(12, $e->sourceLine, 'the method\'s line');
        }
    }
}
