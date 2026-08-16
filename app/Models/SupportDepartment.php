<?php

namespace App\Models;

use App\Models\Scopes\TenantSupplierScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportDepartment extends Model
{
    protected $fillable = ['supplier_id', 'name', 'description', 'color', 'active'];
    protected $casts = ['active' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantSupplierScope());
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function topics(): HasMany { return $this->hasMany(SupportTopic::class, 'department_id'); }
}
