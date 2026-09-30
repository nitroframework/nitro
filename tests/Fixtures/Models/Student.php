<?php

namespace Nitro\Fusion\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $guarded = [];

    protected $hidden = ['secret'];

    public $timestamps = false;
}
