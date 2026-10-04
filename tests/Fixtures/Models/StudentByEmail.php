<?php

namespace Nitro\Fusion\Tests\Fixtures\Models;

/**
 * A student bound by email instead of id (getRouteKeyName()), as a route or a #[Server]
 * parameter resolves it.
 */
class StudentByEmail extends Student
{
    protected $table = 'students';

    public function getRouteKeyName(): string
    {
        return 'email';
    }
}
