<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenantSupplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductBundle extends Model
{
    use BelongsToTenantSupplier;

    protected $fillable = [
        'supplier_id',
        'name',
        'sku',
        'ean',
        'price',
        'stock',
        'weight',
        'description',
        'legacy_kit_id',
        'cover_image_url',
        'is_active',
        'parent_product_id',
        'component_product_id',
        'qty',
    ];

    protected $casts = [
        'price'     => 'decimal:2',
        'weight'    => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // Relacao pai do kit: produto "produto pai" (quando bundle herda de um product)
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    // Relacao componente (quando bundle e um par produto-componente)
    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    // Imagens do kit
    public function media(): HasMany
    {
        return $this->hasMany(ProductBundleMedia::class, 'product_bundle_id')
                    ->orderBy('ordem');
    }

    // Outros componentes do mesmo kit (todos os pares com mesmo parent_product_id)
    public function siblings(): HasMany
    {
        return $this->hasMany(static::class, 'parent_product_id', 'parent_product_id');
    }
}
