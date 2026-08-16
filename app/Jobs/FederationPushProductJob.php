<?php

namespace App\Jobs;

use App\Models\Product;
use App\Observers\ProductObserver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOV-171-C -- Envia produto criado/editado no WL para o hub (api.hubai.io).
 *
 * Disparado pelo ProductObserver nos WLs quando:
 * - Produto criado/editado localmente (federation_source IS NULL)
 * - config('app.tenant') !== 'hubai' (so nos WLs)
 * - config('federation.hub_url') configurado
 *
 * Apos sucesso: salva hub_product_id retornado com $disableSync=true.
 * Retry: tries=3, backoff [30, 120, 600] segundos.
 * Queue: 'default' (workers RUNNING em todos os WLs).
 */
class FederationPushProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly int $productId
    ) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        $hubUrl   = config('federation.hub_url');
        $hubToken = config('federation.hub_token');

        if (! $hubUrl || ! $hubToken) {
            Log::warning('[FederationPushProduct] FEDERATION_HUB_URL ou FEDERATION_HUB_TOKEN nao configurados', [
                'product_id' => $this->productId,
            ]);
            return;
        }

        /** @var Product|null $product */
        $product = Product::withoutGlobalScopes()
            ->with(['media', 'inventory', 'supplier'])
            ->find($this->productId);

        if (! $product) {
            Log::warning('[FederationPushProduct] produto nao encontrado', ['product_id' => $this->productId]);
            return;
        }

        // MUL-395: aqui existia o segundo guard por federation_source ('guard duplo').
        // O primeiro saiu do ProductObserver na MUL-394; este continuou barrando e a
        // edicao do painel seguia sem chegar ao hub -- provado em 15/08: PUT
        // /supplier-admin/products/123 devolveu 200, price mudou local, hub intacto.
        //
        // federation_source e procedencia, nao trava de escrita. O anti-loop real:
        //   1. FederationReceiveCatalogJob seta ProductObserver::$disableSync antes do
        //      upsert -> escrita vinda do hub nao dispara o observer (regra 16);
        //   2. SyncTenantSupplierCatalogJob pula produto cujo federation_source e o slug
        //      do tenant de destino -> o que este WL empurra nao volta pra ele, porque o
        //      payload abaixo manda federation_source = source_tenant (multdrop, etc).
        //
        // Sem 1 e 2 este guard seria necessario. Com eles, e so um bloqueio de edicao.

        $sourceTenant = config('app.tenant', '');

        $payload = [
            'sku'               => $product->sku,
            'name'              => $product->name,
            'price'             => $product->price,
            'cost'              => $product->cost,
            'stock'             => $product->inventory->sum('quantity'),
            'supplier_id'       => $product->supplier?->hub_supplier_id ?? $product->supplier_id, // FOR-079: usa hub_supplier_id (ID no Hub) em vez do ID local do WL
            'source_backend'    => $sourceTenant,
            'federation_source' => $sourceTenant,
            'is_active'         => $product->is_active,
            'description'       => $product->description,
            'brand'             => $product->brand,
            'weight_kg'         => $product->weight_kg,
            'height_cm'         => $product->height_cm,
            'width_cm'          => $product->width_cm,
            'length_cm'         => $product->length_cm,
            'ncm'               => $product->ncm,
            'images'            => $product->media->pluck('url')->toArray(),
        ];

        $response = Http::withToken($hubToken)
            ->timeout(30)
            ->post($hubUrl . '/api/federation/catalog/push', $payload);

        if ($response->failed()) {
            Log::error('[FederationPushProduct] hub retornou erro', [
                'product_id' => $this->productId,
                'sku'        => $product->sku,
                'status'     => $response->status(),
                'body'       => substr($response->body(), 0, 500),
            ]);
            throw new \RuntimeException(
                "[FederationPushProduct] Hub retornou HTTP {$response->status()} para SKU {$product->sku}"
            );
        }

        $hubProductId = $response->json('hub_product_id');

        if ($hubProductId) {
            // Salvar hub_product_id com $disableSync=true para nao disparar push recursivo
            ProductObserver::$disableSync = true;
            try {
                $product->update(['hub_product_id' => $hubProductId]);
            } finally {
                ProductObserver::$disableSync = false;
            }
        }

        Log::info('[FederationPushProduct] produto enviado ao hub com sucesso', [
            'product_id'     => $this->productId,
            'sku'            => $product->sku,
            'hub_product_id' => $hubProductId,
            'source_tenant'  => $sourceTenant,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[FederationPushProduct] falhou apos 3 tentativas', [
            'product_id' => $this->productId,
            'error'      => $exception->getMessage(),
        ]);
    }
}
