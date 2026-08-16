<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MUL-159 — Model read-only para marketplace_accounts do hub (hub_readonly).
 * Filtra platform IN (bling,shopee,mercadolivre) e supplier_id=HUB_SUPPLIER_ID.
 * Sem operacoes de escrita — tabela pertence ao hubaiapp.
 * Acoes de escrita passam por HTTP bridge (InternalMarketplaceAccountController).
 */
class HubMarketplaceAccount extends Model
{
    protected $connection = 'hub_readonly';
    protected $table      = 'marketplace_accounts';

    public $timestamps = false;

    protected $casts = [
        'token_expires_at'         => 'datetime',
        'ml_token_expires_at'      => 'datetime',
        'bling_token_expires_at'   => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'last_sync_at'             => 'datetime',
        'last_token_refresh_at'    => 'datetime',
        'sync_blocked_at'          => 'datetime',
        'created_at'               => 'datetime',
        'updated_at'               => 'datetime',
        'auto_invoice_enabled'     => 'boolean',
        'only_ready_to_ship'       => 'boolean',
        'needs_reauth'             => 'boolean',
    ];

    /**
     * Scope global: apenas plataformas de marketplace e o supplier configurado.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('marketplace_supplier', function ($query) {
            $supplierId = (int) config('hubai.hub_supplier_id', 0);

            $query->whereIn('marketplace_accounts.platform', ['bling', 'shopee', 'mercadolivre']);

            if ($supplierId > 0) {
                $query->where('marketplace_accounts.supplier_id', $supplierId);
            } else {
                // HUB_SUPPLIER_ID nao definido — retornar vazio (falha segura)
                $query->whereRaw('1 = 0');
            }
        });
    }

    /**
     * Retorna o campo de expiracao de token independente da plataforma.
     */
    public function getTokenExpiresAtAttribute(): ?\Carbon\Carbon
    {
        return $this->ml_token_expires_at
            ?? $this->token_expires_at
            ?? $this->bling_token_expires_at;
    }

    /**
     * Retorna o seller identifier (ml_user_id, shop_id, seller_id ou account_name).
     */
    public function getSellerIdentifierAttribute(): ?string
    {
        return match ($this->attributes['platform'] ?? '') {
            'mercadolivre' => $this->attributes['ml_user_id'] ?? $this->attributes['seller_nickname'] ?? null,
            'shopee'       => $this->attributes['shop_id'] ?? null,
            'bling'        => $this->attributes['seller_id'] ?? $this->attributes['account_name'] ?? null,
            default        => $this->attributes['seller_id'] ?? null,
        };
    }
}
