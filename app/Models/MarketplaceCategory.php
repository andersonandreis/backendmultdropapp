<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceCategory extends Model
{
    protected $fillable = [
        'platform',
        'external_id',
        'name',
        'full_path',
        'parent_external_id',
        'attributes_schema',
        'last_synced_at',
    ];

    protected $casts = [
        'attributes_schema' => 'array',
        'last_synced_at' => 'datetime',
    ];
}
