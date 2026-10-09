<?php

namespace Nitro\Tests\Feature;

use Nitro\Compiler\CompileException;
use Nitro\Compiler\ComponentReader;
use Nitro\Support\Wire;
use Nitro\Tests\Fixtures\Components\Store;
use Nitro\Tests\Fixtures\Invalid\AbstractModelParameter;
use Nitro\Tests\Fixtures\Invalid\CallableParameter;
use Nitro\Tests\Fixtures\Invalid\ObjectParameter;
use Nitro\Tests\Fixtures\Invalid\PureEnumParameter;
use Nitro\Tests\Fixtures\Invalid\VariadicModels;
use Nitro\Tests\Fixtures\Models\Student;
use Nitro\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

require_once dirname(__DIR__).'/Fixtures/Invalid/ServerParameters.php';

/**
 * #[Server] parameters typed with a model or a backed enum: filled from what the browser sends,
 * as route model binding fills a route's (a model from its key, the row itself giving its key too;
 * a backed enum from its value). Other classes still come from the container. Parameters the
 * browser can never fill refuse to build.
 */
class ServerParametersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Store::$log = [];
    }

    private function call(string $method, array $params): Response
    {
        $snapshot = $this->snapshots((string) $this->get('/students/1')->getContent())[0]['envelope'];
        $this->app->forgetScopedInstances();

        return $this->update([
            'snapshot' => $snapshot,
            'updates' => [],
            'call' => ['method' => $method, 'params' => array_map(Wire::encode(...), $params)],
        ]);
    }

    #[DataProvider('frameworks')]
    public function test_a_model_parameter_is_the_record_its_key_names(string $framework): void
    {
        $this->boot($framework);
        $grace = Student::create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);

        $response = $this->call('remove', [$grace->id]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(['removed Grace Hopper'], Store::$log);
        $this->assertNull(Student::find($grace->id), 'the record itself, deleted: not a new, empty model');
    }

    #[DataProvider('frameworks')]
    public function test_the_row_as_the_browser_has_it_gives_its_key(string $framework): void
    {
        $this->boot($framework);

        $this->assertSame(200, $this->call('pick', [['id' => 1, 'name' => 'Ada Lovelace']])->getStatusCode());
        $this->assertSame(['picked Ada Lovelace'], Store::$log);
    }

    #[DataProvider('frameworks')]
    public function test_a_missing_record_is_a_404_and_a_wrong_key_a_403(string $framework): void
    {
        $this->boot($framework);

        $this->assertSame(404, $this->call('remove', [999])->getStatusCode());
        $this->assertSame(403, $this->call('remove', [true])->getStatusCode());
        $this->assertSame(403, $this->call('remove', [])->getStatusCode(), 'not optional');
        $this->assertSame([], Store::$log);
    }

    #[DataProvider('frameworks')]
    public function test_a_nullable_model_takes_null(string $framework): void
    {
        $this->boot($framework);

        $this->assertSame(200, $this->call('pick', [null])->getStatusCode());
        $this->assertSame(['picked nobody'], Store::$log);
    }

    #[DataProvider('frameworks')]
    public function test_a_model_with_its_own_route_key(string $framework): void
    {
        $this->boot($framework);

        $this->assertSame(200, $this->call('pickByEmail', ['ada@example.com'])->getStatusCode());
        $this->assertSame(['by email Ada Lovelace'], Store::$log);
        $this->assertSame(404, $this->call('pickByEmail', [1])->getStatusCode(), 'by email, not by id');
    }

    #[DataProvider('frameworks')]
    public function test_a_backed_enum_from_its_value_beside_a_service(string $framework): void
    {
        $this->boot($framework);

        $this->assertSame(200, $this->call('grade', ['8'])->getStatusCode());
        $this->assertSame(['grade 8 '.Store::class], Store::$log, 'the service from the container, taking no value');
        $this->assertSame(403, $this->call('grade', ['9'])->getStatusCode(), 'not one of its cases');
        $this->assertSame(403, $this->call('grade', [['8']])->getStatusCode());
    }

    public function test_a_variadic_model_refuses_to_build(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('#[Server] remove($students): a model or an enum arrives one per parameter, not variadic');

        ComponentReader::read(VariadicModels::class, 'variadic-models', 'variadic-models');
    }

    public function test_an_abstract_model_refuses_to_build(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('#[Server] remove($model): Illuminate\Database\Eloquent\Model is abstract');

        ComponentReader::read(AbstractModelParameter::class, 'abstract-model', 'abstract-model');
    }

    public function test_object_and_callable_parameters_refuse_to_build(): void
    {
        foreach ([ObjectParameter::class => 'object', CallableParameter::class => 'callable'] as $class => $type) {
            try {
                ComponentReader::read($class, 'invalid', 'invalid');
                $this->fail("A {$type} parameter must not build.");
            } catch (CompileException $e) {
                $this->assertStringContainsString("a {$type} parameter cannot be filled from the browser", $e->getMessage());
            }
        }
    }

    public function test_a_pure_enum_refuses_to_build(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('is a pure enum: it has no value the browser can send');

        ComponentReader::read(PureEnumParameter::class, 'pure-enum', 'pure-enum');
    }
}
