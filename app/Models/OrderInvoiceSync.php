<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MUL-454: estado da cadeia automatica de NF do seller por pedido.
 * attempts so cresce em FALHA; status: pending | resolved | failed.
 */
class OrderInvoiceSync extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_checked_at' => 'datetime',
        'alerted_at'      => 'datetime',
    ];
}
