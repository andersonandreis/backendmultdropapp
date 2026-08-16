<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientKit extends Model
{
    protected $fillable = ['client_id', 'name', 'sku', 'description', 'price', 'is_active', 'legacy_kit_id', 'source_tenant'];

    protected $casts = [
        'price'     => 'decimal:2',
        'is_active' => 'boolean',
        'legacy_kit_id' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClientKitItem::class, 'kit_id');
    }
}
