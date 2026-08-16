<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\ProductQualityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AutoFillProductAttributesJob
 *
 * Para um produto, faz:
 * 1. Descobre categoria ML via domain_discovery (API publica)
 * 2. Busca atributos obrigatorios da categoria ML
 * 3. Preenche ml_attributes com valores de fallback para atributos obrigatorios
 * 4. Calcula quality_score_ml, quality_score_shopee e quality_issues
 * 5. Salva tudo no registro products
 */
class AutoFillProductAttributesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    // Backoff de 30s entre tentativas (rate limit ML)
    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function __construct(
        public readonly int $productId
    ) {}

    public function handle(ProductQualityService $qualityService): void
    {
        $product = Product::find($this->productId);

        if (! $product) {
            Log::warning("[AutoFill] Product #{$this->productId} nao encontrado.");
            return;
        }

        $title = $product->ai_title ?: $product->name;

        if (empty(trim($title ?? ''))) {
            Log::warning("[AutoFill] Product #{$this->productId} sem titulo — pulando ML discovery.");
        } else {
            $this->fillMlAttributes($product, $title);
        }

        // Shopee: salva categoria ja existente ou tenta inferir via atributos ML
        $this->fillShopeeAttributes($product);

        // Calcula quality scores
        $result = $qualityService->calculateProductScore($product->fresh());
        // Para Shopee usamos o mesmo score base (diferenciamos apenas no label da plataforma)
        $product->update([
            'quality_score_ml'     => $result['score'],
            'quality_score_shopee' => $result['score'],
            'quality_issues'       => $result['issues'],
        ]);

        Log::info("[AutoFill] Product #{$product->id} - score: {$result['score']}, issues: " . count($result['issues']));
    }

    // -----------------------------------------------------------------------

    protected function fillMlAttributes(Product $product, string $title): void
    {
        // 1. Descobre categoria ML
        $catId = $product->ml_category_id;

        if (! $catId) {
            $resp = Http::timeout(15)->get('https://api.mercadolibre.com/sites/MLB/domain_discovery/search', [
                'q'     => $title,
                'limit' => 3,
            ]);

            if ($resp->successful() && !empty($resp->json())) {
                $first = $resp->json()[0] ?? null;
                $catId = $first['category_id'] ?? null;

                if ($catId) {
                    $product->update(['ml_category_id' => $catId]);
                    Log::info("[AutoFill] Product #{$product->id} - categoria ML inferida: {$catId}");
                }
            } else {
                Log::warning("[AutoFill] Product #{$product->id} - domain_discovery falhou: " . $resp->status());
            }
        }

        if (! $catId) {
            return;
        }

        // 2. Busca atributos obrigatorios da categoria
        $attrResp = Http::timeout(15)->get("https://api.mercadolibre.com/categories/{$catId}/attributes");

        if (! $attrResp->successful()) {
            Log::warning("[AutoFill] Product #{$product->id} - falha ao buscar atributos da cat {$catId}");
            return;
        }

        $catAttrs = $attrResp->json();

        // 3. Preenche ml_attributes com valores existentes + fallback para obrigatorios
        $existing = is_array($product->ml_attributes) ? $product->ml_attributes : [];
        $indexed  = [];
        foreach ($existing as $attr) {
            if (isset($attr['id'])) {
                $indexed[$attr['id']] = $attr;
            }
        }

        foreach ($catAttrs as $attr) {
            $isRequired = $attr['tags']['required'] ?? false;
            if (! $isRequired) {
                continue;
            }

            $attrId = $attr['id'] ?? null;
            if (! $attrId || isset($indexed[$attrId])) {
                continue;
            }

            // Tenta preencher com dados reais do produto
            $valueName = $this->resolveAttributeValue($attrId, $attr, $product);
            $indexed[$attrId] = [
                'id'         => $attrId,
                'value_name' => $valueName,
                'source'     => 'auto_fill',
            ];
        }

        if (! empty($indexed)) {
            $product->update(['ml_attributes' => array_values($indexed)]);
        }
    }

    protected function resolveAttributeValue(string $attrId, array $attrDef, Product $product): string
    {
        // Tenta valores reais do produto primeiro
        switch ($attrId) {
            case 'BRAND':
                if (!empty($product->brand))      return $product->brand;
                if (!empty($product->model_name)) return $product->model_name;
                break;
            case 'MODEL':
                if (!empty($product->model))      return $product->model;
                if (!empty($product->model_name)) return $product->model_name;
                break;
            case 'GTIN':
                if (!empty($product->ean))  return $product->ean;
                if (!empty($product->gtin)) return $product->gtin;
                break;
        }

        // Usa primeiro value da lista de valores aceitos, ou "Outros"
        $values = $attrDef['values'] ?? [];
        if (!empty($values)) {
            return $values[0]['name'] ?? 'Outros';
        }

        return 'Outros';
    }

    protected function fillShopeeAttributes(Product $product): void
    {
        // Se ja tem shopee_attributes, nao sobrescreve
        if (!empty($product->shopee_attributes)) {
            return;
        }

        // Para Shopee, por ora copiamos os atributos ML como base
        // (os IDs sao diferentes mas o dado e o mesmo: marca, modelo, etc.)
        if (!empty($product->ml_attributes)) {
            // Mapeia para formato Shopee: {attribute_id, attribute_value_list}
            $shopeeAttrs = [];
            foreach ($product->ml_attributes as $attr) {
                $shopeeAttrs[] = [
                    'attribute_name'       => $attr['id'] ?? 'ATTRIBUTE',
                    'attribute_value'      => $attr['value_name'] ?? 'Outros',
                    'source'               => 'auto_fill_from_ml',
                ];
            }
            if (!empty($shopeeAttrs)) {
                $product->update(['shopee_attributes' => $shopeeAttrs]);
            }
        }
    }
}