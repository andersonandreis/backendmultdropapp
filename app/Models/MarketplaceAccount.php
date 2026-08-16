<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenantSupplier;
use Illuminate\Database\Eloquent\Model;

class MarketplaceAccount extends Model
{
    use BelongsToTenantSupplier;
    protected $fillable = [
        'client_id',
        'supplier_id',
        'account_name',
        'platform',
        'app_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'refresh_token_expires_at',
        'seller_id',
        'seller_nickname',
        'shop_id',
        'status',
        'centrally_managed', // NOV-181: cadeia de tokens pertence ao hub central (WL so espelha)
        'service',         // NOV-046-H: identifica tenant de origem (hubai, multdrop, fornecefy)
        'wl_client_id',
        'wl_client_name',   // FOR-127
        'wl_client_email',  // FOR-127    // MUL-188: client_id da conta espelho na WL de origem (push pos-renovacao Bling)
        'import_mode',
        'pricing_strategy',
        'price_margin',
        'tax_percentage',
        'marketplace_commission',
        'marketplace_fixed_fee',
        'marketplace_shipping_fee',
        'last_sync_at',
        'last_token_refresh_at',
        'sync_errors_count',
        // ML OAuth PKCE
        'ml_user_id', 'identification_type', 'identification_number',
        'ml_access_token',
        'ml_refresh_token',
        'ml_token_expires_at',
        // IA
        'ai_instructions',
        // Bloqueio de sync por erro de conta
        'sync_blocked_at',
        // Bling OAuth
        'bling_access_token',
        'bling_refresh_token',
        'bling_token_expires_at',
        'bling_images_mode',
        // MUL-082: import filters
        'data_inicial_import',
        'allowed_integrations',
        // MUL-095: Shopee Selo Indicado
        'shop_tier',
        'is_indicated',
        'shop_tier_synced_at',
        // MUL-107: cache ID contato fornecedor no Bling do lojista
        'bling_supplier_contact_id',
        // SEL-047: TikTok Shop -- ponteiro para tiktok_shop_connections.id
        'tiktok_connection_id',
        'needs_reauth',
        'is_token_broken',
        'token_broken_reason',
        'token_broken_at',
        // SEL-357: espelho readonly LGPD
        'mirror_mode',
        'mirror_source_backend',
        'mirror_source_client_id',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'last_token_refresh_at' => 'datetime',
        'ml_token_expires_at' => 'datetime',
        'sync_blocked_at'     => 'datetime',
        'bling_token_expires_at' => 'datetime',
        // MUL-095
        'shop_tier_synced_at' => 'datetime',
        'is_indicated'        => 'boolean',
        // NOV-181
        'centrally_managed'   => 'boolean',
        // MUL-082
        'data_inicial_import' => 'date',
        'allowed_integrations' => 'array',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * SEL-078: Retorna o campo de expiracao de token independente da plataforma
     * (compat com o antigo HubMarketplaceAccount). Usado pelo MarketplaceAccountResource.
     */
    public function getTokenExpiresAtAttribute(): ?\Carbon\Carbon
    {
        $mlExp    = $this->attributes['ml_token_expires_at']    ?? null;
        $tokenExp = $this->attributes['token_expires_at']       ?? null;
        $blingExp = $this->attributes['bling_token_expires_at'] ?? null;

        $raw = $mlExp ?? $tokenExp ?? $blingExp;
        return $raw ? \Carbon\Carbon::parse($raw) : null;
    }

    /**
     * SEL-078: identificador principal por plataforma (compat HubMarketplaceAccount).
     */
    public function getSellerIdentifierAttribute(): ?string
    {
        return match ($this->attributes['platform'] ?? '') {
            'mercadolivre' => $this->attributes['ml_user_id']     ?? $this->attributes['seller_nickname'] ?? null,
            'shopee'       => $this->attributes['shop_id']        ?? null,
            'bling'        => $this->attributes['seller_id']      ?? $this->attributes['account_name']  ?? null,
            default        => $this->attributes['seller_id']      ?? null,
        };
    }
}
