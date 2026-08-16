<?php

namespace App\Models;

use App\Enums\EmailStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    protected $fillable = [
        'user_id',
        'email_type',
        'to_email',
        'token',
        'status',
        'sent_at',
        'opened_at',
        'opened_count',
        'clicked_at',
        'click_count',
        'clicked_link',
        'failed_reason',
    ];

    protected function casts(): array
    {
        return [
            'token'      => 'string',
            'status'     => EmailStatus::class,
            'sent_at'    => 'datetime',
            'opened_at'  => 'datetime',
            'clicked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * URL do pixel de rastreamento 1x1 para detectar abertura do e-mail.
     */
    public function trackingPixelUrl(): string
    {
        return url("/email/track/open/{$this->token}");
    }

    /**
     * Envolve um link original com o rastreador de cliques.
     */
    public function trackedUrl(string $url): string
    {
        return url('/email/track/click/' . $this->token . '?url=' . urlencode($url));
    }
}
