<?php

namespace App\Models;

use App\Models\Scopes\TenantSupplierScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTopic extends Model
{
    protected $fillable = ['supplier_id', 'department_id', 'name', 'description', 'active'];
    protected $casts = ['active' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantSupplierScope());
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function department(): BelongsTo { return $this->belongsTo(SupportDepartment::class, 'department_id'); }
}
