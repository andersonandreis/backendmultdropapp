<?php

namespace App\Services\Drop;

use App\Models\Drop\ImportedProduct;
use App\Models\Drop\SupplierProduct;
use App\Services\Drop\Suppliers\CJDropshippingConnector;
use App\Services\Drop\Suppliers\AliExpressConnector;
use App\Services\Drop\Suppliers\Contracts\SupplierConnectorInterface;
use Illuminate\Support\Facades\Log;

/**
 * Drop Internacional — Fase 2
 * Servico de mineracao de produtos de fornecedores externos.
 *
 * Responsavel por:
 *  - Pesquisar produtos via conector do fornecedor e persistir como SupplierProduct
 *  - Importar um SupplierProduct para uma loja Drop como rascunho de ImportedProduct
 *  - Aplicar regra de precificacao automaticamente na importacao
 */
class SupplierProductMiningService
{
    public function __construct(
        private DropPricingService $pricing
    ) {}

    /**
     * Pesquisar produtos de um fornecedor e persistir os resultados como SupplierProduct.
     *
     * Upsert por (client_id, supplier_slug, external_id): atualiza last_fetched_at
     * e os campos de produto se o item ja existir, mantendo dados frescos.
     *
     * @param  int     $clientId      ID do cliente (lojista)
     * @param  string  $supplierSlug  Slug do fornecedor (ex: 'cj')
     * @param  array   $filters       Filtros: query, category, page, per_page
     * @return SupplierProduct[]
     */
    public function search(int $clientId, string $supplierSlug, array $filters): array
    {
        $connector = $this->resolveConnector($supplierSlug);

        $rawProducts = $connector->searchProducts($filters);

        if (empty($rawProducts)) {
            Log::info('SupplierProductMiningService: nenhum produto retornado', [
                'client_id'     => $clientId,
                'supplier_slug' => $supplierSlug,
                'filters'       => $filters,
            ]);
            return [];
        }

        $results = [];

        foreach ($rawProducts as $raw) {
            $externalId = (string) ($raw['external_id'] ?? '');
            if ($externalId === '') {
                continue;
            }

            $sp = SupplierProduct::updateOrCreate(
                [
                    'client_id'     => $clientId,
                    'supplier_slug' => $supplierSlug,
                    'external_id'   => $externalId,
                ],
                [
                    'title'           => $raw['title'] ?? '',
                    'description'     => $raw['description'] ?? null,
                    'images'          => $raw['images'] ?? null,
                    'variants'        => $raw['variants'] ?? null,
                    'cost_usd'        => $raw['cost_usd'] ?? 0,
                    'shipping_usd'    => $raw['shipping_usd'] ?? 0,
                    'estimated_days'  => $raw['estimated_days'] ?? null,
                    'rating'          => $raw['rating'] ?? null,
                    'sales_count'     => $raw['sales_count'] ?? null,
                    'supplier_url'    => $raw['supplier_url'] ?? null,
                    'category'        => $raw['category'] ?? null,
                    'risk_score'      => $raw['risk_score'] ?? 0,
                    'last_fetched_at' => now(),
                ]
            );

            $results[] = $sp;
        }

        Log::info('SupplierProductMiningService: mining concluido', [
            'client_id'     => $clientId,
            'supplier_slug' => $supplierSlug,
            'found'         => count($rawProducts),
            'persisted'     => count($results),
        ]);

        return $results;
    }

    /**
     * Importar um SupplierProduct para uma loja Drop como rascunho de ImportedProduct.
     *
     * - Se o produto nao tiver variantes, busca detalhes completos via API.
     * - Cria o ImportedProduct com status 'draft'.
     * - Aplica automaticamente a regra de precificacao do cliente.
     *
     * @param  SupplierProduct  $product     Produto minerado a ser importado
     * @param  int              $dropStoreId ID da loja Drop de destino
     * @return ImportedProduct  Rascunho criado (nao publicado no Shopify)
     */
    public function importToStore(SupplierProduct $product, int $dropStoreId): ImportedProduct
    {
        $connector = $this->resolveConnector($product->supplier_slug);

        $costUsd     = (float) $product->cost_usd;
        $shippingUsd = (float) $product->shipping_usd;
        $images      = $product->images ?? [];
        $variants    = $product->variants ?? [];
        $description = $product->description;

        // Enriquecer com dados completos se ainda nao temos variantes
        if (empty($variants) && $product->external_id) {
            $full = $connector->getProduct($product->external_id);
            if (! empty($full)) {
                $costUsd     = $full['cost_usd'] ?? $costUsd;
                $shippingUsd = $full['shipping_usd'] ?? $shippingUsd;
                $images      = ! empty($full['images']) ? $full['images'] : $images;
                $variants    = $full['variants'] ?? $variants;
                $description = $full['description'] ?? $description;

                // Atualiza SupplierProduct com dados enriquecidos
                $product->update([
                    'cost_usd'        => $costUsd,
                    'shipping_usd'    => $shippingUsd,
                    'images'          => $images,
                    'variants'        => $variants,
                    'description'     => $description,
                    'estimated_days'  => $full['estimated_days'] ?? $product->estimated_days,
                    'last_fetched_at' => now(),
                ]);
            }
        }

        $imported = ImportedProduct::create([
            'client_id'            => $product->client_id,
            'drop_store_id'        => $dropStoreId,
            'supplier_slug'        => $product->supplier_slug,
            'external_supplier_id' => $product->external_id,
            'title'                => $product->title,
            'description'          => $description,
            'images'               => $images,
            'variants_data'        => $variants,
            'cost_usd'             => $costUsd,
            'shipping_usd'         => $shippingUsd,
            'currency'             => 'USD',
            'status'               => 'draft',
        ]);

        // Aplicar regra de precificacao automaticamente
        $rule     = $this->pricing->getActiveRule($product->client_id, $product->supplier_slug);
        $imported = $this->pricing->applyRuleToProduct($imported, $rule);
        $imported->save();

        Log::info('SupplierProductMiningService: produto importado', [
            'client_id'           => $product->client_id,
            'drop_store_id'       => $dropStoreId,
            'imported_product_id' => $imported->id,
            'supplier_slug'       => $product->supplier_slug,
            'external_id'         => $product->external_id,
            'sell_price'          => $imported->sell_price,
        ]);

        return $imported;
    }

    /**
     * Resolver conector de fornecedor pelo slug.
     * Extensivel: adicionar novos fornecedores aqui.
     *
     * @throws \InvalidArgumentException se o slug nao for suportado
     */
    private function resolveConnector(string $slug): SupplierConnectorInterface
    {
        return match ($slug) {
            'cj'          => app(CJDropshippingConnector::class),
            'aliexpress'  => app(AliExpressConnector::class),
            default => throw new \InvalidArgumentException(
                "Fornecedor '{$slug}' nao suportado. Conectores disponiveis: cj, aliexpress"
            ),
        };
    }
}
