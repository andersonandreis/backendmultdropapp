<?php

namespace App\Jobs;

use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * AutoLinkMarketplaceProductsJob
 *
 * Varre client_products sem product_id vinculado e cruza com a tabela
 * products do fornecedor usando 3 estrategias em cascata:
 *
 *   1. SKU exato (case-insensitive, trim)
 *   2. SKU normalizado (remove -, _, espacos)
 *   3. Similaridade de titulo (palavras-chave principais, >= 4 chars)
 *
 * Criado em 2026-06-21 (NOV-030): auto-vinculacao de produtos importados
 * dos marketplaces ao catalogo de fornecedores do HubAI.
 *
 * Disparado por:
 *   - ImportMarketplaceAccountDataJob (apos importar produtos + pedidos)
 *   - Cron horario (para contas com produtos ainda sem vinculo)
 */
class AutoLinkMarketplaceProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 180;

    public function __construct(
        public readonly ?int $accountId = null
    ) {}

    public function handle(): void
    {
        $query = ClientProduct::whereNull('product_id')
            ->whereNotNull('external_listing_id')
            ->where(function ($q) {
                $q->whereNotNull('custom_sku')
                  ->orWhereNotNull('custom_title');
            });

        if ($this->accountId !== null) {
            $query->where('marketplace_account_id', $this->accountId);
        }

        $unlinked = $query->get();

        if ($unlinked->isEmpty()) {
            Log::channel('marketplace')->info('[AutoLinkMarketplaceProductsJob] Nenhum produto sem vinculo', [
                'account_id' => $this->accountId,
            ]);
            return;
        }

        Log::channel('marketplace')->info('[AutoLinkMarketplaceProductsJob] Iniciando vinculacao', [
            'account_id'  => $this->accountId,
            'total_items' => $unlinked->count(),
        ]);

        $linked  = 0;
        $skipped = 0;

        foreach ($unlinked as $cp) {
            $productId = $this->findProduct($cp);

            if ($productId) {
                $cp->update(['product_id' => $productId]);
                $linked++;
            } else {
                $skipped++;
            }
        }

        Log::channel('marketplace')->info('[AutoLinkMarketplaceProductsJob] Concluido', [
            'account_id' => $this->accountId,
            'linked'     => $linked,
            'skipped'    => $skipped,
        ]);
    }

    private function findProduct(ClientProduct $cp): ?int
    {
        // Estrategia 1: SKU exato (case-insensitive)
        if ($cp->custom_sku) {
            $sku = trim($cp->custom_sku);
            // NOV-210: collation utf8mb4_unicode_ci é case-insensitive — match direto usa o índice unique
            $product = Product::where('sku', $sku)
                ->where('is_active', true)
                ->first(['id']);

            if ($product) {
                Log::channel('marketplace')->debug('[AutoLink] SKU exato', [
                    'cp_id'      => $cp->id,
                    'sku'        => $sku,
                    'product_id' => $product->id,
                ]);
                return $product->id;
            }

            // Estrategia 2: SKU normalizado (sem -, _, espacos)
            $skuNorm = preg_replace('/[\-_\s]+/', '', strtoupper($sku));
            if (strlen($skuNorm) >= 3) {
                // NOV-210: coluna gerada sku_normalized (mesma expressão) com índice
                $product = Product::where('sku_normalized', $skuNorm)
                    ->where('is_active', true)->first(['id']);

                if ($product) {
                    Log::channel('marketplace')->debug('[AutoLink] SKU normalizado', [
                        'cp_id'      => $cp->id,
                        'sku_norm'   => $skuNorm,
                        'product_id' => $product->id,
                    ]);
                    return $product->id;
                }
            }
        }

        // Estrategia 3: Similaridade de titulo (palavras >= 4 chars)
        if ($cp->custom_title) {
            $productId = $this->matchByTitle($cp->custom_title);
            if ($productId) {
                Log::channel('marketplace')->debug('[AutoLink] Titulo matched', [
                    'cp_id'      => $cp->id,
                    'title'      => $cp->custom_title,
                    'product_id' => $productId,
                ]);
                return $productId;
            }
        }

        return null;
    }

    private function matchByTitle(string $title): ?int
    {
        $stopWords = [
            'para', 'com', 'sem', 'por', 'mais', 'preta', 'preto', 'branca',
            'branco', 'azul', 'rosa', 'verde', 'unidade', 'pack', 'novo',
            'nova', 'original', 'produto', 'item', 'kit',
        ];

        $words    = preg_split('/\s+/', mb_strtolower($title));
        $keywords = array_filter(
            $words,
            fn ($w) => mb_strlen($w) >= 4 && !in_array($w, $stopWords, true)
        );
        $keywords = array_slice(array_values($keywords), 0, 3);

        if (empty($keywords)) {
            return null;
        }

        $query = Product::where('is_active', true);

        // NOV-210: pré-filtro FULLTEXT (ft_products_name) reduz candidatos antes
        // dos LIKEs; sem ele cada chamada varria a tabela inteira de products
        $ftTerms = [];
        foreach ($keywords as $kw) {
            $clean = preg_replace('/[+\-<>()~*"@]/u', '', $kw);
            if ($clean !== null && mb_strlen($clean) >= 3) {
                $ftTerms[] = '+' . $clean . '*';
            }
        }
        if (!empty($ftTerms)) {
            $query->whereRaw('MATCH(name) AGAINST(? IN BOOLEAN MODE)', [implode(' ', $ftTerms)]);
        }

        foreach ($keywords as $kw) {
            $query->where('name', 'like', '%' . $kw . '%');
        }

        return $query->first(['id'])?->id;
    }
}
