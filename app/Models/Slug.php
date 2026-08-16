<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Slug extends Model
{
    protected $fillable = [
        'sluggable_type',
        'sluggable_id',
        'slug',
        'is_canonical',
    ];

    public function sluggable()
    {
        return $this->morphTo();
    }
}
