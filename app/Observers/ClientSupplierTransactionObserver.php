<?php

namespace App\Observers;

use App\Models\ClientSupplierTransaction;
use Illuminate\Support\Facades\Cache;

/**
 * MUL-262: invalida cache do FinancialController quando nova transação é criada.
 * Chaves afetadas: fin:summary:{client}:{supplier} + fin:history:{client}:{supplier}:{days}
 * balance-history usa TTL 120s + limpa apenas os days mais comuns (30/60/90/365).
 */
class ClientSupplierTransactionObserver
{
    public function created(ClientSupplierTransaction $tx): void
    {
        $c = $tx->client_id;
        $s = $tx->supplier_id;
        Cache::forget("fin:summary:{$c}:{$s}");
        foreach ([30, 60, 90, 180, 365] as $days) {
            Cache::forget("fin:history:{$c}:{$s}:{$days}");
        }
    }
}
