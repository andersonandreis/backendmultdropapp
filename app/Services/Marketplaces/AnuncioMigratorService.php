<?php

namespace App\Services\Marketplaces;

use App\Models\MarketplaceAccount;
use App\Services\Integrations\Marketplaces\MercadoLivreService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOV-134 — Migrador de anúncios.
 * Copia um anúncio existente de uma conta ML para outra conta ML (do mesmo dono).
 */
class AnuncioMigratorService
{
    public function __construct(protected MercadoLivreService $ml) {}

    /**
     * @return array{ok:bool,message:string,new_listing_id?:string}
     */
    public function migrate(int $fromAccountId, int $toAccountId, string $listingId): array
    {
        $from = MarketplaceAccount::query()->find($fromAccountId);
        $to   = MarketplaceAccount::query()->find($toAccountId);

        if (!$from || !$to) {
            return ['ok' => false, 'message' => 'Conta de origem ou destino não encontrada.'];
        }
        if ($from->supplier_id !== $to->supplier_id) {
            return ['ok' => false, 'message' => 'As duas contas devem pertencer ao mesmo supplier.'];
        }
        if ($from->marketplace !== 'mercadolivre' || $to->marketplace !== 'mercadolivre') {
            return ['ok' => false, 'message' => 'Migrador suporta apenas Mercado Livre por enquanto.'];
        }

        $fromToken = $this->ml->getAccessToken($from);
        $toToken   = $this->ml->getAccessToken($to);
        if (!$fromToken || !$toToken) {
            return ['ok' => false, 'message' => 'Tokens inválidos. Reautorize as contas.'];
        }

        try {
            // 1) Busca o anúncio na conta origem
            $resp = Http::withToken($fromToken)->timeout(30)
                ->get('https://api.mercadolibre.com/items/'.$listingId);
            if (!$resp->successful()) {
                return ['ok' => false, 'message' => 'Anúncio não encontrado: '.$resp->status()];
            }
            $item = $resp->json();

            // 2) Prepara payload (remove campos próprios da conta origem)
            $payload = array_filter([
                'title'             => $item['title'] ?? null,
                'category_id'       => $item['category_id'] ?? null,
                'price'             => $item['price'] ?? null,
                'currency_id'       => $item['currency_id'] ?? 'BRL',
                'available_quantity'=> $item['available_quantity'] ?? 1,
                'buying_mode'       => $item['buying_mode'] ?? 'buy_it_now',
                'listing_type_id'   => $item['listing_type_id'] ?? 'gold_special',
                'condition'         => $item['condition'] ?? 'new',
                'description'       => $item['description'] ?? null,
                'pictures'          => array_map(fn($p) => ['source' => $p['url']], $item['pictures'] ?? []),
                'attributes'        => $item['attributes'] ?? [],
                'sale_terms'        => $item['sale_terms'] ?? [],
                'shipping'          => isset($item['shipping']) ? array_filter([
                    'mode' => $item['shipping']['mode'] ?? null,
                    'local_pick_up' => $item['shipping']['local_pick_up'] ?? null,
                    'free_shipping' => $item['shipping']['free_shipping'] ?? null,
                ]) : null,
            ]);

            // 3) Publica na conta destino
            $createResp = Http::withToken($toToken)->timeout(30)
                ->post('https://api.mercadolibre.com/items', $payload);

            if (!$createResp->successful()) {
                Log::warning('[NOV-134] Falha publicar', ['body' => $createResp->body()]);
                return ['ok' => false, 'message' => 'Falha publicar: '.$createResp->status().' '.substr($createResp->body(), 0, 300)];
            }

            $newId = $createResp->json('id');
            Log::info('[NOV-134] Migração concluída', [
                'from_listing' => $listingId,
                'new_listing'  => $newId,
                'from_account' => $fromAccountId,
                'to_account'   => $toAccountId,
            ]);

            return [
                'ok' => true,
                'message' => 'Anúncio migrado com sucesso',
                'new_listing_id' => $newId,
            ];
        } catch (\Throwable $e) {
            Log::error('[NOV-134] Erro migração', ['err' => $e->getMessage()]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
