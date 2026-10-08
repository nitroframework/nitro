<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Illuminate\Database\Eloquent\Collection;
use Nitro\Fusion\Attributes\Server;
use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\Fixtures\Components\Store;
use Nitro\Fusion\Tests\Fixtures\Models\Student;
use Nitro\Fusion\Tests\Fixtures\Models\StudentByEmail;

/**
 * Model properties read again on each call: a nullable one whose row may be deleted ($maybe), one
 * that must exist ($sure), a collection of two model classes ($mixed), and models with a relation
 * loaded ($mentee, $everyone), which the view reads with -> in the browser too.
 */
class Pair extends Component
{
    public ?Student $maybe = null;

    public Student $sure;

    public ?Collection $mixed = null;

    public ?Student $mentee = null;

    public ?Collection $everyone = null;

    public function mount(Student $student): void
    {
        $this->maybe = $student;
        $this->sure = Student::findOrFail(1);
        $this->mixed = new Collection([$student, StudentByEmail::findOrFail(1)]);
        $this->mentee = Student::with('mentor')->find($student->id);
        $this->everyone = Student::with('mentor')->orderBy('id')->get();
    }

    #[Server]
    public function ping(): void
    {
        Store::$log[] = ($this->maybe?->name ?? 'gone').' / '.implode(',', $this->mixed->map(fn ($m) => class_basename($m).' '.$m->name)->all());
    }
}
