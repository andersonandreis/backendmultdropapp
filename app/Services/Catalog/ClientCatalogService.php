<?php

namespace App\Services\Catalog;

use App\Models\Client;
use App\Models\Product;
use App\Models\ClientProduct;

class ClientCatalogService
{
    /**
     * Clona um SKU do Galpão Mestre para a Prateleira Digital do Lojista
     */
    public function cloneToClientCatalog(Client $client, Product $product, float $desiredMargin): ClientProduct
    {
        $customPrice = $product->price * (1 + ($desiredMargin / 100));

        return ClientProduct::firstOrCreate([
            'client_id' => $client->id,
            'product_id' => $product->id,
        ], [
            'supplier_product_sku' => $product->sku,
            'custom_sku' => $client->id . '-' . $product->sku,
            'custom_title' => null,
            'custom_description' => $product->description,
            'custom_price' => $customPrice,
            'pricing_mode' => 'margin',
            'profit_margin' => $desiredMargin,
            'sync_status' => 'pending',
            'is_active' => true
        ]);
    }
}
