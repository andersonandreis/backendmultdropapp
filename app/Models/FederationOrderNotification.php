<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * NOV-171 — Registro local de notificacao de pedido recebida do hub via federacao.
 * Existe SOMENTE nos WLs (multdrop, fornecefy, mestoredrop).
 * NAO eh uma copia de orders — os WLs nao tem tabela orders.
 * Representa o pedido no painel do WL para exibicao e controle de status.
 * hub_delivery_id (UNIQUE) garante idempotencia: mesmo webhook nao cria duplicata.
 *
 * WL MANDA no status: quando operador altera status aqui,
 * o WL chama POST api.hubai.io/api/federation/orders/{hub_order_id}/status.
 *
 * @property int    $id
 * @property int    $hub_order_id    orders.id no hubaiapp
 * @property string $hub_delivery_id UUID do DispatchWebhookJob (dedup)
 * @property string $origin_tenant   Slug do WL de origem (multdrop, fornecefy, etc.)
 * @property int|null $client_id     clients.id local se mapeado
 * @property array  $payload         Payload completo do webhook
 * @property string $status          pending | processing | done | failed
 * @property \Carbon\Carbon|null $processed_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class FederationOrderNotification extends Model
{
    protected $fillable = [
        'hub_order_id',
        'hub_delivery_id',
        'origin_tenant',
        'client_id',
        'payload',
        'status',
        'processed_at',
    ];

    protected $casts = [
        'hub_order_id' => 'integer',
        'client_id'    => 'integer',
        'payload'      => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * Cria notificacao se ainda nao existe para este hub_delivery_id (dedup).
     * Retorna false se ja existia (duplicata ignorada).
     */
    public static function createIfNew(array $attributes): bool
    {
        $exists = static::where('hub_delivery_id', $attributes['hub_delivery_id'])->exists();

        if ($exists) {
            return false;
        }

        static::create($attributes);

        return true;
    }

    /**
     * Marca como processada com timestamp.
     */
    public function markDone(): void
    {
        $this->update([
            'status'       => 'done',
            'processed_at' => now(),
        ]);
    }

    /**
     * Marca como falha.
     */
    public function markFailed(): void
    {
        $this->update(['status' => 'failed']);
    }
}
