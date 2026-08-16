<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ErpAccount — conta de ERP (Bling) do supplier.
 *
 * Pode ser vinculada a supplier (PF/fornecedor sem Client) ou a client (legacy/cliente
 * normal). access_token e refresh_token são salvos criptografados pelo cast `encrypted`
 * (mesmo padrão do MarketplaceAccount).
 *
 * Campo `status`: 'active' | 'disconnected' | 'error' | 'needs_reauth'.
 * O campo legado `is_active` NÃO existe — usar sempre `status`.
 */
class ErpAccount extends Model
{
    protected $fillable = [
        'legacy_id',
        'client_id',
        'supplier_id',
        'platform',
        'account_name',
        'bling_id_loja',
        'bling_seller_id',
        'bling_supplier_contact_id',
        'api_key',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'api_version',
        'status',
        'last_sync_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_sync_at'     => 'datetime',
        'access_token'     => 'encrypted',
        'refresh_token'    => 'encrypted',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function client(): BelongsTo
    {
        // client_id pode ser NULL (contas conectadas só ao supplier).
        return $this->belongsTo(Client::class);
    }

    /**
     * Token está expirado? Se não houver `token_expires_at`, considera expirado
     * por segurança (forçar refresh).
     */
    public function isTokenExpired(): bool
    {
        if (! $this->token_expires_at) {
            return true;
        }
        return $this->token_expires_at->isPast();
    }
}
