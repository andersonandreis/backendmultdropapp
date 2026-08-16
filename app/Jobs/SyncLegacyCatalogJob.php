<?php

namespace App\Jobs;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Supplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Observers\ProductObserver;

class SyncLegacyCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 min max
    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('legacy'); // NOV-199: carga do legado nao compete com a fila default
    }

    public function handle(): void
    {
        // MUL-320: a trava do legado vivia SO no schedule (routes/console.php:89), atras de
        // config('app.legacy_sync_enabled'). Qualquer outro disparo — AdminController:1031,
        // fila, tinker — rodava com a flag desligada e repopulava o catalogo: 691 produtos
        // criados em 01/08/2026 com a flag em false. Mesmo defeito da MUL-311 no Bling,
        // onde a trava tambem estava no agendamento e nao no funil.
        if (! config('app.legacy_sync_enabled')) {
            Log::warning('[MUL-320] SyncLegacyCatalogJob abortado: legacy_sync_enabled esta desligado');
            return;
        }

        // MUL-037: guard de conexao -- falha de acesso ao legado (ex: credencial errada,
        // host inacessivel) deve loggar e encerrar sem estourar a fila de failed_jobs.
        // Causa raiz historica (2026-06-18): job rodava com username root em vez de
        // tudoonline_production; o MySQL do K3s negava acesso do IP 10.42.0.1 resultando
        // em 332 failed_jobs. Fix definitivo: credenciais corrigidas no .env (commit 78d8987).
        // Este guard e uma camada extra de seguranca para futuras falhas temporarias de rede/auth.
        try {
            DB::connection("legacy")->getPdo();
        } catch (\Throwable $connErr) {
            Log::warning("SyncLegacyCatalog: conexao legacy indisponivel, pulando execucao: " . $connErr->getMessage());
            return;
        }

        $lastSync = Cache::get('legacy_catalog_last_sync', now()->subHour()->toDateTimeString());

        Log::info("SyncLegacyCatalog: checking updates since {$lastSync}");

        // Filtra apenas depositos mapeados nos suppliers — evita processar 85K+ itens de depositos nao relacionados
        $knownDepositoIds = Supplier::whereNotNull('legacy_id')->pluck('legacy_id')->toArray();
        if (empty($knownDepositoIds)) {
            Log::info('SyncLegacyCatalog: no suppliers with legacy_id, skipping');
            return;
        }

        $updated = DB::connection('legacy')
            ->table('sku_pai')
            ->join('deposito', 'sku_pai.id_deposito', '=', 'deposito.id')
            ->where('deposito.liberada', 1)
            ->whereIn('sku_pai.id_deposito', $knownDepositoIds)
            ->where('sku_pai.data_update', '>', $lastSync)
            ->select([
                'sku_pai.id', 'sku_pai.sku', 'sku_pai.descricao', 'sku_pai.custo',
                'sku_pai.custo_curso', 'sku_pai.estoque', 'sku_pai.id_deposito',
                'sku_pai.peso', 'sku_pai.largura', 'sku_pai.altura', 'sku_pai.comprimento',
                'sku_pai.ean', 'sku_pai.marca', 'sku_pai.data_update',
                'sku_pai.img', // MUL-FIX-3: campo de imagem do legado
            ])
            ->orderBy('sku_pai.data_update')
            ->limit(500)
            ->get();

        $synced  = 0;
        $skipped = 0;
        $maxCursor = $lastSync;

        // FOR-030: Pre-carrega imagens adicionais de todos os sku_pai deste batch (N+1 prevenido).
        $legacyIds = $updated->pluck('id')->filter()->values()->toArray();
        $extraImagesMap = [];
        if (!empty($legacyIds)) {
            try {
                $extraRows = DB::connection('legacy')
                    ->table('sku_pai_imagens')
                    ->whereIn('id_sku_pai', $legacyIds)
                    ->orderBy('id_sku_pai')
                    ->orderBy('posicao')
                    ->select(['id_sku_pai', 'img', 'posicao'])
                    ->get();
                foreach ($extraRows as $er) {
                    if (empty($er->img)) continue;
                    $extraImagesMap[$er->id_sku_pai][] = ['img' => trim($er->img), 'posicao' => (int) ($er->posicao ?? 1)];
                }
            } catch (\Throwable $e) {
                Log::warning('SyncLegacyCatalog: nao foi possivel buscar sku_pai_imagens: ' . $e->getMessage());
            }
        }

        foreach ($updated as $row) {
            // Avanca o cursor para o data_update desta linha mesmo se ela falhar,
            // garantindo que uma linha problematica (ex: SKU duplicado) nao trave o batch para sempre.
            if (!empty($row->data_update) && $row->data_update > $maxCursor) {
                $maxCursor = $row->data_update;
            }

            try {
                // MUL-223: sku_pai com prefixo D773- é da Multdrop Filial (sup 157/legacy 773),
                // mesmo que sku_pai.id_deposito aponte pro dep de origem (ex.: 498 = Multdrop).
                // Duplicação intencional pra ter preço diferente por filial (decisao Ruan 12/07).
                $legacyDep = (!empty($row->sku) && str_starts_with($row->sku, 'D773-'))
                    ? 773
                    : $row->id_deposito;
                $supplier = Supplier::where('legacy_id', $legacyDep)->first();
                if (!$supplier) { $skipped++; continue; }

                // Anti-loop: desativa observer (legado->hubai, nao hubai->legado)
                ProductObserver::$disableSync = true;
                // Fix duplicate key: quando legado recria sku_pai com novo ID mas mesmo SKU,
                // updateOrCreate(['legacy_sku_pai_id'=>newId]) tentava INSERT e explodia na unique(sku).
                // Solucao: pre-fixar o legacy_sku_pai_id do registro existente antes do updateOrCreate.
                $sku = $row->sku ?: "SKU-{$row->id}";
                $byId  = Product::where('legacy_sku_pai_id', $row->id)->first();
                $bySku = !$byId ? Product::where('sku', $sku)->first() : null;
                if ($bySku && !$byId) {
                    // Produto existe pelo SKU mas com legacy_id diferente — realinhar o ID
                    $bySku->legacy_sku_pai_id = $row->id;
                    $bySku->saveQuietly(); // saveQuietly: pula HasSlug observer, evita slugs_slug_unique
                }
                // NOV-081 FIX 4: updateOrCreate dispara HasSlug::bootHasSlug (via saved()).
                // Se o slug ja existe na tabela slugs para outro produto, estoura slugs_slug_unique.
                // O catch externo abandonava o produto inteiro — estoque nunca era atualizado.
                // Fix: try/catch interno isola o erro de slug. Se updateOrCreate falhar,
                // usa saveQuietly() (sem observer) para persistir os dados do produto.
                // O estoque continua sendo atualizado independente do slug.
                $productData = [
                    'supplier_id' => $supplier->id,
                    'sku' => $sku,
                    'name' => mb_convert_encoding($row->descricao ?? 'Sem nome', 'UTF-8', 'UTF-8'),
                    'price' => (float) ($row->custo_curso ?: $row->custo ?: 0),
                    'cost' => (float) ($row->custo ?: 0),
                    'ean' => $row->ean ?: null,
                    'brand' => $row->marca ? mb_convert_encoding($row->marca, 'UTF-8', 'UTF-8') : null,
                    'weight_kg' => (float) ($row->peso ?: 0),
                    'height_cm' => (float) ($row->altura ?: 0),
                    'width_cm' => (float) ($row->largura ?: 0),
                    'length_cm' => (float) ($row->comprimento ?: 0),
                ];
                // FIX MUL-073 anderson: não forçar reativação. is_active só no INSERT (preserva is_active=0 setado pelo HubAI).
                // MUL-079: produto novo sem foto no legado criado como inativo — evita catálogo poluído por SnapmixBrasil/outros sem img.
                // O ErpAccount sync (BlingProductSync) ou SyncProductToLegacy (com foto) ativa o produto depois.
                $existing = $byId ?: $bySku;
                $imgUrlPreview = !empty($row->img) ? trim($row->img) : null;
                if (!$existing) {
                    // MUL-081: supplier com erp_account usa ERP sync para ativar — legado nao ativa
                    $supplierHasErp = \DB::table('erp_accounts')
                        ->where('supplier_id', $supplier->id)
                        ->whereNotNull('access_token')
                        ->exists();
                    if ($supplierHasErp) {
                        $productData['is_active'] = false;
                    } else {
                        $productData['is_active'] = ($imgUrlPreview !== null && $imgUrlPreview !== '');
                    }
                }
                try {
                    $product = Product::updateOrCreate(['legacy_sku_pai_id' => $row->id], $productData);
                } catch (\Throwable $slugErr) {
                    // Erro de slug (slugs_slug_unique) ou outro erro de observer nao pode pular o produto.
                    // Fallback: busca produto existente e atualiza so os dados de negocio (sem observer).
                    $product = $byId ?: $bySku ?: Product::where('legacy_sku_pai_id', $row->id)->first();
                    if (!$product) {
                        // Produto novo E slug deu erro (raro): tenta insert sem observer
                        $product = new Product();
                    }
                    $product->fill($productData);
                    $product->legacy_sku_pai_id = $row->id;
                    try {
                        $product->saveQuietly(); // Sem HasSlug — estoque sera atualizado abaixo
                    } catch (\Throwable $saveErr) {
                        Log::warning("SyncLegacyCatalog: saveQuietly fallback falhou sku_pai={$row->id}: " . $saveErr->getMessage());
                    }
                    Log::warning("SyncLegacyCatalog: slug falhou, usando saveQuietly sku_pai={$row->id}: " . $slugErr->getMessage());
                }
                ProductObserver::$disableSync = false;

                $newQty = max(0, (int) $row->estoque);
                // SEL-031: legado usa 2147483647 como sentinela "sem controle de estoque" (ex. deposito 498)
                // e ha sku_pai corrompidos com INT_MAX; capar pra nao propagar overflow pra federacao/marketplaces
                if ($newQty >= 2000000000) {
                    $newQty = 99999;
                }
                $inventory = Inventory::firstOrNew(
                    ['product_id' => $product->id, 'warehouse_id' => $supplier->id]
                );

                $oldQty = $inventory->exists ? (int) $inventory->quantity : null;

                // NOV-079: garantir propagacao de estoque=0 para todos os clientes com listing
                // updateOrCreate nao dispara observer 'updated' se valor nao muda
                if (!$inventory->exists) {
                    $inventory->producer_id = $supplier->id;
                    $inventory->quantity    = $newQty;
                    $inventory->save(); // dispara created -> SyncInventoryJob
                } elseif ($oldQty !== $newQty) {
                    $inventory->quantity    = $newQty;
                    $inventory->producer_id = $supplier->id;
                    $inventory->save(); // dispara updated -> SyncInventoryJob
                } elseif ($newQty === 0) {
                    // Estoque ja era zero mas pode ter listings com sync_status=failed ou desatualizadas
                    // Dispatch direto sem tocar no model para nao criar loop
                    if ($product->clientProducts()->whereNotNull('external_listing_id')->exists()) {
                        \App\Jobs\SyncInventoryJob::dispatch($product->id)
                            ->onQueue('inventory')
                            ->delay(now()->addSeconds(10));
                    }
                } else {
                    // Valor identico e nao-zero: sem acao necessaria
                    $inventory->producer_id = $supplier->id;
                    $inventory->save(['timestamps' => false]); // atualiza producer_id sem observer
                }

                // MUL-FIX-3: sincronizar imagem cover do legado (campo img)
                // Salva a URL diretamente sem baixar — o accessor getUrlAttribute() em ProductMedia
                // resolve paths relativos para URL absoluta. URLs do legado ja sao absolutas (Shopee CDN,
                // goolhub.io, etc) e sao retornadas pelo accessor sem modificacao.
                // updateOrCreate garante idempotencia: sync repetido nao duplica registros.
                $imgUrl = !empty($row->img) ? trim($row->img) : null;
                if ($imgUrl && $this->isSafeImageUrl($imgUrl)) {
                    // MUL-080: preservar URL local — nao sobrescrever com S3 expirado do legado
                    $existingCoverUrl = DB::table('product_media')
                        ->where('product_id', $product->id)
                        ->where('is_cover', true)
                        ->whereNull('product_variation_id')
                        ->value('url');
                    if (!$existingCoverUrl || !str_starts_with($existingCoverUrl, '/storage')) {
                        ProductMedia::updateOrCreate(
                            ['product_id' => $product->id, 'is_cover' => true, 'product_variation_id' => null],
                            [
                                'type'         => 'image',
                                'url'          => $imgUrl,
                                'original_url' => $imgUrl,
                                'position'     => 0,
                            ]
                        );
                    }
                }

                // FOR-030: sincronizar imagens adicionais (sku_pai_imagens)
                // Idempotente: insere apenas URLs que ainda nao existem no product_media.
                // Nao deleta imagens existentes para preservar anuncios ja publicados.
                $extraImgs = $extraImagesMap[$row->id] ?? [];
                if (!empty($extraImgs)) {
                    $existingUrls = DB::table('product_media')
                        ->where('product_id', $product->id)
                        ->where('type', 'image')
                        ->pluck('url')
                        ->filter(fn($u) => is_string($u) || is_int($u)) // HUB-xxx FIX-4: array_flip() nao aceita null/float
                        ->flip()
                        ->all();

                    $newRows = [];
                    foreach ($extraImgs as $extra) {
                        $url = $extra['img'];
                        if (empty($url) || isset($existingUrls[$url]) || !$this->isSafeImageUrl($url)) continue;
                        $newRows[] = [
                            'product_id'   => $product->id,
                            'type'         => 'image',
                            'url'          => $url,
                            'original_url' => $url,
                            'is_cover'     => 0,
                            'content_hash' => md5($url), // MUL-206: dedup nativo via UNIQUE
                            'position'     => max(1, (int) $extra['posicao']),
                            'created_at'   => now(),
                            'updated_at'   => now(),
                        ];
                    }
                    if (!empty($newRows)) {
                        // MUL-206: insertOrIgnore + UNIQUE(product_id, content_hash)
                        DB::table('product_media')->insertOrIgnore($newRows);
                    }
                }

                $synced++;
            } catch (\Throwable $e) {
                // Uma linha ruim NAO pode derrubar o batch inteiro (era a causa do sync travado em D53-CINTASWEAT).
                $skipped++;
                Log::error("SyncLegacyCatalog: pulando sku_pai={$row->id} sku={$row->sku}: " . $e->getMessage());
            }
        }

        // Cursor avanca para o maior data_update processado (synced OU pulado) — progresso garantido.
        Cache::put('legacy_catalog_last_sync', $maxCursor);
        Log::info("SyncLegacyCatalog: synced {$synced}, skipped {$skipped}, checked {$updated->count()}, cursor={$maxCursor}");
    }
    /**
     * MUL-063: Guard - rejeita URLs de outros tenants que vazam via legado compartilhado.
     */
    private function isSafeImageUrl(string $url): bool
    {
        foreach (["fornecefy-images.b-cdn.net", "api.fornecefy.io", "fornecefy.io"] as $blocked) {
            if (stripos($url, $blocked) !== false) return false;
        }
        return true;
    }

}