<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MUL-227 item 30 — contrato de Fulfillment.
 */
class FulfillmentContract extends Model
{
    protected $fillable = [
        'client_id',
        'marketplace',
        'mode',
        'm3_reservado',
        'valor_m3',
        'valor_por_pedido',
        'warehouse_location',
        'status',
        'started_at',
    ];

    protected $casts = [
        'm3_reservado'     => 'decimal:2',
        'valor_m3'         => 'decimal:2',
        'valor_por_pedido' => 'decimal:2',
        'started_at'       => 'datetime',
    ];

    public const MARKETPLACES = ['all', 'mercadolivre', 'shopee', 'amazon', 'magalu', 'tiktok'];
    public const MODES        = ['envio', 'apenas_processamento'];
    public const STATUSES     = ['active', 'paused', 'cancelled'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
