<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BridgeRelayQueue
 *
 * Fila persistente de retry para eventos que falharam no relay ao bridge legado.
 * Processada pelo RetryBridgeRelayJob a cada 5 minutos.
 *
 * Status:
 *   pending     - aguardando processamento (next_try_at no futuro ou passado)
 *   processing  - sendo processado agora (lock otimista)
 *                 usa processing_started_at para detectar stuck > STUCK_THRESHOLD_MIN
 *   done        - relay concluido com sucesso
 *   failed_max  - esgotou MAX_ATTEMPTS tentativas - requer intervencao manual
 *
 * Backoff exponencial (segundos):
 *   tentativa 1 -> 30s
 *   tentativa 2 -> 300s  (5 min)
 *   tentativa 3 -> 1800s (30 min)
 *   tentativa 4 -> 7200s (2 h)
 *   tentativa 5 -> failed_max
 */
class BridgeRelayQueue extends Model
{
    protected $table = 'bridge_relay_queue';

    protected $fillable = [
        'platform',
        'event_type',
        'order_id',
        'legacy_user_id',
        'payload',
        'attempts',
        'next_try_at',
        'last_error',
        'status',
        'processing_started_at',
        'ml_order_id',
        'shopee_order_sn',
    ];

    protected $casts = [
        'payload'               => 'array',
        'next_try_at'           => 'datetime',
        'processing_started_at' => 'datetime',
        'attempts'              => 'integer',
    ];

    /** Maxima de tentativas antes de marcar como failed_max */
    public const MAX_ATTEMPTS = 5;

    /** Minutos para considerar um item 'processing' como stuck (worker crashou) */
    public const STUCK_THRESHOLD_MIN = 10;

    /**
     * Segundos de delay para cada tentativa (backoff exponencial).
     * Indice = numero da tentativa JA realizada (0-based).
     */
    public const BACKOFF_SECONDS = [
        0 => 30,       // 1a falha -> espera 30s
        1 => 300,      // 2a falha -> espera 5min
        2 => 1800,     // 3a falha -> espera 30min
        3 => 7200,     // 4a falha -> espera 2h
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Calcula o proximo next_try_at com base no numero de tentativas atuais.
     */
    public function calcNextTryAt(): \Carbon\Carbon
    {
        $delay = self::BACKOFF_SECONDS[$this->attempts] ?? 7200;
        return now()->addSeconds($delay);
    }

    /**
     * Cria uma entrada na fila de retry para um evento ML que falhou.
     */
    public static function enqueueMLRelay(
        string $topic,
        string $resource,
        string $mlUserId,
        int    $legacyUser,
        array  $payload,
        ?int   $orderId,
        string $error
    ): self {
        $parts     = explode('/', trim($resource, '/'));
        $mlOrderId = end($parts) ?: null;

        return self::create([
            'platform'       => 'mercadolivre',
            'event_type'     => $topic,
            'order_id'       => $orderId,
            'legacy_user_id' => $legacyUser,
            'payload'        => array_merge($payload, [
                '_ml_user_id' => $mlUserId,
                '_resource'   => $resource,
                '_topic'      => $topic,
            ]),
            'ml_order_id'    => $mlOrderId,
            'attempts'       => 0,
            'next_try_at'    => now()->addSeconds(self::BACKOFF_SECONDS[0]),
            'last_error'     => $error,
            'status'         => 'pending',
        ]);
    }

    /**
     * Cria uma entrada na fila de retry para um evento Shopee que falhou.
     */
    public static function enqueueShopeeRelay(
        int    $code,
        int    $shopId,
        array  $payload,
        ?int   $orderId,
        string $error
    ): self {
        $orderSn = $payload['ordersn'] ?? $payload['order_sn'] ?? null;

        return self::create([
            'platform'        => 'shopee',
            'event_type'      => (string) $code,
            'order_id'        => $orderId,
            'legacy_user_id'  => null,
            'payload'         => array_merge($payload, [
                '_code'    => $code,
                '_shop_id' => $shopId,
            ]),
            'shopee_order_sn' => $orderSn,
            'attempts'        => 0,
            'next_try_at'     => now()->addSeconds(self::BACKOFF_SECONDS[0]),
            'last_error'      => $error,
            'status'          => 'pending',
        ]);
    }
}
