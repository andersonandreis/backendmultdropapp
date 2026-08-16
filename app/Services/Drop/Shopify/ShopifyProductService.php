<?php

namespace App\Services\Drop\Shopify;

use App\Models\Drop\DropStore;
use App\Models\Drop\ImportedProduct;
use App\Models\Drop\ImportedProductVariant;
use Illuminate\Support\Facades\Log;

/**
 * Gerencia produtos Shopify - publicacao, atualizacao, estoque e exclusao.
 */
class ShopifyProductService
{
    /**
     * Publica um ImportedProduct na loja Shopify do cliente.
     * Salva shopify_product_id no produto e shopify_variant_id em cada variante.
     */
    public function publishProduct(ImportedProduct $product): array
    {
        $store  = $this->resolveStore($product);
        $client = $this->makeClient($store);
        $payload = $this->buildProductPayload($product);

        Log::info('[ShopifyProductService] Publicando produto', [
            'product_id' => $product->id,
            'store_id'   => $store->id,
            'title'      => $payload['product']['title'],
        ]);

        $response       = $client->post('products.json', $payload);
        $shopifyProduct = $response['product'] ?? [];
        $shopifyId      = $shopifyProduct['id'] ?? null;

        if (!$shopifyId) {
            Log::error('[ShopifyProductService] publishProduct: resposta sem product.id', [
                'product_id' => $product->id,
                'response'   => $response,
            ]);
            throw new \RuntimeException('Shopify nao retornou product.id apos criacao');
        }

        $product->update([
            'shopify_product_id'   => (string) $shopifyId,
            'status'               => 'published',
            'shopify_published_at' => now(),
        ]);

        $this->mapVariantIds($product, $shopifyProduct['variants'] ?? []);

        Log::info('[ShopifyProductService] Produto publicado com sucesso', [
            'product_id'         => $product->id,
            'shopify_product_id' => $shopifyId,
        ]);

        return $response;
    }

    /**
     * Atualiza produto existente no Shopify (PUT).
     */
    public function updateProduct(ImportedProduct $product): array
    {
        if (!$product->shopify_product_id) {
            throw new \RuntimeException(
                'ImportedProduct #' . $product->id . ' sem shopify_product_id - use publishProduct primeiro'
            );
        }

        $store   = $this->resolveStore($product);
        $client  = $this->makeClient($store);
        $payload = $this->buildProductPayload($product);

        Log::info('[ShopifyProductService] Atualizando produto', [
            'product_id'         => $product->id,
            'shopify_product_id' => $product->shopify_product_id,
        ]);

        $response = $client->put('products/' . $product->shopify_product_id . '.json', $payload);

        Log::info('[ShopifyProductService] Produto atualizado com sucesso', [
            'product_id'         => $product->id,
            'shopify_product_id' => $product->shopify_product_id,
        ]);

        return $response;
    }

    /**
     * Atualiza nivel de estoque de uma variante via inventory_levels/set.
     * Busca inventory_item_id da variante antes de atualizar.
     */
    public function updateInventory(ImportedProductVariant $variant, int $quantity): bool
    {
        if (!$variant->shopify_variant_id) {
            Log::warning('[ShopifyProductService] updateInventory: variante sem shopify_variant_id', [
                'variant_id' => $variant->id,
            ]);
            return false;
        }

        $product = $variant->importedProduct;
        $store   = $this->resolveStore($product);
        $client  = $this->makeClient($store);

        try {
            $variantData     = $client->get('variants/' . $variant->shopify_variant_id . '.json');
            $inventoryItemId = $variantData['variant']['inventory_item_id'] ?? null;

            if (!$inventoryItemId) {
                Log::warning('[ShopifyProductService] updateInventory: inventory_item_id nao encontrado', [
                    'variant_id'         => $variant->id,
                    'shopify_variant_id' => $variant->shopify_variant_id,
                ]);
                return false;
            }

            $locations  = $client->get('locations.json');
            $locationId = $locations['locations'][0]['id'] ?? null;

            if (!$locationId) {
                Log::warning('[ShopifyProductService] updateInventory: location_id nao encontrado', [
                    'store_id' => $store->id,
                ]);
                return false;
            }

            $client->post('inventory_levels/set.json', [
                'location_id'       => $locationId,
                'inventory_item_id' => $inventoryItemId,
                'available'         => $quantity,
            ]);

            Log::info('[ShopifyProductService] Estoque atualizado', [
                'variant_id'        => $variant->id,
                'inventory_item_id' => $inventoryItemId,
                'quantity'          => $quantity,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('[ShopifyProductService] updateInventory falhou', [
                'variant_id' => $variant->id,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove produto do Shopify e marca como arquivado.
     */
    public function deleteProduct(ImportedProduct $product): bool
    {
        if (!$product->shopify_product_id) {
            Log::warning('[ShopifyProductService] deleteProduct: produto sem shopify_product_id', [
                'product_id' => $product->id,
            ]);
            return false;
        }

        $store   = $this->resolveStore($product);
        $client  = $this->makeClient($store);
        $deleted = $client->delete('products/' . $product->shopify_product_id . '.json');

        if ($deleted) {
            $product->update(['status' => 'archived']);
            Log::info('[ShopifyProductService] Produto excluido do Shopify', [
                'product_id'         => $product->id,
                'shopify_product_id' => $product->shopify_product_id,
            ]);
        }

        return $deleted;
    }

    /**
     * Busca dados de um produto no Shopify pelo ID externo.
     */
    public function getProduct(DropStore $store, string $shopifyProductId): array
    {
        $client   = $this->makeClient($store);
        $response = $client->get('products/' . $shopifyProductId . '.json');

        return $response['product'] ?? $response;
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    /**
     * Monta o payload completo para criacao/atualizacao de produto no Shopify.
     */
    private function buildProductPayload(ImportedProduct $product): array
    {
        $body = $product->description_ai ?? $product->description ?? '';

        $images = [];
        if (!empty($product->images) && is_array($product->images)) {
            foreach ($product->images as $url) {
                if ($url) {
                    $images[] = ['src' => $url];
                }
            }
        }

        $variants = [];
        if ($product->relationLoaded('variants') || $product->variants()->exists()) {
            foreach ($product->variants as $variant) {
                $vp = [
                    'title'                => $variant->title ?? 'Default Title',
                    'price'                => (string) ($variant->price ?? $product->price ?? '0.00'),
                    'sku'                  => $variant->sku ?? null,
                    'weight'               => (float) ($variant->weight_kg ?? 0),
                    'weight_unit'          => 'kg',
                    'inventory_management' => 'shopify',
                    'inventory_policy'     => 'deny',
                    'requires_shipping'    => true,
                ];

                if ($variant->option1) {
                    $vp['option1'] = $variant->option1;
                }
                if ($variant->option2) {
                    $vp['option2'] = $variant->option2;
                }

                $variants[] = $vp;
            }
        }

        if (empty($variants)) {
            $variants = [[
                'title'                => 'Default Title',
                'price'                => (string) ($product->price ?? '0.00'),
                'inventory_management' => 'shopify',
                'inventory_policy'     => 'deny',
                'requires_shipping'    => true,
            ]];
        }

        return [
            'product' => [
                'title'        => $product->title ?? $product->name ?? 'Produto sem titulo',
                'body_html'    => $body,
                'vendor'       => config('app.name', 'HubAI'),
                'product_type' => $product->category ?? '',
                'status'       => 'active',
                'images'       => $images,
                'variants'     => $variants,
            ],
        ];
    }

    /**
     * Associa shopify_variant_id de cada variante da resposta ao model ImportedProductVariant.
     * Matching por SKU; fallback por posicao (ordem).
     */
    private function mapVariantIds(ImportedProduct $product, array $shopifyVariants): void
    {
        if (empty($shopifyVariants)) {
            return;
        }

        $product->loadMissing('variants');

        $byShopifySku = [];
        foreach ($shopifyVariants as $sv) {
            if (!empty($sv['sku'])) {
                $byShopifySku[$sv['sku']] = $sv;
            }
        }

        foreach ($product->variants as $index => $variant) {
            $shopifyVariant = null;

            if ($variant->sku && isset($byShopifySku[$variant->sku])) {
                $shopifyVariant = $byShopifySku[$variant->sku];
            } elseif (isset($shopifyVariants[$index])) {
                $shopifyVariant = $shopifyVariants[$index];
            }

            if ($shopifyVariant) {
                $variant->update(['shopify_variant_id' => (string) $shopifyVariant['id']]);
            }
        }
    }

    /**
     * Resolve a DropStore associada ao produto (via client_id do produto).
     */
    private function resolveStore(ImportedProduct $product): DropStore
    {
        $store = DropStore::where('client_id', $product->client_id)
            ->where('status', 'active')
            ->first();

        if (!$store) {
            throw new \RuntimeException(
                'Nenhuma DropStore ativa encontrada para client_id=' . $product->client_id
            );
        }

        return $store;
    }

    private function makeClient(DropStore $store): ShopifyApiClient
    {
        return new ShopifyApiClient($store->shop_domain, $store->access_token);
    }
}
