<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Client;
use App\Models\Product;
use App\Models\ClientProduct;

class AutoListSupplierProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Varrer clientes que estao em "full_auto" list mode e atachar novos produtos do Galpão Base
     */
    public function handle(): void
    {
        $autoClients = Client::where('listing_mode', 'full_auto')
            ->where('is_active', true)
            ->get();

        foreach ($autoClients as $client) {
            // Todos produtos do Fornecedor vinculado a este cliente e que ainda nao estão no client_products
            $supplierProducts = Product::where('supplier_id', $client->supplier_id)
                ->where('is_active', true)
                ->whereNotIn('id', function ($q) use ($client) {
                    $q->select('product_id')
                        ->from('client_products')
                        ->where('client_id', $client->id);
                })
                ->get();

            foreach ($supplierProducts as $product) {

                // Cria uma "Cópia Espelho" no catalogo do Cliente
                $cp = ClientProduct::create([
                    'client_id' => $client->id,
                    'product_id' => $product->id,
                    'supplier_product_sku' => $product->sku,
                    'custom_sku' => $client->id . '-' . $product->sku,
                    'custom_title' => $product->name,
                    'custom_description' => $product->description,
                    'custom_price' => $product->price * 1.5, // 50% mock margin default
                    'pricing_mode' => 'margin',
                    'profit_margin' => 50,
                    'sync_status' => 'pending',
                ]);

                // Dispara o Job de Sync para subir nas lojas se o Lojista tiver conta ML/Shopee logada
                SyncProductsJob::dispatch($cp->id);
            }
        }
    }
}
