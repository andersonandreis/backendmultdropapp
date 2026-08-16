<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * NOV-171 — Modelo de auditoria de operacoes de federacao hub<->WL.
 * Existe SOMENTE no hubaiapp (banco hubaiapp).
 * Os WLs nao tem esta tabela — o hub eh o ponto central de auditoria.
 *
 * @property int    $id
 * @property string $direction     hub_to_wl | wl_to_hub
 * @property string $entity_type   product | order | order_status
 * @property int    $entity_id     products.id ou orders.id no hubaiapp
 * @property string $target_tenant Slug do WL (multdrop, fornecefy, mestoredrop)
 * @property string $status        success | failed | skipped
 * @property string|null $payload_hash SHA-256 do payload para dedup
 * @property string|null $error_message
 * @property \Carbon\Carbon $created_at
 */
class FederationSyncLog extends Model
{
    public $timestamps = false;
    protected $table = 'federation_sync_log';

    protected $fillable = [
        'direction',
        'entity_type',
        'entity_id',
        'target_tenant',
        'status',
        'payload_hash',
        'error_message',
    ];

    protected $casts = [
        'entity_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Registra uma operacao de sync com hash de dedup.
     * Se o hash for identico ao ultimo registro para (entity_id, target_tenant), retorna null (skip).
     */
    public static function recordOrSkip(
        string $direction,
        string $entityType,
        int $entityId,
        string $targetTenant,
        string $status,
        ?string $payloadHash = null,
        ?string $errorMessage = null
    ): ?static {
        // Dedup por payload_hash: se igual ao ultimo, nao grava (skip silencioso)
        if ($payloadHash) {
            $exists = static::where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('target_tenant', $targetTenant)
                ->where('payload_hash', $payloadHash)
                ->exists();

            if ($exists) {
                return null;
            }
        }

        return static::create([
            'direction'     => $direction,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'target_tenant' => $targetTenant,
            'status'        => $status,
            'payload_hash'  => $payloadHash,
            'error_message' => $errorMessage,
        ]);
    }
}
