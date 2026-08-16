<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $fillable = [
        'client_id', 'supplier_id', 'title', 'category', 'priority', 'status',
        'description', 'related_order_id',
        'department_id', 'topic_id', 'operator_user_id',
        'resolved_at', 'closed_at', 'closed_by_user_id', 'first_response_at',
        'rating', 'rating_comment', 'rated_at',
    ];

    protected $casts = [
        'resolved_at'       => 'datetime',
        'closed_at'         => 'datetime',
        'first_response_at' => 'datetime',
        'rated_at'          => 'datetime',
        'rating'            => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(SupportDepartment::class, 'department_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(SupportTopic::class, 'topic_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_user_id');
    }
}
