<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliateWithdrawal extends Model
{
    protected $fillable = [
        'affiliate_id',
        'amount',
        'pix_key',
        'pix_type',
        'status',
        'processed_at',
        'rejection_reason',
        'admin_notes',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }
}
