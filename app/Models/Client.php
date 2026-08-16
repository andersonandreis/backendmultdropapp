<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\MarketplaceAccount;
use App\Services\CurrentTenant;

class Client extends Model
{
    use HasFactory;

    // MUL-269 fase 2: dados pessoais do seller (full_name, responsible_cpf,
    // birth_date, rg, mother_name, father_name, rg_*_file, residence_proof_file)
    // e o antigo company_name vao ser dropados de clients. Ler/escrever agora
    // via users (accessor getCompanyNameAttribute cobre leituras Eloquent).
    protected $fillable = [
        'user_id',
        'document',
        'phone',
        'listing_mode',
        'is_active',
        'pagarme_customer_id',
        'legacy_id_login',
        'service',
        'legacy_sha256_hash',
        'legacy_password_type',
        'auto_pay_from_wallet',
        'auto_pay_min_balance',
        'discount_sales_count',
        'discount_ramp_start',
        'current_discount_percent',
        'first_orders_used',
        'first_orders_bonus_pct',
        'whatsapp_invited_at',
        // MUL: enriquecimento PJ/PF + endereco + bling contact cache
        'person_type',
        'legal_name',
        'trade_name',
        'state_registration',
        'ie_indicator',
        'municipal_registration',
        'nfe_email',
        'address_cep',
        'address_street',
        'address_number',
        'address_complement',
        'address_neighborhood',
        'address_city',
        'address_state',
        // INF-030-MARCA (13/08): marca (seller.global | tokfy) do cliente,
        // gravada na criacao a partir do Origin/Referer do checkout. Ver
        // App\Support\BrandKit::fromRequest().
        'marca',
        'bling_supplier_contact_id',
    ];

    protected $casts = [
        'legacy_id_login' => 'integer',
        'legacy_sha256_hash' => 'string',
        'auto_pay_from_wallet' => 'boolean',
        'auto_pay_min_balance' => 'decimal:2',
        'discount_sales_count' => 'integer',
        'discount_ramp_start' => 'datetime',
        'current_discount_percent' => 'integer',
        'first_orders_used' => 'integer',
        'first_orders_bonus_pct' => 'integer',
        'whatsapp_invited_at' => 'datetime',
    ];

    // SEL-182 pentest fix: nao serializar credenciais migradas nem id gateway
    // pra client. Payload de GET /api/v1/me e /api/v1/profile expunha hash SHA256
    // do legado (util pra ataque cross-system) e pagarme_customer_id (util pra
    // enumeracao de assinaturas). Nada disso precisa vazar pro browser.
    protected $hidden = [
        'legacy_sha256_hash',
        'legacy_password_type',
        'legacy_id_login',
        'pagarme_customer_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * MUL-269 fase 2: company_name deixou de existir na tabela clients.
     * Todo acesso Eloquent a $client->company_name agora resolve
     * pelo user conectado (full_name com fallback name). Escritas
     * viram no-op silencioso (fillable ja nao inclui).
     */
    public function getCompanyNameAttribute(): ?string
    {
        return $this->user
            ? (trim((string) $this->user->full_name) ?: $this->user->name)
            : null;
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function marketplaceAccounts()
    {
        return $this->hasMany(MarketplaceAccount::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Transacoes financeiras entre o client e fornecedores
     * (debitos por pedido, creditos por wallet topup, estornos).
     * Usado pelo painel admin do fornecedor pra montar
     * extrato e volume financeiro do cliente.
     */
    public function clientSupplierTransactions()
    {
        return $this->hasMany(ClientSupplierTransaction::class);
    }

    /**
     * Fornecedores extras vinculados manualmente a este client (alem dos herdados do plano).
     */
    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class, 'client_supplier')
            ->withPivot('is_extra')
            ->withTimestamps();
    }

    /**
     * Apenas fornecedores extras (adicionados manualmente pelo admin).
     */
    public function extraSuppliers()
    {
        return $this->belongsToMany(Supplier::class, 'client_supplier')
            ->withPivot('is_extra')
            ->withTimestamps()
            ->wherePivot('is_extra', true);
    }

    /**
     * Retorna true se o cliente esta no plano Start (max_skus <= 30)
     * ou sem plano ativo. Pro e Scale tem max_skus > 30.
     */
    public function isStartPlan(): bool
    {
        $subscription = $this->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan')
            ->latest()
            ->first();

        if (! $subscription || ! $subscription->plan) {
            return true;
        }

        return (int) $subscription->plan->max_skus <= 30;
    }

    /**
     * Retorna true se o cliente esta no plano Scale (ou superior).
     * Usa slug quando disponivel; fallback para max_skus >= 10000.
     * Sem plano ativo retorna false.
     */
    public function isScalePlan(): bool
    {
        $subscription = $this->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan')
            ->latest()
            ->first();

        if (! $subscription || ! $subscription->plan) {
            return false;
        }

        $plan = $subscription->plan;

        if (! empty($plan->slug)) {
            return in_array($plan->slug, ['scale', 'enterprise', 'plano-completo-teste'], true);
        }

        // Fallback: max_skus >= 10000 identifica Scale/Enterprise
        return (int) $plan->max_skus >= 10000;
    }

    /**
     * Retorna todos os suppliers efetivos visíveis a este client.
     *
     * FOR-029: usa tenant_supplier em vez de plan_supplier.
     * Sem duplicatas.
     */
    public function getEffectiveSuppliersAttribute()
    {
        $currentTenant = app(CurrentTenant::class);
        $tenant = $currentTenant->tenant();

        $clientId = $this->id;

        // Sem tenant ou visibilidade all -> todos os ativos, exceto privados de outros (MUL-219)
        if ($tenant === null || $tenant->default_supplier_visibility === "all") {
            return \App\Models\Supplier::where("is_active", true)
                ->where(function ($q) use ($clientId) {
                    $q->where("is_private", false)
                      ->orWhere(function ($qq) use ($clientId) {
                          $qq->where("is_private", true)->where("owner_client_id", $clientId);
                      });
                })->get();
        }

        // Tenant scoped -> vinculados via tenant_supplier (sem privados de outros)
        // + privados do proprio client (MUL-219)
        $tenantIds = $tenant->suppliers()->pluck("suppliers.id")->all();

        return \App\Models\Supplier::where("is_active", true)
            ->where(function ($q) use ($tenantIds, $clientId) {
                $q->where(function ($qq) use ($tenantIds) {
                    $qq->whereIn("id", $tenantIds)->where("is_private", false);
                })->orWhere(function ($qq) use ($clientId) {
                    $qq->where("is_private", true)->where("owner_client_id", $clientId);
                });
            })->get();
    }
}
