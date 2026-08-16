<?php

namespace App\Services;

use App\Models\Product;
use App\Models\MarketplaceAccount;
use App\Models\ClientProduct;

class ProductCloneService
{
    /**
     * Clona um produto Mestre (Fornecedor) para a conta específica de Marketplace do Lojista.
     */
    public function cloneToAccount(Product $product, MarketplaceAccount $account): ClientProduct
    {
        // 1. Gera o Sufixo da Loja
        $platformPrefix = strtoupper(substr($account->platform, 0, 3)); // MLI, SHO, AMA
        $suffix = "-{$platformPrefix}{$account->id}";

        // 2. Cria o Sub-SKU único
        $subSku = $product->sku . $suffix;

        // 3. Obtém as imagens originais
        $images = $product->media()->pluck('url')->toArray();

        // 4. Clona o Produto com Metadados
        $clientProduct = ClientProduct::firstOrCreate(
            [
                'client_id' => $account->client_id,
                'marketplace_account_id' => $account->id,
                'product_id' => $product->id,
            ],
            [
                'supplier_product_sku' => $product->sku,
                'custom_sku' => $subSku,
                'custom_title' => null,
                'custom_description' => $product->description,
                'custom_price' => $product->price, // O lojista editará depois adicionando a margem
                'custom_brand' => $product->brand,
                'custom_model' => $product->model,
                'custom_gtin' => $product->gtin,
                'custom_condition' => $product->condition ?? 'new',
                'custom_warranty_type' => $product->warranty_type,
                'custom_warranty_days' => $product->warranty_days,
                'custom_weight_kg' => $product->weight_kg,
                'custom_height_cm' => $product->height_cm,
                'custom_width_cm' => $product->width_cm,
                'custom_length_cm' => $product->length_cm,
                'custom_images' => $images,
                'pricing_mode' => 'manual',
                'sync_status' => 'pending',
                'is_active' => true,
            ]
        );

        // Se o produto tiver variações (cores, tamanhos), clonar a árvore de varies também
        // foreach ($product->variations as $var) { ... }

        return $clientProduct;
    }
}
