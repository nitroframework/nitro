<?php

namespace Nitro\Fusion\Tests\Fixtures\Components;

use Nitro\Fusion\Attributes\LazyLoad;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Attributes\Title;
use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\Fixtures\Models\Student;

/**
 * A routed page: /students/{student} binds the model through mount()'s signature.
 */
#[Title('Student')]
#[LazyLoad('pages')]
class StudentPage extends Component
{
    public ?Student $student = null;

    public string $note = '';

    public function mount(Student $student): void
    {
        $this->student = $student;
    }

    public function shout(): string
    {
        return strtoupper($this->student->name).'!';
    }

    #[Server]
    public function rename(string $name): void
    {
        $this->student->update(['name' => $name]);
    }
}
