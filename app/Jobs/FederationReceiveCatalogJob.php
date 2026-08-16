<?php

namespace App\Jobs;

use App\Models\Inventory;
use App\Models\Product;
use App\Observers\ProductObserver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NOV-171-C -- Processa recepcao de produto do hub nos WLs.
 *
 * Roda na queue 'webhooks' (workers RUNNING em todos os WLs -- NOV-171 plano secao 4).
 * Faz upsert com ProductObserver::$disableSync = true (regra 16 do 00-INDEX anti-loop).
 * Dedup por hub_product_id ou sku+supplier_id.
 *
 * LOCAL_SUPPLIER_ID do .env define o supplier_id local default para produtos novos.
 *
 * FOR-070: adicionado SELECT FOR UPDATE dentro da transaction para evitar race condition
 * entre workers que processam o mesmo webhook simultaneamente. Tambem ha try/catch
 * especifico para SQLSTATE 23000 (products_sku_unique) como fallback defensivo -- nesse
 * caso o job nao e recolocado na fila (delete()) e o conflito e logado como warning.
 */
class FederationReceiveCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly array $payload
    ) {}

    public function handle(): void
    {
        $sku          = trim($this->payload['sku'] ?? '');
        $hubProductId = $this->payload['hub_product_id'] ?? null;

        if (! $sku) {
            Log::warning('[FederationReceiveCatalog] sku vazio -- ignorando', [
                'payload_keys' => array_keys($this->payload),
            ]);
            return;
        }

        // Determinar supplier_id local
        // MUL-225: mapping hub→WL via suppliers.hub_supplier_id.
        // Se payload.supplier_id existe e há um supplier local com hub_supplier_id igual,
        // usar esse local — permite WL espelhar múltiplos fornecedores do HUB.
        $localSupplierId = (int) config('federation.local_supplier_id', 1);

        $payloadHubSupplierId = isset($this->payload['supplier_id']) ? (int) $this->payload['supplier_id'] : null;
        if ($payloadHubSupplierId) {
            $mapped = \DB::table('suppliers')->where('hub_supplier_id', $payloadHubSupplierId)->value('id');
            if ($mapped) {
                $localSupplierId = (int) $mapped;
            }
        }

        if ($hubProductId) {
            $existingByHub = Product::withoutGlobalScopes()
                ->where('hub_product_id', $hubProductId)
                ->value('supplier_id');
            if ($existingByHub) {
                $localSupplierId = $existingByHub;
            }
        }

        try {
            // ANTI-LOOP: desabilitar observer ANTES do upsert (regra 16 do 00-INDEX)
            ProductObserver::$disableSync = true;

            DB::transaction(function () use ($sku, $hubProductId, $localSupplierId) {
                // FOR-070: SELECT FOR UPDATE garante exclusividade entre workers paralelos.
                // Ambas as buscas usam lockForUpdate() para serializar no MySQL.
                $product = null;
                if ($hubProductId) {
                    $product = Product::withoutGlobalScopes()
                        ->where('hub_product_id', $hubProductId)
                        ->where('supplier_id', $localSupplierId)
                        ->lockForUpdate()
                        ->first();
                }
                if (! $product) {
                    $product = Product::withoutGlobalScopes()
                        ->where('sku', $sku)
                        ->where('supplier_id', $localSupplierId)
                        ->lockForUpdate()
                        ->first();
                }

                // Campos basicos sempre presentes (evitar NOT NULL sem default)
                $price = (float) ($this->payload['price'] ?? $product?->price ?? 0);
                $data  = [
                    'name'              => $this->payload['name']           ?? $product?->name ?? $sku,
                    'supplier_id'       => $localSupplierId,
                    'federation_source' => $this->payload['federation_source'] ?? 'hubai',
                    'is_active'         => $this->payload['is_active']      ?? true,
                    'sku'               => $sku,
                    'price'             => $price,
                    // cost: fallback para price se ausente (campo NOT NULL em alguns WLs)
                    'cost'              => (float) ($this->payload['cost']  ?? $product?->cost ?? $price),
                ];

                // Campos opcionais: incluir apenas se presentes no payload
                foreach (['description', 'brand', 'weight_kg', 'height_cm', 'width_cm', 'length_cm', 'ncm'] as $field) {
                    if (array_key_exists($field, $this->payload)) {
                        $data[$field] = $this->payload[$field];
                    }
                }

                if ($hubProductId) {
                    $data['hub_product_id'] = $hubProductId;
                }

                if ($product) {
                    // FOR-070: ao atualizar produto encontrado pelo SKU (hub_product_id era NULL),
                    // verificar se o novo SKU colidira com outro produto diferente.
                    if ($product->sku !== $sku) {
                        $conflict = Product::withoutGlobalScopes()
                            ->where('sku', $sku)
                            ->where('id', '!=', $product->id)
                            ->exists();

                        if ($conflict) {
                            Log::warning('[FederationReceiveCatalog] SKU conflita com outro produto -- skipando update de SKU', [
                                'sku'            => $sku,
                                'hub_product_id' => $hubProductId,
                                'product_id'     => $product->id,
                                'action'         => 'federation_sku_conflict',
                            ]);
                            // Atualiza tudo exceto o SKU para nao colidir
                            unset($data['sku']);
                        }
                    }
                    $product->fill($data)->save();
                } else {
                    // FOR-070: fallback defensivo caso outro worker tenha criado entre o
                    // lockForUpdate() e o create() (situacao extremamente rara pos-lock,
                    // mas possivel se o produto foi encontrado via hub_product_id=NULL + sku diferente).
                    try {
                        $product = Product::withoutGlobalScopes()->create($data);
                    } catch (QueryException $e) {
                        if (str_contains($e->getMessage(), 'products_sku_unique')) {
                            Log::warning('[FederationReceiveCatalog] SKU duplicado no create -- race condition residual, skipando', [
                                'sku'            => $sku,
                                'hub_product_id' => $hubProductId,
                                'supplier_id'    => $localSupplierId,
                                'action'         => 'federation_sku_conflict',
                            ]);
                            // Nao recolocar na fila -- job e descartado graciosamente
                            $this->delete();
                            return;
                        }
                        throw $e; // outros erros continuam propagando
                    }
                }

                // Atualizar estoque se informado
                if (array_key_exists('stock', $this->payload)) {
                    Inventory::withoutGlobalScopes()->updateOrCreate(
                        ['product_id' => $product->id],
                        [
                            'quantity'     => min((int) ($this->payload['stock'] ?? 0), 99999),
                            'warehouse_id' => $localSupplierId,
                            'producer_id'  => $localSupplierId,
                        ]
                    );
                }

                // Processar imagens (idempotente por URL)
                if (! empty($this->payload['images']) && is_array($this->payload['images'])) {
                    foreach ($this->payload['images'] as $index => $url) {
                        if (filter_var($url, FILTER_VALIDATE_URL)) {
                            $product->media()->firstOrCreate(
                                ['url' => $url],
                                ['type' => 'image', 'position' => $index]
                            );
                        }
                    }
                }

                Log::info('[FederationReceiveCatalog] produto upsertado', [
                    'sku'              => $sku,
                    'hub_product_id'   => $hubProductId,
                    'product_id_local' => $product->id,
                    'supplier_id'      => $localSupplierId,
                    'was_created'      => $product->wasRecentlyCreated,
                ]);
            });

        } finally {
            ProductObserver::$disableSync = false;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[FederationReceiveCatalog] falhou apos 3 tentativas', [
            'sku'   => $this->payload['sku'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
