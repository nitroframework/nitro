<?php

namespace Nitro\Fusion\Tests\Feature;

use Nitro\Fusion\Support\Wire;
use Nitro\Fusion\Tests\Fixtures\Components\Store;
use Nitro\Fusion\Tests\Fixtures\Models\Student;
use Nitro\Fusion\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Model properties read again on each #[Server] call: a collection of several model classes
 * comes back as it was, and a single model whose row was deleted is null when its property is
 * nullable (the component can handle it), a 404 when it is not.
 */
class ModelStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Store::$log = [];
    }

    private function snapshot(int $student): array
    {
        return $this->snapshots((string) $this->get("/pair/{$student}")->getContent())[0]['envelope'];
    }

    private function ping(array $snapshot): Response
    {
        $this->app->forgetScopedInstances();

        return $this->update(['snapshot' => $snapshot, 'updates' => [], 'call' => ['method' => 'ping', 'params' => []]]);
    }

    #[DataProvider('frameworks')]
    public function test_a_collection_of_several_model_classes_comes_back_as_it_was(string $framework): void
    {
        $this->boot($framework);
        $grace = Student::create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);

        $response = $this->ping($this->snapshot($grace->id));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(['Grace Hopper / Student Grace Hopper,StudentByEmail Ada Lovelace'], Store::$log, 'each model its own class, in order');
    }

    #[DataProvider('frameworks')]
    public function test_a_nullable_model_whose_row_is_gone_is_null(string $framework): void
    {
        $this->boot($framework);
        $grace = Student::create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
        $snapshot = $this->snapshot($grace->id);

        $grace->delete();
        $response = $this->ping($snapshot);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(['gone / StudentByEmail Ada Lovelace'], Store::$log, 'null: the component handles it; the collection leaves the row out');
        $this->assertNull(Wire::fromJson($this->json($response)['snapshot']['state'])['data']['maybe']);
    }

    #[DataProvider('frameworks')]
    public function test_loaded_relations_are_loaded_again_and_travel_as_models(string $framework): void
    {
        $this->boot($framework);
        $grace = Student::create(['name' => 'Grace Hopper', 'email' => 'grace@example.com', 'mentor_id' => 1]);

        $html = (string) $this->get("/pair/{$grace->id}")->getContent();
        $this->assertStringContainsString('Grace Hopper: Ada Lovelace', $html);
        $this->assertStringContainsString('<li>Grace Hopper (Ada Lovelace, ada@example.com)</li>', $html);

        $response = $this->ping($this->snapshots($html)[0]['envelope']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $state = $this->json($response)['snapshot']['state'];
        $models = Wire::fromJson($state)['memo']['models'];
        $this->assertSame(['mentor'], $models['mentee']['relations'], 'the relations it had, in the signed state');
        $this->assertSame(['mentor'], $models['everyone']['relations']);
        $this->assertArrayNotHasKey('relations', $models['sure'], 'none loaded: none listed');

        $data = Wire::fromJson($state)['data'];
        $this->assertSame('Ada Lovelace', $data['mentee']['mentor']['name'], 'loaded again after the call');
        $this->assertSame('Ada Lovelace', $data['everyone'][1]['mentor']['name']);
        $this->assertArrayNotHasKey('secret', $data['mentee']['mentor'], 'a relation shows what its model shows');

        /** Models (and their relations) are marked: the browser reads ->name on them, and on no other array. */
        $this->assertStringContainsString('"$o":{"$m":[["id",2],["name","Grace Hopper"],["email","grace@example.com"],["mentor",{"$o":', $state);
    }

    #[DataProvider('frameworks')]
    public function test_a_model_that_must_exist_answers_404_when_its_row_is_gone(string $framework): void
    {
        $this->boot($framework);
        $grace = Student::create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
        $snapshot = $this->snapshot($grace->id);

        Student::find(1)->delete();

        $this->assertSame(404, $this->ping($snapshot)->getStatusCode(), '$sure is not nullable');
        $this->assertSame([], Store::$log);
    }
}
