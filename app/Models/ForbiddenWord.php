<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForbiddenWord extends Model
{
    protected $fillable = [
        'word',
        'context',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
