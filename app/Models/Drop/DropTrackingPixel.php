<?php

namespace App\Models\Drop;

use Illuminate\Database\Eloquent\Model;
use App\Models\Client;

class DropTrackingPixel extends Model
{
    protected $table = 'drop_tracking_pixels';

    protected $fillable = [
        'client_id',
        'platform',
        'pixel_id',
        'access_token',
        'test_event_code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $hidden = ['access_token'];

    // Accessors / Mutators — access_token criptografado em repouso

    public function getAccessTokenAttribute($value): ?string
    {
        if (!$value) {
            return null;
        }
        try {
            return decrypt($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function setAccessTokenAttribute($value): void
    {
        $this->attributes['access_token'] = $value ? encrypt($value) : null;
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Relationships

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
