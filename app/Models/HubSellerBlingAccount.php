<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MUL-148B — Model read-only para marketplace_accounts do hub (hub_readonly).
 * Filtra platform=bling e supplier_id=HUB_SUPPLIER_ID via global scope.
 * Sem operações de escrita — tabela é do hubaiapp, não do multdrop.
 */
class HubSellerBlingAccount extends Model
{
    protected $connection = 'hub_readonly';
    protected $table = 'marketplace_accounts';

    public $timestamps = false;

    /**
     * Scope padrão: só contas Bling do supplier configurado no .env.
     * Se HUB_SUPPLIER_ID não estiver definido ou for 0, retorna vazio (sem erro).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('bling_supplier', function ($query) {
            $supplierId = (int) env('HUB_SUPPLIER_ID', 0);

            $query->where('marketplace_accounts.platform', 'bling');

            if ($supplierId > 0) {
                $query->where('marketplace_accounts.supplier_id', $supplierId);
            } else {
                $query->whereRaw('1 = 0');
            }
        });
    }
}
