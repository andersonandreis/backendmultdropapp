<?php

namespace App\Models;

use App\Models\Scopes\TenantSupplierScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierBranding extends Model
{
    protected $table = 'supplier_branding';

    protected $fillable = [
        'supplier_id', 'platform_name', 'logo_url', 'favicon_url',
        'primary_color', 'secondary_color', 'accent_color',
        'background_color', 'text_color',
        'contact_email', 'contact_phone', 'custom_css', 'extra',
    ];

    protected $casts = [
        'extra' => 'array',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantSupplierScope());
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
