<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historico auditavel de transicoes de status de pedido.
 *
 * MES-046-B: criado para desacoplar o bip do legado e prover auditoria
 * de quem mudou o status de qual pedido, quando e por qual origem.
 */
class OrderStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'order_status_history';

    protected $fillable = [
        'order_id',
        'field',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'origin',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    // ---------------------------------------------------------------------------
    // Relacoes
    // ---------------------------------------------------------------------------

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // ---------------------------------------------------------------------------
    // Factory helper — ponto unico de gravacao de historico
    // ---------------------------------------------------------------------------

    /**
     * Grava uma linha de historico sem lancar excecao.
     *
     * @param Order       $order
     * @param string      $field        Campo que mudou ('order_processing_status' ou 'status')
     * @param string|null $fromStatus
     * @param string|null $toStatus
     * @param string      $origin       bip | api | webhook | legacy_sync | observer
     * @param array       $metadata     Dados extras
     * @param string|null $actorId      ID do usuario/sistema responsavel
     * @param string      $actorType    bip | system | supplier | api
     */
    public static function record(
        Order $order,
        string $field,
        ?string $fromStatus,
        ?string $toStatus,
        string $origin = 'observer',
        array $metadata = [],
        ?string $actorId = null,
        string $actorType = 'system'
    ): void {
        try {
            self::create([
                'order_id'    => $order->id,
                'field'       => $field,
                'from_status' => $fromStatus,
                'to_status'   => $toStatus,
                'actor_type'  => $actorType,
                'actor_id'    => $actorId,
                'origin'      => $origin,
                'metadata'    => $metadata ?: null,
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[OrderStatusHistory] Falha ao gravar historico', [
                'order_id'  => $order->id,
                'field'     => $field,
                'to_status' => $toStatus,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
