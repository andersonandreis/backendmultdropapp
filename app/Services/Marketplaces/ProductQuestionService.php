<?php

namespace App\Services\Marketplaces;

use App\Models\MarketplaceAccount;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Services\Integrations\Marketplaces\MercadoLivreService;
use App\Services\Integrations\Marketplaces\ShopeeService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOV-123 — sync e resposta de perguntas de compradores em marketplaces.
 *
 * Estrategia: lazy. Mantemos o servico simples e tolerante. As APIs do ML/Shopee mudam,
 * entao se falhar o sync, gravamos failure_reason e seguimos. O painel Filament permite
 * editar e mandar a resposta manualmente; quando o ML/Shopee responder OK, status=answered.
 */
class ProductQuestionService
{
    public function __construct(
        protected MercadoLivreService $ml,
        protected ShopeeService $shopee,
    ) {}

    /** Sync as perguntas pendentes para todas as contas marketplace do supplier. */
    public function syncForSupplier(int $supplierId): array
    {
        $summary = ['ml' => 0, 'shopee' => 0, 'errors' => []];
        $accounts = MarketplaceAccount::query()
            ->where('supplier_id', $supplierId)
            ->where('status', 'active')
            ->get();
        foreach ($accounts as $account) {
            try {
                if (str_starts_with($account->platform ?? '', 'mercado')) {
                    $summary['ml'] += $this->syncMercadoLivre($account);
                } elseif ($account->platform === 'shopee') {
                    $summary['shopee'] += $this->syncShopee($account);
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = ['account_id' => $account->id, 'msg' => $e->getMessage()];
                Log::warning('[NOV-123] sync failure', ['account_id' => $account->id, 'err' => $e->getMessage()]);
            }
        }
        return $summary;
    }

    protected function syncMercadoLivre(MarketplaceAccount $account): int
    {
        $token = $this->ml->getAccessToken($account);
        if (!$token) return 0;
        // GET /my/received_questions/search?status=UNANSWERED
        $resp = Http::withToken($token)
            ->timeout(20)
            ->get('https://api.mercadolibre.com/my/received_questions/search', [
                'status' => 'UNANSWERED',
                'limit'  => 50,
            ]);
        if (!$resp->successful()) return 0;
        $count = 0;
        foreach ($resp->json('questions') ?? [] as $q) {
            $exists = ProductQuestion::withoutGlobalScopes()
                ->where('marketplace', 'mercadolivre')
                ->where('marketplace_question_id', (string) $q['id'])
                ->exists();
            if ($exists) continue;
            // ══ NOV-PERGUNTA-TEM-DONO (16/08) ═══════════════════════════════════════
            // `product_questions` tinha ZERO linha desde sempre: client_id e product_id sao
            // NOT NULL e o codigo nao mandava client_id e mandava product_id possivelmente
            // nulo. Toda pergunta de comprador do ML era buscada, falhava ao gravar e sumia
            // (1.645 erros so nos logs recentes). O cliente nunca soube que perguntaram.
            //
            // O dono do anuncio sai de client_products, que liga anuncio -> cliente -> produto.
            $itemId = (string) ($q['item_id'] ?? '');
            $anuncio = $itemId === '' ? null : \App\Models\ClientProduct::withoutGlobalScopes()
                ->where(function ($qq) use ($itemId) {
                    $qq->where('external_listing_id', $itemId)
                       ->orWhere('ml_external_item_id', $itemId);
                })
                ->first();

            if (! $anuncio || ! $anuncio->client_id || ! $anuncio->product_id) {
                // Sem anuncio casado nao da pra dizer de QUEM e a pergunta. Antes isso
                // morria calado; agora fica contavel — se aparecer muito, o buraco e o
                // vinculo do anuncio, nao a pergunta.
                \Illuminate\Support\Facades\Log::warning('[NOV-PERGUNTA-SEM-DONO] pergunta do ML sem anuncio casado — nao da pra atribuir', [
                    'item_id'     => $itemId,
                    'question_id' => (string) ($q['id'] ?? ''),
                    'conta'       => $account->id,
                ]);

                continue;
            }

            ProductQuestion::query()->create([
                'supplier_id'              => $account->supplier_id,
                'client_id'                => $anuncio->client_id,
                'product_id'               => $anuncio->product_id,
                'marketplace_account_id'   => $account->id,
                'marketplace'              => 'mercadolivre',
                'marketplace_question_id'  => (string) $q['id'],
                'marketplace_item_id'      => $q['item_id'] ?? null,
                'buyer_name'               => isset($q['from']) ? ($q['from']['nickname'] ?? null) : null,
                'buyer_external_id'        => isset($q['from']) ? (string) ($q['from']['id'] ?? '') : null,
                'question'                 => $q['text'] ?? '',
                'status'                   => 'pending',
                'asked_at'                 => isset($q['date_created']) ? \Carbon\Carbon::parse($q['date_created']) : now(),
            ]);
            $count++;
        }
        return $count;
    }

    protected function syncShopee(MarketplaceAccount $account): int
    {
        // NOV-151: /api/v2/conversation/get_one_conversation retorna 404 para contas sem
        // conversas ativas, gerando log spam de ERROR. Como este endpoint e um stub sem
        // uso funcional real (Q&A Shopee nao implementado), retornamos 0 diretamente.
        // TODO: implementar sync real de Q&A Shopee quando necessario.
        return 0;
    }

    /** Envia a resposta ao marketplace e marca como answered. */
    public function answer(ProductQuestion $q, string $answerText, int $userId): bool
    {
        if (!$q->platform || !$q->platform_question_id) {
            // Pergunta nativa (site) — apenas salva
            $q->update([
                'answer' => $answerText,
                'answered_by_user_id' => $userId,
                'answered_at' => now(),
                'status' => 'answered',
            ]);
            return true;
        }
        try {
            if ($q->platform === 'mercadolivre' && $q->platformAccount) {
                $token = $this->ml->getAccessToken($q->platformAccount);
                if (!$token) throw new \RuntimeException('Sem token ML');
                $resp = Http::withToken($token)
                    ->timeout(20)
                    ->post('https://api.mercadolibre.com/answers', [
                        'question_id' => (int) $q->platform_question_id,
                        'text'        => $answerText,
                    ]);
                if (!$resp->successful()) {
                    throw new \RuntimeException('ML retornou '.$resp->status().': '.$resp->body());
                }
            }
            // Shopee responder requer assinatura — stub que registra mas nao envia
            $q->update([
                'answer' => $answerText,
                'answered_by_user_id' => $userId,
                'answered_at' => now(),
                'status' => 'answered',
                'failure_reason' => null,
            ]);
            return true;
        } catch (\Throwable $e) {
            $q->update([
                'answer' => $answerText,
                'answered_by_user_id' => $userId,
                'answered_at' => now(),
                'status' => 'failed',
                'failure_reason' => substr($e->getMessage(), 0, 500),
            ]);
            return false;
        }
    }
}
