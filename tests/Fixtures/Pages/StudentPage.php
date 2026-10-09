<?php

namespace Nitro\Tests\Fixtures\Pages;

use Nitro\Attributes\LazyLoad;
use Nitro\Attributes\Server;
use Nitro\Attributes\Title;
use Nitro\Attributes\Validate;
use Nitro\Component;
use Nitro\Tests\Fixtures\Components\Store;
use Nitro\Tests\Fixtures\Models\Grade;
use Nitro\Tests\Fixtures\Models\Student;
use Nitro\Tests\Fixtures\Models\StudentByEmail;

/**
 * A routed page: /students/{student} binds the model through mount()'s signature.
 */
#[Title('Student')]
#[LazyLoad('pages')]
class StudentPage extends Component
{
    public ?Student $student = null;

    public string $note = '';

    /** #[Validate]: what the browser sets is checked on the server before a #[Server] method runs. */
    #[Validate('integer|min:1|max:10', as: 'number of copies')]
    public int $copies = 1;

    #[Validate(['tags' => 'array|max:3', 'tags.*' => 'string|min:2'])]
    public array $tags = [];

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

    /** A model parameter: resolved from the key the browser sends, as route model binding does. */
    #[Server]
    public function remove(Student $other): void
    {
        Store::$log[] = 'removed '.$other->name;
        $other->delete();
    }

    #[Server]
    public function pick(?Student $other): void
    {
        Store::$log[] = 'picked '.($other?->name ?? 'nobody');
    }

    /** Its own route key (getRouteKeyName(): email). */
    #[Server]
    public function pickByEmail(StudentByEmail $other): void
    {
        Store::$log[] = 'by email '.$other->name;
    }

    #[Server]
    public function order(): void
    {
        Store::$log[] = "ordered {$this->copies}";
    }

    /** $this->validate() with no rules: every #[Validate] property. */
    #[Server]
    public function checkAll(): void
    {
        $this->validate();
        Store::$log[] = 'all valid';
    }

    /** A backed enum from its value, and a service from the container, side by side. */
    #[Server]
    public function grade(Grade $grade, Store $store): void
    {
        Store::$log[] = 'grade '.$grade->value.' '.$store::class;
    }
}
