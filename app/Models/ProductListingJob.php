<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NOV-072 - Robo de Cadastro v2
 *
 * Representa um item na fila de publicacao de um ClientProduct num marketplace.
 *
 * @property int         $id
 * @property int         $client_id
 * @property int         $marketplace_account_id
 * @property int         $client_product_id
 * @property string      $status  pending|processing|done|failed|skipped
 * @property int         $attempt
 * @property string|null $error_message
 * @property string|null $external_listing_id
 * @property bool        $generate_image
 * @property string      $speed  slow|normal|fast
 */
class ProductListingJob extends Model
{
    protected $table = 'product_listing_jobs';

    protected $fillable = [
        'client_id',
        'marketplace_account_id',
        'client_product_id',
        'status',
        'attempt',
        'error_message',
        'external_listing_id',
        'generate_image',
        'speed',
    ];

    protected $casts = [
        'generate_image' => 'boolean',
        'attempt'        => 'integer',
    ];

    // --- Relacionamentos ---

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function marketplaceAccount(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class);
    }

    public function clientProduct(): BelongsTo
    {
        return $this->belongsTo(ClientProduct::class);
    }

    // --- Scopes ---

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    // --- Transicoes de estado ---

    /** Marca o job como em processamento. */
    public function markProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    /** Marca como concluido com o ID retornado pelo marketplace. */
    public function markDone(string $externalListingId): void
    {
        $this->update([
            'status'              => 'done',
            'external_listing_id' => $externalListingId,
            'error_message'       => null,
        ]);
    }

    /** Incrementa tentativa e marca como failed. */
    public function markFailed(string $error): void
    {
        $this->update([
            'status'        => 'failed',
            'attempt'       => $this->attempt + 1,
            'error_message' => mb_substr($error, 0, 65535),
        ]);
    }

    /** Marca como ignorado (produto ja publicado, duplicata, etc). */
    public function markSkipped(string $reason = 'already_listed'): void
    {
        $this->update([
            'status'        => 'skipped',
            'error_message' => $reason,
        ]);
    }

    // --- Helpers ---

    /**
     * Numero maximo de jobs por ciclo do dispatcher baseado na velocidade configurada.
     * slow: 1/min | normal: 5/min | fast: 20/min
     */
    public static function maxPerCycleForSpeed(string $speed): int
    {
        return match ($speed) {
            'slow'  => 1,
            'fast'  => 20,
            default => 5, // normal
        };
    }
}
