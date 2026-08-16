<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanDiscount extends Model
{
    protected $fillable = [
        'plan_id',
        'discount_type',
        'discount_value',
        'billing_cycle',
        'is_active',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'discount_value' => 'decimal:2',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}
