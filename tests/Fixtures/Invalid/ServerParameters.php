<?php

namespace Nitro\Tests\Fixtures\Invalid;

use Illuminate\Database\Eloquent\Model;
use Nitro\Attributes\Server;
use Nitro\Component;
use Nitro\Tests\Fixtures\Models\Student;

/**
 * #[Server] parameters the browser can never fill, one class each (outside the discovered
 * directories: they would fail the build).
 */
class VariadicModels extends Component
{
    #[Server]
    public function remove(Student ...$students): void
    {
    }
}

class AbstractModelParameter extends Component
{
    #[Server]
    public function remove(Model $model): void
    {
    }
}

enum Mood
{
    case Happy;
}

class PureEnumParameter extends Component
{
    #[Server]
    public function feel(Mood $mood): void
    {
    }
}

class ObjectParameter extends Component
{
    #[Server]
    public function take(object $thing): void
    {
    }
}

class CallableParameter extends Component
{
    #[Server]
    public function run(callable $then): void
    {
    }
}
