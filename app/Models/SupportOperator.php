<?php

namespace App\Models;

use App\Models\Scopes\TenantSupplierScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportOperator extends Model
{
    protected $fillable = ['supplier_id', 'user_id', 'department_ids', 'online', 'active'];
    protected $casts = [
        'department_ids' => 'array',
        'online'         => 'boolean',
        'active'         => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantSupplierScope());
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
