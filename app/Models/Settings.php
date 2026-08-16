<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Settings extends Model
{
    protected $fillable = [
        'group',
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];
}
