<?php

namespace Nitro\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Student extends Model
{
    protected $guarded = [];

    protected $hidden = ['secret', 'mentor_id'];

    public $timestamps = false;

    /** @return BelongsTo<self, $this> */
    public function mentor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'mentor_id');
    }
}
