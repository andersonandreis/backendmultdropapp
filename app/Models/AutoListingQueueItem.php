<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoListingQueueItem extends Model
{
    protected $fillable = [
        'client_id',
        'marketplace_account_id',
        'product_id',
        'status',
        'generated_title',
        'generated_description',
        'generated_bullet_points',
        'client_product_id',
        'external_listing_id',
        'error_message',
        'attempts',
        'max_attempts',
        'priority',
        'scheduled_at',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'generated_bullet_points' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function marketplaceAccount()
    {
        return $this->belongsTo(MarketplaceAccount::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function clientProduct()
    {
        return $this->belongsTo(ClientProduct::class);
    }

    public function logs()
    {
        return $this->hasMany(AutoListingLog::class, 'queue_item_id');
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessable($query)
    {
        return $query->pending()
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()));
    }

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    // State transitions

    public function markProcessing(): void
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    public function markCompleted(int $clientProductId, ?string $externalId = null): void
    {
        $this->update([
            'status' => 'completed',
            'client_product_id' => $clientProductId,
            'external_listing_id' => $externalId,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->increment('attempts');
        $this->refresh();

        $this->update([
            'status' => $this->attempts >= $this->max_attempts ? 'failed' : 'pending',
            'error_message' => $error,
        ]);
    }

    public function markSkipped(string $reason = 'already_exists'): void
    {
        $this->update([
            'status' => 'skipped',
            'error_message' => $reason,
            'completed_at' => now(),
        ]);
    }
}
