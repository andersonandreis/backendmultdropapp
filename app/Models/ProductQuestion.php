<?php

namespace App\Models;

use App\Models\Scopes\TenantSupplierScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductQuestion extends Model
{
    protected $fillable = [
        'product_id', 'client_id', 'supplier_id',
        'question', 'answer', 'answered_by_user_id', 'answered_at', 'is_public',
        'marketplace_account_id', 'marketplace', 'marketplace_question_id',
        'marketplace_item_id', 'buyer_name', 'buyer_external_id',
        'status', 'asked_at', 'failure_reason',
    ];

    protected $casts = [
        'answered_at' => 'datetime',
        'asked_at'    => 'datetime',
        'is_public'   => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantSupplierScope());
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function marketplaceAccount(): BelongsTo { return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id'); }

    public function scopeAnswered($q) { return $q->whereNotNull('answer'); }
    public function scopePending($q)  { return $q->where(function($qq){ $qq->where('status','pending')->orWhereNull('answer'); }); }
    public function scopePublic($q)   { return $q->where('is_public', true); }
    public function scopeFromMarketplace($q, ?string $mp = null) {
        return $mp ? $q->where('marketplace', $mp) : $q->whereNotNull('marketplace');
    }
}
