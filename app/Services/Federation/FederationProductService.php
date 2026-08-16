<?php

namespace App\Services\Federation;

use App\Models\FederationSyncLog;
use App\Models\Inventory;
use App\Models\Product;
use App\Observers\ProductObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NOV-171-B -- Servico de recepcao de produto vindo de um WL (direcao WL->Hub).
 *
 * REGRA CRITICA (00-INDEX regra 16): ProductObserver::$disableSync = true ANTES
 * de qualquer upsert -- evita loop: produto chega do WL -> Observer dispara
 * SyncTenantSupplierCatalogJob -> produto volta ao WL que originou.
 *
 * DECISAO RUAN (NOV-171 secao 10): WL MANDA -- hub aceita incondicionalmente.
 * Sem validacao de transicao de estado, sem rejeicao de preco, etc.
 *
 * NOV-171-C fix: products.sku e UNIQUE no hubaiapp (nao composto com supplier_id).
 * O updateOrCreate usa apenas sku como chave de busca para evitar Duplicate entry.
 * Produto existente de outro supplier_id sera atualizado (WL MANDA).
 */
class FederationProductService
{
    /**
     * Recebe produto de um WL e faz upsert no hub (hubaiapp).
     */
    public function pushFromWl(array $data, string $sourceTenant): Product
    {
        $supplierId   = (int) $data['supplier_id'];
        $sku          = trim($data['sku']);
        $sourcBackend = $data['source_backend'] ?? $sourceTenant;

        // Validar que o supplier pertence ao tenant autenticado
        $this->assertSupplierBelongsToTenant($supplierId, $sourceTenant);

        $payloadHash = hash('sha256', json_encode([
            'sku'    => $sku,
            'sup'    => $supplierId,
            'name'   => $data['name'] ?? '',
            'price'  => $data['price'] ?? null,
            'stock'  => $data['stock'] ?? null,
        ]));

        try {
            // ANTI-LOOP: desabilitar observer antes de qualquer escrita (regra 16 do 00-INDEX)
            ProductObserver::$disableSync = true;

            $product = DB::transaction(function () use ($data, $sku, $supplierId, $sourcBackend) {
                /** @var Product $product */
                // IMPORTANTE: products.sku e UNIQUE (nao composto) no hubaiapp.
                // Usar apenas 'sku' como chave de busca para evitar Duplicate entry.
                // Produto existente de qualquer supplier_id sera atualizado.
                $product = Product::withoutGlobalScopes()->updateOrCreate(
                    [
                        'sku' => $sku,
                    ],
                    [
                        'supplier_id'       => $supplierId,
                        'name'              => $data['name'],
                        'price'             => $data['price'] ?? null,
                        'is_active'         => $data['is_active'] ?? true,
                        'federation_source' => $sourcBackend,
                        'description'       => $data['description']      ?? null,
                        'cost'              => $data['cost']              ?? 0,
                        'brand'             => $data['brand']             ?? null,
                        'weight_kg'         => $data['weight_kg']         ?? null,
                        'height_cm'         => $data['height_cm']         ?? null,
                        'width_cm'          => $data['width_cm']          ?? null,
                        'length_cm'         => $data['length_cm']         ?? null,
                        'ncm'               => $data['ncm']               ?? null,
                    ]
                );

                // Atualizar estoque se fornecido
                if (isset($data['stock'])) {
                    Inventory::withoutGlobalScopes()->updateOrCreate(
                        ['product_id' => $product->id],
                        [
                            'quantity'     => (int) $data['stock'],
                            'warehouse_id' => $supplierId,
                            'producer_id'  => $supplierId,
                        ]
                    );
                }

                // Processar imagens se fornecidas
                if (! empty($data['images']) && is_array($data['images'])) {
                    $this->syncImages($product, $data['images']);
                }

                return $product;
            });

            // Registrar sucesso na auditoria
            FederationSyncLog::recordOrSkip(
                direction:    'wl_to_hub',
                entityType:   'product',
                entityId:     $product->id,
                targetTenant: $sourceTenant,
                status:       'success',
                payloadHash:  $payloadHash,
            );

            Log::info('[FederationProductService] produto recebido do WL', [
                'source_tenant' => $sourceTenant,
                'product_id'    => $product->id,
                'sku'           => $sku,
                'supplier_id'   => $supplierId,
                'was_recent'    => $product->wasRecentlyCreated,
            ]);

            return $product;

        } catch (\Throwable $e) {
            FederationSyncLog::recordOrSkip(
                direction:    'wl_to_hub',
                entityType:   'product',
                entityId:     0,
                targetTenant: $sourceTenant,
                status:       'failed',
                payloadHash:  $payloadHash,
                errorMessage: substr($e->getMessage(), 0, 500),
            );
            throw $e;
        } finally {
            // SEMPRE restaurar o observer (mesmo em caso de excecao)
            ProductObserver::$disableSync = false;
        }
    }

    /**
     * Sincroniza imagens do produto vindo do WL.
     * Idempotente por URL -- nao duplica.
     */
    private function syncImages(Product $product, array $imageUrls): void
    {
        foreach ($imageUrls as $index => $url) {
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $product->media()->firstOrCreate(
                ['url' => $url],
                [
                    'type'     => 'image',
                    'position' => $index,
                ]
            );
        }
    }

    /**
     * Valida que o supplier_id pertence ao tenant autenticado.
     * Previne cross-tenant: WL enviando produto de supplier de outro WL.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    private function assertSupplierBelongsToTenant(int $supplierId, string $tenantSlug): void
    {
        $exists = DB::table('tenant_supplier as ts')
            ->join('tenants as t', 't.id', '=', 'ts.tenant_id')
            ->where('t.slug', $tenantSlug)
            ->where('ts.supplier_id', $supplierId)
            ->exists();

        if (! $exists) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                "supplier_id {$supplierId} nao pertence ao tenant '{$tenantSlug}'."
            );
        }
    }
}