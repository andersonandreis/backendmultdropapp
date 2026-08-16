<?php

namespace App\Models\Drop;

use Illuminate\Database\Eloquent\Model;

class StorePaymentGateway extends Model
{
    protected $table = 'store_payment_gateways';

    protected $fillable = [
        'drop_store_id',
        'gateway_type',
        'credentials_enc',
        'pix_key',
        'pix_key_type',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    protected $hidden = ['credentials_enc'];

    public function store()
    {
        return $this->belongsTo(DropStore::class, 'drop_store_id');
    }

    public function getCredentialsAttribute(): array
    {
        if (!$this->credentials_enc) return [];
        try {
            return json_decode(decrypt($this->credentials_enc), true) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function setCredentialsAttribute(array $value): void
    {
        $this->attributes['credentials_enc'] = encrypt(json_encode($value));
    }
}
