<?php

namespace App\Console\Commands\OneOff;

use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use App\Services\MercadoLivreService;
use App\Support\SkuDoAnuncio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FOR-111 Fase 3 — grava SELLER_SKU no anuncio que esta no ar, e SOMENTE isso.
 *
 * Por que um comando proprio em vez de reusar o PublishClientProductToMLJob:
 *
 * O job envia o payload completo — titulo, preco, estoque, descricao, atributos. Medido em
 * 13/08/2026 numa amostra de 240 anuncios de 6 contas:
 *
 *     preco divergente do nosso custom_price : 77 de 240 (32%)
 *        dos quais o ML esta MAIOR             : 75
 *        delta medio                           : +R$ 14,84
 *     estoque divergente                      : 212 de 240 (88%)
 *
 * Republicar em massa DERRUBARIA o preco pela metade em 75 de 77 anuncios, e reescreveria o
 * estoque de quase todos — sendo que o SyncInventoryJob esta desligado por decisao desde que
 * zerou ~35 mil anuncios em 29/05/2026. Um backfill de SKU nao pode ter esse efeito colateral.
 *
 * Aqui o PUT leva um unico campo: attributes[SELLER_SKU]. Verificado em 13/08 no anuncio
 * MLB4960441267 (ativo, 17 vendas): SELLER_SKU gravado, e status, preco, estoque e titulo
 * inalterados.
 */
class BackfillSellerSkuNoMarketplace extends Command
{
    protected $signature = 'sku:backfill-seller-sku
        {--dry-run : So mostrar o que faria, sem tocar no marketplace}
        {--limit=0 : Limitar N anuncios (0 = todos do filtro)}
        {--account= : Restringir a uma marketplace_account}
        {--only-missing : So anuncio que NAO tem SELLER_SKU no ML (default: tambem sobrescreve placeholder como D53)}
        {--only-placeholder : So anuncio cujo custom_sku no BANCO e placeholder/vazio — ou seja, os que recebem o formato novo. Evita carimbar o formato ANTIGO no marketplace e ter de reescrever depois da unificacao}';

    protected $description = 'FOR-111: grava SELLER_SKU no anuncio do Mercado Livre — apenas o atributo, nunca preco/estoque/titulo';

    public function handle(): int
    {
        $dry   = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $q = ClientProduct::query()
            ->whereNotNull('external_listing_id')
            ->where('external_listing_id', 'LIKE', 'MLB%');

        if ($conta = $this->option('account')) {
            $q->where('marketplace_account_id', (int) $conta);
        }
        if ($this->option('only-placeholder')) {
            // FOR-111: so quem nao tem SKU util hoje. Anuncio com SKU no formato antigo
            // (PROD-{produto}-{conta}) fica de fora ate a decisao de unificacao — carimba-lo
            // agora obrigaria a reescrever depois, e o seller veria o SKU mudar duas vezes.
            $q->where(function ($w) {
                $w->whereNull('custom_sku')
                  ->orWhere('custom_sku', '')
                  ->orWhere('custom_sku', 'LIKE', 'ml-%')
                  ->orWhere('custom_sku', 'LIKE', 'shopee-%')
                  ->orWhere('custom_sku', 'REGEXP', '^(MLB|MLA|MLM)[0-9]+$');
            });
        }

        if ($limit > 0) {
            $q->limit($limit);
        }

        $total = (clone $q)->count();
        $this->info(($dry ? '[DRY-RUN] ' : '') . "anuncios no filtro: {$total}");

        $stat = ['ok' => 0, 'ja_tinha' => 0, 'sem_token' => 0, 'nao_editavel' => 0, 'erro' => 0, 'pulado' => 0];
        $tokens = [];

        foreach ($q->cursor() as $cp) {
            $accId = $cp->marketplace_account_id;

            if (! array_key_exists($accId, $tokens)) {
                $acc = MarketplaceAccount::find($accId);
                try {
                    $tokens[$accId] = $acc ? app(MercadoLivreService::class)->getValidToken($acc) : null;
                } catch (\Throwable $e) {
                    $tokens[$accId] = null;
                }
            }
            $token = $tokens[$accId];
            if (! $token) { $stat['sem_token']++; continue; }

            $sku = SkuDoAnuncio::paraAnuncio($cp);
            if ($sku === '') { $stat['pulado']++; continue; }

            $atual = Http::withToken($token)
                ->get("https://api.mercadolibre.com/items/{$cp->external_listing_id}",
                      ['attributes' => 'id,status,attributes']);

            if ($atual->failed()) { $stat['erro']++; continue; }

            $corpo    = $atual->json();
            $statusMl = $corpo['status'] ?? '';
            $skuAtual = null;
            foreach (($corpo['attributes'] ?? []) as $a) {
                if (($a['id'] ?? '') === 'SELLER_SKU') { $skuAtual = (string) ($a['value_name'] ?? ''); }
            }

            // closed nao aceita edicao; os demais (active, paused, under_review) aceitam
            // atualizacao de atributo — verificado em 13/08 num anuncio pausado.
            if ($statusMl === 'closed') { $stat['nao_editavel']++; continue; }

            if ($skuAtual === $sku) { $stat['ja_tinha']++; continue; }

            // --only-missing: nao mexe em anuncio que ja tem QUALQUER valor.
            // Sem a flag, sobrescreve placeholder (o 'D53' e afins), que e o caso comum.
            if ($this->option('only-missing') && $skuAtual !== null && $skuAtual !== '') {
                $stat['pulado']++; continue;
            }

            if ($dry) {
                $this->line(sprintf('  %-15s %-10s %-16s -> %s',
                    $cp->external_listing_id, $statusMl, $skuAtual ?? '(sem)', $sku));
                $stat['ok']++;
                continue;
            }

            $r = Http::withToken($token)->put(
                "https://api.mercadolibre.com/items/{$cp->external_listing_id}",
                ['attributes' => [['id' => 'SELLER_SKU', 'value_name' => $sku]]]
            );

            if ($r->successful()) {
                $stat['ok']++;
                if ($cp->custom_sku !== $sku) {
                    $cp->forceFill(['custom_sku' => $sku])->saveQuietly();
                }
                if (! $cp->ml_external_item_id) {
                    $cp->forceFill(['ml_external_item_id' => $cp->external_listing_id])->saveQuietly();
                }
            } else {
                $stat['erro']++;
                Log::warning('[FOR-111] falha ao gravar SELLER_SKU', [
                    'client_product_id' => $cp->id,
                    'item'              => $cp->external_listing_id,
                    'http'              => $r->status(),
                    'body'              => mb_substr($r->body(), 0, 300),
                ]);
            }

            usleep(120000); // ~8 req/s, folga confortavel no limite do ML
        }

        $this->newLine();
        foreach ($stat as $k => $v) {
            $this->line(sprintf('  %-14s %d', $k, $v));
        }

        return self::SUCCESS;
    }
}
