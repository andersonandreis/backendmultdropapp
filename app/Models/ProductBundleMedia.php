<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBundleMedia extends Model
{
    protected $fillable = ['product_bundle_id', 'url', 'ordem'];

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(ProductBundle::class, 'product_bundle_id');
    }
}
