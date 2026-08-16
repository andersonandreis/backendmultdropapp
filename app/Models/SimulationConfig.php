<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SimulationConfig extends Model
{
    protected $fillable = [
        'user_id',
        'slug',
        'revenue_per_month',
        'orders_per_day',
        'store_name',
        'store_link',
        'label_enabled',
        'product_links',
    ];

    protected $casts = [
        'revenue_per_month' => 'decimal:2',
        'orders_per_day'    => 'integer',
        'label_enabled'     => 'boolean',
        'product_links'     => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
