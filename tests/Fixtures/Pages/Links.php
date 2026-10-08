<?php

namespace Nitro\Fusion\Tests\Fixtures\Pages;

use Illuminate\Support\Str;
use Nitro\Fusion\Attributes\LazyLoad;
use Nitro\Fusion\Component;
use Nitro\Fusion\Tests\Fixtures\Models\Student;

/**
 * Laravel's helpers in a component: route() (named, positional, optional, a binding field, a
 * model, the query string, relative), url(), asset(), __() with replacements, config(). The
 * browser must print what Laravel prints. In app.js although it is a page (BuildTest).
 */
#[LazyLoad(false)]
class Links extends Component
{
    public int $studentId = 1;

    public string $name = 'ada lovelace';

    public string $slug = 'hello world';

    public ?Student $student = null;

    public function rename(): void
    {
        $this->name = 'grace hopper';
        $this->studentId++;
    }

    /** route() in a browser method. */
    public function nextStudent(): string
    {
        return route('students.show', ['student' => $this->studentId + 1, 'from' => $this->name]);
    }

    /** Str, through the use statement, in a browser method. */
    public function handle(): string
    {
        return Str::headline(Str::camel($this->name)).' #'.Str::slug($this->slug.' @ café');
    }

    /** preg_*, with $matches (a reference), in a browser method. */
    public function initials(): string
    {
        $found = preg_match_all('/\b(\w)(\w*)/u', $this->name, $words, PREG_SET_ORDER);
        $first = preg_match('/^(?<first>\w+)\s/', $this->name, $matches) ? $matches['first'] : '-';

        return $found.':'.implode('', array_map(fn ($word) => strtoupper($word[1]), $words)).':'.$first;
    }
}
