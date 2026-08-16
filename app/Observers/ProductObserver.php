<?php

namespace App\Observers;

use App\Jobs\FederationPushProductJob;
use App\Jobs\SyncProductToLegacy;
use App\Models\Product;

/**
 * Observer para disparar sync quando um produto e criado/editado/excluido.
 *
 * Dois caminhos de sync:
 * 1. Legado: SyncProductToLegacy (hub apenas; LEGACY_SYNC_ENABLED gate)
 * 2. Federacao: FederationPushProductJob (WLs apenas; gate federation configurado)
 *
 * Anti-loop:
 * - $disableSync = true: bloqueia AMBOS os caminhos
 * - $product->federation_source preenchido: produto veio via federacao, nao dispara push de volta
 *
 * Regra 16 do 00-INDEX: $disableSync e flag anti-loop sagrada.
 *
 * NOTA: usa config('federation.tenant') -- NAO config('app.tenant') que nao existe em app.php.
 * config('federation.tenant') = env('APP_TENANT', 'hubai') -- definido em config/federation.php.
 */
class ProductObserver
{
    public static bool $disableSync = false; // toggle anti-loop para TODOS os syncs

    public function created(Product $product): void
    {
        if (self::$disableSync) return;
        if (! $product->supplier_id) return;

        // Caminho 1: sync para legado (so no hub, LEGACY_SYNC_ENABLED gate no job)
        SyncProductToLegacy::dispatch($product->id, 'upsert');

        // Caminho 2: push para hub via federacao (so nos WLs)
        $this->maybePushToHub($product);
    }

    public function updated(Product $product): void
    {
        if (self::$disableSync) return;
        if (! $product->supplier_id) return;

        // Caminho 1: sync para legado
        SyncProductToLegacy::dispatch($product->id, 'upsert');

        // Caminho 2: push para hub via federacao (so nos WLs)
        $this->maybePushToHub($product);
    }

    public function deleted(Product $product): void
    {
        if (self::$disableSync) return;
        if (! $product->legacy_sku_pai_id) return;
        SyncProductToLegacy::dispatch($product->id, 'delete', $product->legacy_sku_pai_id);
        // Nota: delete nao propagado via federacao nesta versao (NOV-171-C)
    }

    /**
     * Gate de federacao: dispara FederationPushProductJob se:
     * 1. Estamos num WL (federation.tenant != 'hubai')
     * 2. Produto e LOCAL (federation_source IS NULL) -- nao veio do hub
     * 3. hub_url de federacao esta configurado
     *
     * Produto recebido do hub (federation_source != null) NAO e re-enviado (anti-loop).
     */
    private function maybePushToHub(Product $product): void
    {
        // Apenas WLs empurram para o hub
        // usa config('federation.tenant') -- config('app.tenant') nao existe em app.php
        if (config('federation.tenant', 'hubai') === 'hubai') {
            return;
        }

        // Sem configuracao de hub, nao ha para onde empurrar
        if (! config('federation.hub_url')) {
            return;
        }

        // MUL-394: o gate por federation_source ficava aqui e travava TODA edicao manual.
        // Como 100% dos produtos das WLs chegam do hub (federation_source='hubai'), o WL
        // nunca conseguia empurrar de volta -- preco editado no painel morria local e era
        // desfeito no proximo delta hub->WL (5 min).
        //
        // O anti-loop REAL ja existe em dois lugares e continua valendo:
        //   1. FederationReceiveCatalogJob seta ProductObserver::$disableSync antes do
        //      upsert, entao escrita vinda do hub nao dispara este observer (regra 16).
        //   2. SyncTenantSupplierCatalogJob PULA produto cujo federation_source e o slug
        //      do proprio tenant de destino -- o que o WL empurra nao volta pra ele.
        //
        // federation_source e procedencia, nao trava de escrita.
        FederationPushProductJob::dispatch($product->id)->onQueue('default');
    }
}