<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientKitItem extends Model
{
    protected $fillable = ['kit_id', 'client_product_id', 'product_variation_id', 'quantity'];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(ClientKit::class, 'kit_id');
    }

    public function clientProduct(): BelongsTo
    {
        return $this->belongsTo(ClientProduct::class);
    }
}
