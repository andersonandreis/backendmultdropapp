<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenantSupplier;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use BelongsToTenantSupplier;
    protected $fillable = [
        'tenant_id',
        'client_id',
        'canonical_status',
        'supplier_id',
        'order_number',
        'source',
        'external_order_id',
        'external_pack_id',
        'external_shipping_id',
        'buyer_id',
        'buyer_nickname',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_document_type',
        'customer_document_number',
        'customer_address',
        'supplier_total',
        'subtotal',
        'shipping_cost',
        'marketplace_fee',
        'platform_fee',
        'discount_amount',
        'total',
        'currency',
        'status',
        'is_draft',
        'draft_reason',
        'enrich_attempts',
        'last_enriched_at',
        'order_processing_status',
        'cancel_reason',
        'shipping_mode',
        'tracking_number',
        'tracking_url',
        'label_url',
        'manual_label_path',
        'manual_label_uploaded_at',
        'carrier_name',
        'invoice_number',
        'invoice_series',
        'invoice_access_key',
        'invoice_issued_at',
        'paid_at',
        'wallet_paid_at',
        'wallet_transaction_id',
        'label_printed_at',
        'label_status_reason',
        'label_error_at',
        'separated_at',
        'shipped_at',
        'delivered_at',
        'cancelled_at',
        'invoice_url',
        'invoice_xml_url',
        'invoice_status',
        'invoice_xml',
        'packing_photo_url',
        'return_code',
        'return_status',
        'manual_reason',
        'manual_created_by',
        'channel_name',
        'delivery_type',
        'notes',
        'seller_notes',
        'admin_note', // NOV-207 E3
        'legacy_id',
        'hubai_order_id',
        'hubai_client_id',
        'wl_seller_name',  // FOR-127
        'payment_external_id',  // FOR-130
        'payment_method',
        'payment_gateway',
        'captured_amount',
        'captured_at',
        'capture_source',
        'capture_payload',
        'bling_order_id',
        'bling_order_number',
        'bling_payload',
        'expedition_note',
        'expedition_note_read_at',
        'expedition_note_read_by',
        'nfe_entrada_status',
        'nfe_entrada_received_at',
        'nfe_entrada_updated_at',
        'nfe_entrada_access_key',
        'nfe_entrada_pdf_url',
        'nfe_entrada_xml_url',
        'tenant_slug',
        'origin_tenant_slug',
        'marketplace_account_id',
        'marketplace_order_id',
        'shop_id',
        'buyer_username',
        'raw_payload',
        'stock_decremented_at',
        'blocked_at',
        'blocked_by',
        'block_reason',
        // SEL-171: bonus catalogo (3 primeiras vendas com 50% off silencioso)
        'catalog_bonus_applied',
        'catalog_original_price',
        'catalog_discounted_price',
        'pending_subsidy_amount',
        // MUL-237: data real da venda no marketplace (epoch->UTC)
        'marketplace_created_at',
    ];

    protected $casts = [
        'customer_address' => 'array',
        'supplier_total' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'marketplace_fee' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'manual_created_by' => 'integer',
        'legacy_id' => 'integer',
        'hubai_order_id' => 'integer',
        'invoice_issued_at' => 'datetime',
        'blocked_at' => 'datetime',
        'paid_at' => 'datetime',
        'wallet_paid_at' => 'datetime',
        'wallet_transaction_id' => 'integer',
        'label_printed_at' => 'datetime',
        'manual_label_uploaded_at' => 'datetime',
        'separated_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'raw_payload' => 'array',
        'shop_id' => 'integer',
        'marketplace_account_id' => 'integer',
        'stock_decremented_at' => 'datetime',
        'is_draft' => 'boolean',
        'enrich_attempts' => 'integer',
        'last_enriched_at' => 'datetime',
        // SEL-171
        'catalog_bonus_applied' => 'boolean',
        'catalog_original_price' => 'decimal:2',
        'catalog_discounted_price' => 'decimal:2',
        'pending_subsidy_amount' => 'decimal:2',
        // MUL-237
        'marketplace_created_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->order_number)) {
                $prefix = static::prefixoDeOrigem($order);

                // Formato: PREFIXO-AAMMDD-XXXX  (ex.: MUL-260806-A1B2)
                $datePart = date('ymd');
                $randomPart = strtoupper(substr(uniqid(), -4));
                $order->order_number = "{$prefix}-{$datePart}-{$randomPart}";
            }
        });
    }

    /**
     * MUL-339 — o prefixo do numero marca a ORIGEM do pedido, nao o destino.
     *
     * Regra: uma loja conectada no Fornecefy que vende produto do JTDrop gera um pedido FOR. O
     * destino — qual fornecedor entrega e para qual WL o pedido e encaminhado — e resolvido
     * depois, pela cadeia do SKU, e nao tem nada a ver com a nomenclatura.
     *
     * Ate 06/08/2026 o prefixo saia de `suppliers.prefix`, que e o DESTINO. Coincidia enquanto
     * cada WL tinha um fornecedor principal, e quebrava quando o fornecedor nao resolvia: o
     * fallback carimbava 'HUB' num pedido que nunca foi do hub. Mediu-se 541 pedidos do MultDrop
     * assim, entre 14/07 e 02/08.
     *
     * A origem ja era conhecida: `marketplace_accounts.service` guarda de qual WL a loja veio, e
     * o resolveTenantSlug ja o usa como primeiro criterio.
     *
     * Ordem de resolucao:
     *   1. tenant da origem da conta  (service -> tenants.order_prefix)
     *   2. prefixo do fornecedor      (comportamento antigo, para conta sem service)
     *   3. 'HUB'                      (ultimo recurso; pedido nativo do hub tambem cai aqui,
     *                                  e nesse caso esta correto)
     */
    protected static function prefixoDeOrigem($order): string
    {
        // 1. o tenant do pedido — ja resolvido pelo resolveTenantSlug, que considera o service da
        //    conta quando ele diz algo e o fornecedor quando nao diz. E o campo mais confiavel:
        //    nos ultimos 7 dias, sempre que tenant e prefixo discordaram, o tenant estava certo.
        if (! empty($order->tenant_slug)) {
            $prefixo = \Illuminate\Support\Facades\DB::table('tenants')
                ->where('slug', $order->tenant_slug)
                ->value('order_prefix');

            if ($prefixo) {
                return $prefixo;
            }
        }

        // 2. a origem da loja, quando ela declara uma. 'hubai' nao conta: e o valor de quem foi
        //    conectada pelo hub, nao de quem veio de um WL — o resolveTenantSlug o ignora do
        //    mesmo jeito. Contas do MultDrop tem service='hubai', entao usar isso as carimbaria
        //    como HUB.
        if ($order->marketplace_account_id) {
            $origem = \Illuminate\Support\Facades\DB::table('marketplace_accounts')
                ->where('id', $order->marketplace_account_id)
                ->value('service');

            if ($origem && $origem !== 'hubai') {
                $prefixo = \Illuminate\Support\Facades\DB::table('tenants')
                    ->where('slug', $origem)
                    ->value('order_prefix');

                if ($prefixo) {
                    return $prefixo;
                }
            }
        }

        if ($order->supplier_id) {
            $prefixo = \Illuminate\Support\Facades\DB::table('suppliers')
                ->where('id', $order->supplier_id)
                ->value('prefix');

            if ($prefixo) {
                return $prefixo;
            }
        }

        return 'HUB';
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function events()
    {
        return $this->hasMany(OrderEvent::class)->orderBy('created_at', 'desc');
    }

    public function labelQueue()
    {
        return $this->hasOne(OrderLabelQueue::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    // MUL-198: lastro do pagamento do seller — debito na wallet ou PIX direto do pedido
    public function walletTransaction()
    {
        return $this->belongsTo(ClientSupplierTransaction::class, 'wallet_transaction_id');
    }

    public function pixTransactions()
    {
        return $this->hasMany(PixTransaction::class, 'order_id');
    }

    public function marketplaceAccount()
    {
        return $this->belongsTo(MarketplaceAccount::class);
    }

    // Supplier Core — Tenant API (sistema externo).
    // Pedido NAO tem dono tenant. Tenant ve pedido via supplier_id IN (tenant_supplier).
    public function scopeForTenant($q, string $tenantId)
    {
        return $q->whereIn('supplier_id', function ($sub) use ($tenantId) {
            $sub->select('supplier_id')
                ->from('tenant_supplier')
                ->where('tenant_id', $tenantId);
        });
    }

    public function scopeForTenantSlug($q, string $slug)
    {
        $uuid = Tenant::where('slug', $slug)->value('id');
        if (!$uuid) return $q->whereRaw('1=0');
        return $q->whereIn('supplier_id', function ($sub) use ($uuid) {
            $sub->select('supplier_id')
                ->from('tenant_supplier')
                ->where('tenant_id', $uuid);
        });
    }

    /**
     * MUL-378: definicao UNICA de "pedido pago, com etiqueta, ainda nao enviado" —
     * o trabalho que existe de verdade no picking/packing.
     *
     * Cada tela tinha a sua versao, e todas erravam por filtrar `orders.status`
     * (status cru do marketplace) em vez das condicoes reais. Medido em 17/08/2026:
     *   Central de Pedidos ... 12.811 linhas, 12.805 SEM etiqueta
     *   Monitor de Separacao . 12.450 linhas, 12.445 sem etiqueta, 87 ja enviados
     *   Imprimir Etiquetas ...    546 linhas,   430 JA ENVIADOS, 466 cancelados/entregues
     *   verdade ..............    131 pedidos
     *
     * Por que cada condicao:
     *   is_draft=0          rascunho nao e pedido.
     *   label_url           sem etiqueta o fornecedor nao tem o que despachar.
     *   shipped_at IS NULL  nao repetir trabalho ja feito.
     *   paid_at             pago no marketplace (≠ wallet_paid_at, que e o pago AO fornecedor).
     *   blocked_at IS NULL  MUL-226-08: pedido bloqueado sai da fila.
     *   canonical_status    'status' cru nao serve: Shopee manda 'processed', e 'paid'
     *                       cru abriga 12.683 pedidos sem etiqueta. cancelado/entregue
     *                       ficam de fora porque o marketplace nao aceita mais o envio.
     *
     * shipped_at sozinho NAO da conta de "nao enviado": 278 pedidos ja
     * entregues/enviados estao com shipped_at nulo (importacao antiga nao preencheu).
     * E por isso que canonical_status entra junto.
     */
    public function scopeReadyToShip($q)
    {
        return $q->where('orders.is_draft', false)
            ->whereNotNull('orders.label_url')->where('orders.label_url', '<>', '')
            ->whereNull('orders.shipped_at')
            ->whereNotNull('orders.paid_at')
            ->whereNull('orders.blocked_at')
            ->whereIn('orders.canonical_status', ['paid', 'processing']);
    }
}
