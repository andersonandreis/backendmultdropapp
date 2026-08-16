<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * HUB-131 — Registro de webhooks ja processados (tabela de deduplicacao).
 *
 * Uso:
 *   // Tenta inserir — retorna false se ja existe (duplicata)
 *   $isNew = ProcessedWebhookId::markProcessed('mercadolivre', $notificationId, 'orders_v2');
 *   if (! $isNew) {
 *       return response()->json(['status' => 'duplicate'], 200);
 *   }
 *
 * @property int         $id
 * @property string      $source       'mercadolivre', 'shopee', 'bling'
 * @property string      $external_id  ID unico do evento na plataforma
 * @property string|null $topic        Topico do evento (para debug)
 * @property \Carbon\Carbon $processed_at
 */
class ProcessedWebhookId extends Model
{
    public $timestamps  = false;
    protected $table    = 'processed_webhook_ids';

    protected $fillable = [
        'source',
        'external_id',
        'topic',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    /**
     * Tenta marcar o webhook como processado.
     * Retorna true se e a primeira vez que este evento chega (deve processar).
     * Retorna false se o evento ja foi processado antes (descarta silenciosamente).
     *
     * Usa INSERT IGNORE semantico via firstOrCreate + verificacao de wasRecentlyCreated.
     */
    public static function markProcessed(string $source, string $externalId, ?string $topic = null): bool
    {
        try {
            $record = static::firstOrCreate(
                ['source' => $source, 'external_id' => $externalId],
                ['topic' => $topic, 'processed_at' => now()]
            );

            return $record->wasRecentlyCreated;
        } catch (\Illuminate\Database\QueryException $e) {
            // Duplicate entry (race condition entre workers) — ja foi processado
            if (str_contains($e->getMessage(), 'Duplicate entry') || $e->errorInfo[1] === 1062) {
                return false;
            }
            // Outro erro de banco — deixar passar (fail-open: melhor processar 2x do que perder)
            \Illuminate\Support\Facades\Log::warning('[WebhookDedup] Erro ao verificar dedup — fail-open', [
                'source'      => $source,
                'external_id' => $externalId,
                'error'       => $e->getMessage(),
            ]);
            return true;
        }
    }
}
