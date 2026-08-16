<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WebhookConfig extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'security_header',
        'event_field',
        'expected_event_value',
        'customer_email_field',
        'amount_field',
        'subscription_id_field',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name);
            }
        });

        static::updating(function (self $model) {
            if (empty($model->slug) && $model->isDirty('name')) {
                $model->slug = Str::slug($model->name);
            }
        });
    }
}
