<?php

namespace App\Services\Catalog;

use App\Models\Supplier;
use App\Models\Product;

class SupplierCatalogService
{
    /**
     * Valida e insere um novo produto mestre no Catálogo Central do Produtor
     */
    public function createMasterProduct(Supplier $supplier, array $data): Product
    {
        $product = Product::create(array_merge($data, [
            'supplier_id' => $supplier->id,
            'is_active' => true
        ]));

        return $product;
    }

    /**
     * Pausa um SKU tirando ele de visibilidade pros Dropshippers (Clients)
     */
    public function suspendProduct(Product $product): void
    {
        $product->update(['is_active' => false]);
    }
}
