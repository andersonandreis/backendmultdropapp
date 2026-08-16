<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenantSupplier;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use \App\Traits\HasSlug, BelongsToTenantSupplier;

    protected $fillable = [
        'legacy_sku_pai_id',
        'supplier_id',
        'sku',
        // MUL-334: identidade do produto no ERP. Sem estar no fillable, o create()
        // descarta em silencio — foi o que aconteceu no 1o teste da publicacao.
        'erp_sku',
        'erp_formato',
        'erp_parent_sku',
        'erp_map_origem',
        'erp_map_confirmado_em',
        'service_sku',
        'name',
        'description',
        'price',
        'cost',
        'gtin',
        'ean',
        'brand',
        'model',
        'model_name',
        'weight_kg',
        'height_cm',
        'width_cm',
        'length_cm',
        'category_id',
        'condition',
        'attributes',
        'warranty_type',
        'warranty_days',
        'warranty_months',
        'video_url',
        'is_active',
        'virtual_stock_qty',
        'safety_margin_stock',
        'zero_out_margin_stock',
        // AI generated content
        'ai_title',
        'ai_description',
        'ai_bullet_points',
        // Shopee integration fields
        'shopee_item_id',
        'shopee_model_id',
        // Fiscal e Conformidade (MUL-161)
        'ncm',
        'origin',
        'origem',
        'inmetro',
        'homologation_number',
        'manufacturer',
        // Quality fields
        'ml_category_id',
        'ml_attributes',
        'shopee_attributes',
        'quality_score_shopee',
        'quality_score_ml',
        'quality_issues',
        // Localização no depósito
        'warehouse_location',
        // Federação hub<->WL
        'hub_product_id',
        'federation_source',
    ];

    protected $casts = [
        'attributes' => 'array',
        'price' => 'decimal:2',
        'cost' => 'decimal:2',
        'weight_kg' => 'decimal:3',
        'is_active' => 'boolean',
        'virtual_stock_qty' => 'integer',
        'safety_margin_stock' => 'integer',
        'zero_out_margin_stock' => 'integer',
        'ai_bullet_points' => 'array',
        'shopee_item_id' => 'integer',
        'shopee_model_id' => 'integer',
        'hub_product_id' => 'integer',
    ];

    /**
     * MUL-198: Regra permanente — produto com price <= 0 NUNCA fica ativo.
     * Cobre todos os pontos de entrada: API, Filament, imports Bling, sync legado.
     * Nao dispara sync (ProductObserver.$disableSync ainda eh respeitado pelo Observer).
     */
    protected static function booted(): void
    {
        static::saving(function (self $product) {
            if ((float) ($product->price ?? 0) <= 0) {
                $product->is_active = false;
            }
        });
    }

    public function kitItems()
    {
        return $this->hasMany(ProductKit::class);
    }

    public function isKit(): bool
    {
        return $this->kitItems()->exists();
    }

    public function getKitStockAttribute(): int
    {
        $items = $this->kitItems()->with('childProduct.inventory')->get();
        if ($items->isEmpty()) {
            return 0;
        }

        return $items->map(function ($item) {
            $childStock = $item->childProduct->inventory()->sum('quantity');
            return $item->quantity > 0 ? intdiv($childStock, $item->quantity) : 0;
        })->min();
    }

    public function getEffectiveStockAttribute()
    {
        // Kit: estoque calculado pelos filhos.
        if ($this->isKit()) {
            return $this->kit_stock;
        }

        // Estoque real = soma do inventory. NAO mascarar com regras de "zera quando <=N" nem
        // inflar com virtual_stock_qty. Pausar anuncio no marketplace e' atribuicao do bot do
        // legado (AtualizarEstoqueMarketplace.php), nao deste atributo.
        // Ver feedback_effective_stock_nao_zera. Bug aplicado 28/05, corrigido 29/05.
        return (int) $this->inventory()->sum('quantity');
    }

    protected static ?array $stockRulesCache = null;

    public static function stockRuleSetting(string $key): int
    {
        if (self::$stockRulesCache === null) {
            self::$stockRulesCache = \Illuminate\Support\Facades\DB::table('settings')
                ->whereIn('key', ['stock_inflation_qty', 'stock_reserve_floor'])
                ->pluck('value', 'key')
                ->toArray();
        }

        return (int) (self::$stockRulesCache[$key] ?? 0);
    }

    public static function resetStockRulesCache(): void
    {
        self::$stockRulesCache = null;
    }

    /**
     * MUL-226-13/14: estoque PUBLICADO nos marketplaces (ML/Shopee) — NUNCA o que vai pro Bling/ERP.
     * Precedencia: 1º reserva (real <= piso => publica 0, preserva unidades pra pedidos em curso),
     * 2º inflacao (SOMA sobre a base: real 102 + inflacao 10.000 = 10.102).
     * Settings zeradas (default) => retorna exatamente o comportamento antigo.
     * NAO altera effective_stock (estoque real — incidente 28/05, feedback_effective_stock_nao_zera).
     */
    public function publishedStock(?int $clientId = null): int
    {
        $real = (int) $this->effective_stock;

        // MUL-234: Threshold 1 — real critico => 0 (pausa efetiva do anuncio)
        $zeroOut = (int) ($this->zero_out_margin_stock ?? 0);
        if ($zeroOut > 0 && $real <= $zeroOut) {
            return 0;
        }

        // MUL-234: Threshold 2 — real na faixa safety => envia real cru (desativa fake)
        $safety = (int) ($this->safety_margin_stock ?? 0);
        if ($safety > 0 && $real <= $safety) {
            return $real;
        }

        // MUL-234: estoque fake POR SELLER dinamico (regra Ruan 2026-07-18).
        // Sem clientId (painel fornecedor / config global) => retorna real cru.
        $virtual = (int) ($this->virtual_stock_qty ?? 0);
        if ($virtual > 0 && $clientId !== null) {
            // Seed deterministica: mesmo (produto, seller, virtual) => sempre mesmo valor.
            // Rotaciona quando fornecedor edita virtual_stock_qty.
            $seed = crc32($this->id . '-' . $clientId . '-' . $virtual);
            $rng = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
            return $rng->getInt(0, intdiv($virtual, 2));
        }

        // Fallback: settings globais legado (mantido pra compat com config antiga)
        $floor = self::stockRuleSetting('stock_reserve_floor');
        if ($floor > 0 && $real <= $floor) {
            return 0;
        }
        $inflation = self::stockRuleSetting('stock_inflation_qty');
        if ($inflation > 0) {
            return $real + $inflation;
        }

        return $real;
    }

    public function getPublishedStockAttribute(): int
    {
        return $this->publishedStock();
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function inventory()
    {
        return $this->hasMany(Inventory::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function variations()
    {
        return $this->hasMany(ProductVariation::class);
    }

    public function media()
    {
        return $this->hasMany(ProductMedia::class);
    }

    public function clientProducts()
    {
        return $this->hasMany(ClientProduct::class);
    }

    /**
     * MUL-318 Parte 2: custo confiavel para pedidos.
     *
     * O catalogo tem placeholders da importacao de 30/06 (86% inativos, 82% com
     * custo suspeito no supplier 30). Derivar custo deles inventava dinheiro:
     * medido em 08/08/2026, 20 itens de produto inativo carregavam R$ 1.420,91 —
     * mais que os 112 itens confiaveis do dia (R$ 1.194,03) — e 17 pedidos
     * ficaram com supplier_total MAIOR que o total.
     *
     * Retorna o price so quando o produto e confiavel (ativo E price > 0);
     * senao null — e o pedido fica sem custo, que e o desejado: o safety net
     * do OrderObserver (MUL-202) segura como rascunho e o guard INF-052
     * bloqueia wallet_paid_at ate o custo existir. Mesmo criterio da trava de
     * derivacao de supplier da Parte 1 (MUL-318, commit 125087e).
     */
    public function custoConfiavel(): ?float
    {
        if ($this->is_active && (float) ($this->price ?? 0) > 0) {
            return (float) $this->price;
        }
        \Illuminate\Support\Facades\Log::warning('[MUL-318] custo recusado — produto nao confiavel', [
            'product_id' => $this->id,
            'sku'        => $this->sku,
            'motivo'     => $this->is_active ? 'preco_zero' : 'produto_inativo',
        ]);
        return null;
    }
}
