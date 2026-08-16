<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historico de impressao de etiquetas em lote (MUL-043 / NOV-075).
 *
 * Cada registro representa um lote de etiquetas impresso pelo painel
 * do fornecedor (picking). Permite auditoria e re-impressao.
 */
class LabelPrintLog extends Model
{
    protected $fillable = [
        'supplier_id',
        'user_id',
        'order_ids',
        'batch_size',
        'marketplace',
        'printer_type',
        'printed_at',
    ];

    protected $casts = [
        'order_ids'  => 'array',
        'printed_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}