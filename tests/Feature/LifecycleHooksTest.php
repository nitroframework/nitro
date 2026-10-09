<?php

namespace Nitro\Tests\Feature;

use Nitro\Build\Bundle;
use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentReader;
use Nitro\Support\ComponentHooks;
use Nitro\Support\PropertyHooks;
use Nitro\Tests\Fixtures\Invalid\ServerRendered;
use Nitro\Tests\Fixtures\Invalid\ServerUpdating;
use Nitro\Tests\Fixtures\Pages\Lifecycle;
use Nitro\Support\Wire;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The hooks' one home (Support/ComponentHooks.php, PropertyHooks.php) and what the compiler
 * gives the browser from it. Their running is in the browser tests (/lifecycle).
 */
class LifecycleHooksTest extends TestCase
{
    public function test_a_property_hook_names_its_property_in_studly_case(): void
    {
        $properties = ['title', 'first_name', 'form'];

        $this->assertSame(['when' => 'updating', 'property' => 'title'], PropertyHooks::parse('updatingTitle', $properties));
        $this->assertSame(['when' => 'updated', 'property' => 'first_name'], PropertyHooks::parse('updatedFirstName', $properties));
        $this->assertSame(['when' => 'updated', 'property' => null], PropertyHooks::parse('updated', $properties));
        $this->assertNull(PropertyHooks::parse('updatedAt', $properties), 'names no property: an ordinary method');
        $this->assertNull(PropertyHooks::parse('updatedtitle', $properties));
        $this->assertNull(PropertyHooks::parse('save', $properties));
    }

    public function test_the_component_hooks_and_where_they_run(): void
    {
        foreach (['boot', 'mount', 'hydrate', 'dehydrate', 'exception', 'render'] as $reserved) {
            $this->assertTrue(ComponentHooks::isReserved($reserved), $reserved);
        }

        $this->assertNull(ComponentHooks::whyNotServer('mounted'));
        $this->assertNull(ComponentHooks::whyNotServer('destroyed'));
        $this->assertNotNull(ComponentHooks::whyNotServer('rendered'));
        $this->assertNull(PropertyHooks::whyNotServer('updatedTitle', ['title']));
        $this->assertNotNull(PropertyHooks::whyNotServer('updatingTitle', ['title']));
    }

    public function test_the_metadata_lists_the_hooks_a_component_has(): void
    {
        $meta = ComponentReader::read(Lifecycle::class, 'pages::lifecycle', 'nitro.pages.lifecycle');

        $this->assertSame(['rendered'], $meta->componentHooks());
        $this->assertEqualsCanonicalizing([
            ['method' => 'updatingTitle', 'when' => 'updating', 'property' => 'title'],
            ['method' => 'updating', 'when' => 'updating', 'property' => null],
            ['method' => 'updatedTitle', 'when' => 'updated', 'property' => 'title'],
            ['method' => 'updatedForm', 'when' => 'updated', 'property' => 'form'],
            ['method' => 'updatedFirstName', 'when' => 'updated', 'property' => 'first_name'],
            ['method' => 'updatedCode', 'when' => 'updated', 'property' => 'code'],
        ], $meta->propertyHooks());
        $this->assertTrue($meta->isServerMethod('updatedCode'));
        $this->assertNotContains('exception', $meta->browserMethods, 'a server hook is never compiled');
    }

    #[DataProvider('frameworks')]
    public function test_the_browser_gets_the_hooks_by_property(string $framework): void
    {
        $this->boot($framework);

        $code = implode("\n", $this->app->make(Bundle::class)->compileAll());

        $this->assertStringContainsString("hooks: {\"rendered\":\"rendered\"},\npropertyHooks: {\"title\":{\"updating\":\"updatingTitle\",\"updated\":\"updatedTitle\"},\"*\":{\"updating\":\"updating\"},\"form\":{\"updated\":\"updatedForm\"},\"first_name\":{\"updated\":\"updatedFirstName\"},\"code\":{\"updated\":\"updatedCode\"}},", $code);
        $this->assertDoesNotMatchRegularExpression('/\bexception\s*[:(]/', $code, 'exception() stays on the server');
    }

    #[DataProvider('frameworks')]
    public function test_exception_handles_what_a_server_method_throws(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshots((string) $this->get('/lifecycle')->getContent())[0]['envelope'];

        $response = $this->update(['snapshot' => $snapshot, 'updates' => [], 'call' => ['method' => 'explode', 'params' => []]]);

        $this->assertSame(200, $response->getStatusCode(), 'handled: the call answers');
        $this->assertSame('handled: it broke', Wire::fromJson($this->json($response)['snapshot']['state'])['data']['failure']);
    }

    #[DataProvider('frameworks')]
    public function test_validate_only_checks_the_properties_it_names(string $framework): void
    {
        $this->boot($framework);
        $snapshot = $this->snapshots((string) $this->get('/lifecycle')->getContent())[0]['envelope'];
        $call = fn (array $updates) => Wire::fromJson($this->json($this->update(['snapshot' => $snapshot, 'updates' => $updates, 'call' => ['method' => 'checkEmail', 'params' => []]]))['snapshot']['state']);

        $refused = $call([]);
        $this->assertSame(['form.email'], array_keys($refused['memo']['errors']), 'the email is checked, the empty nickname is not');
        $this->assertSame([], $refused['data']['log'], 'the method stopped there');

        $passed = $call(['form' => Wire::encode(['email' => 'ada@example.com'])]);
        $this->assertSame([], $passed['memo']['errors'] ?? []);
        $this->assertSame(['email checked'], $passed['data']['log']);
    }

    public function test_rendered_cannot_be_server(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('#[Server] on rendered() runs after every render');

        ComponentReader::read(ServerRendered::class, 'server-rendered', 'nitro.components.server-rendered');
    }

    public function test_an_updating_hook_cannot_be_server(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('#[Server] on updatingEmail() runs before the change is made');

        ComponentReader::read(ServerUpdating::class, 'server-updating', 'nitro.components.server-updating');
    }
}
