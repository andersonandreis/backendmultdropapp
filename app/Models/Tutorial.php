<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tutorial extends Model
{
    protected $fillable = [
        'title',
        'description',
        'video_url',
        'target_page',
        'required_plan_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'required_plan_id');
    }
}
