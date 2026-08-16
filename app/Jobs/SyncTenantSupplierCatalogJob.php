<?php

namespace App\Jobs;

use App\Models\FederationSyncLog;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\TenantWebhookEndpoint;
use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncTenantSupplierCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    private const BATCH_SIZE               = 200;
    private const FEDERATION_CATALOG_EVENT = 'federation.catalog.sync';

    public function __construct(
        public readonly string  $tenantId,
        public readonly int     $supplierId,
        public readonly ?string $sinceTimestamp = null,
    ) {
        $this->onQueue('catalog'); // MUL-212: fila propria, nao compete com a default
    }

    public function handle(): void
    {
        $tenant   = Tenant::find($this->tenantId);
        $supplier = Supplier::withoutGlobalScopes()->find($this->supplierId);
        if (! $tenant || ! $supplier) {
            Log::warning('[SyncTenantSupplierCatalogJob] nao encontrado', ['tenant_id' => $this->tenantId, 'supplier_id' => $this->supplierId]);
            return;
        }
        $endpoints = TenantWebhookEndpoint::where('tenant_id', $this->tenantId)->where('active', true)->where(function ($q) { $q->whereJsonContains('events', self::FEDERATION_CATALOG_EVENT)->orWhereJsonContains('events', '*'); })->get();
        if ($endpoints->isEmpty()) {
            Log::info('[SyncTenantSupplierCatalogJob] sem endpoints de federacao', ['tenant' => $tenant->slug]);
            return;
        }
        $tenantSlug = $tenant->slug;

        // NOV-198: janela = ultimo scan COMPLETO persistido, nao o momento do dispatch.
        // Job atrasado na fila nao pode virar varredura de 20h (loop de 1,5M em 09-10/07).
        // sinceTimestamp do construtor e ignorado de proposito (jobs antigos na fila carregam valores velhos).
        $cacheKey  = "fedcat:last_sync:{$this->tenantId}:{$this->supplierId}";
        $scanStart = now();
        $cached    = Cache::get($cacheKey);
        $since     = $cached ? \Carbon\Carbon::parse($cached) : now()->subHours(2);

        $disp  = 0;
        $skip  = 0;
        Product::withoutGlobalScopes()
            ->where('supplier_id', $this->supplierId)
            // MUL-320c: NAO filtrar por is_active. O delta e por updated_at; ao desativar
            // um produto ele saia do filtro e a WL nunca recebia a desativacao — ficava
            // para sempre com o ultimo estado conhecido, que era 'ativo'. Caminho so de ida:
            // dava para ativar, nunca para desativar. Medido em 03/08/2026: 13 produtos
            // excluidos no Bling e desativados no hub seguiam ativos no painel da WL.
            // O receptor ja aplica is_active (FederationReceiveCatalogJob:106) e cost (:110).
            ->where('updated_at', '>', $since)
            ->with(['media', 'inventory'])
            ->chunkById(self::BATCH_SIZE, function($products) use ($endpoints, $tenantSlug, &$disp, &$skip) {
                foreach ($products as $product) {
                    if ($product->federation_source && $product->federation_source === $tenantSlug) {
                        $skip++;
                        FederationSyncLog::recordOrSkip(direction:'hub_to_wl',entityType:'product',entityId:$product->id,targetTenant:$tenantSlug,status:'skipped');
                        continue;
                    }
                    $pl = ['event'=>self::FEDERATION_CATALOG_EVENT,'hub_product_id'=>$product->id,'sku'=>$product->sku,'name'=>$product->name,'price'=>$product->price,'cost'=>$product->cost,'stock'=>min((int) $product->inventory->where('warehouse_id', $this->supplierId)->sum('quantity'), 99999),'is_active'=>(bool)$product->is_active,'supplier_id'=>$product->supplier_id,'federation_source'=>config('app.tenant','hubai'),'images'=>$product->media->pluck('url')->values()->all(),'synced_at'=>now()->toIso8601String()];
                    // NOV-198: hash SEM synced_at — com ele o hash mudava a cada run e o dedup nunca skipava
                    $plHash = $pl;
                    unset($plHash['synced_at']);
                    $ph = hash('sha256', json_encode($plHash));
                    if (FederationSyncLog::where('entity_type','product')->where('entity_id',$product->id)->where('target_tenant',$tenantSlug)->where('payload_hash',$ph)->where('status','success')->exists()) { $skip++; continue; }
                    foreach ($endpoints as $ep) {
                        // MUL-212: key deterministica colapsa N mudancas de estoque em 1 delivery
                        // pendente (o job entrega o payload mais novo do banco).
                        $key = 'fedcat:'.$ep->id.':'.$product->id;
                        // NOV-198: buscar em QUALQUER status — rows dead/success seguram a unique
                        // key e o create estourava 1062. dead/success revivem pra pending.
                        $existing = WebhookDelivery::where('endpoint_id', $ep->id)
                            ->where('idempotency_key', $key)
                            ->first();
                        if ($existing) {
                            if (in_array($existing->status, [WebhookDelivery::STATUS_PENDING, WebhookDelivery::STATUS_FAILED], true)) {
                                // pending parada >30min = job perdido/purgado — re-despacha (checar ANTES do update, que renova updated_at)
                                $orphaned = $existing->status === WebhookDelivery::STATUS_PENDING && $existing->updated_at->lt(now()->subMinutes(30));
                                $existing->update(['payload' => $pl]);
                                if ($orphaned) {
                                    DispatchWebhookJob::dispatch($existing->id)->onQueue('catalog');
                                }
                                $skip++;
                            } else {
                                $existing->update(['payload' => $pl, 'status' => WebhookDelivery::STATUS_PENDING, 'attempt' => 0, 'next_retry_at' => null]);
                                DispatchWebhookJob::dispatch($existing->id)->onQueue('catalog');
                                $disp++;
                            }
                            continue;
                        }
                        $d = WebhookDelivery::create(['endpoint_id'=>$ep->id,'event'=>self::FEDERATION_CATALOG_EVENT,'payload'=>$pl,'idempotency_key'=>$key,'attempt'=>0,'status'=>WebhookDelivery::STATUS_PENDING]);
                        DispatchWebhookJob::dispatch($d->id)->onQueue('catalog');
                        $disp++;
                    }
                    FederationSyncLog::recordOrSkip(direction:'hub_to_wl',entityType:'product',entityId:$product->id,targetTenant:$tenantSlug,status:'success',payloadHash:$ph);
                }
            });
        // So avanca a janela apos varredura completa; se falhar no meio, proximo run re-varre (dedupe colapsa).
        Cache::forever($cacheKey, $scanStart->toIso8601String());
        Log::info('[SyncTenantSupplierCatalogJob] concluido',[ 'tenant'=>$tenantSlug,'dispatched'=>$disp,'skipped'=>$skip]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[SyncTenantSupplierCatalogJob] falhou',[ 'tenant_id'=>$this->tenantId,'error'=>$e->getMessage()]);
    }
}
