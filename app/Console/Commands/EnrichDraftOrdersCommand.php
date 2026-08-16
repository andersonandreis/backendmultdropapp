<?php

namespace App\Console\Commands;

use App\Jobs\EnrichBlingOrderJob;
use App\Jobs\EnrichMercadoLivreOrderJob;
use App\Jobs\EnrichShopeeOrderJob;
use App\Models\Order;
use App\Services\Orders\DraftOrderPromoter;
use App\Support\DraftReasonClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MUL-197 / INF-036 — Enriquecimento automatico dos rascunhos pendentes (scheduler).
 * Cascas nunca mais ficam orfas: roda a cada 15 min (routes/console.php).
 *
 * Roteamento por source (INF-036 B):
 *   - shopee       → EnrichShopeeOrderJob (get_order_detail + promocao)
 *   - mercadolivre → EnrichMercadoLivreOrderJob (GET /orders/{id})
 *   - bling        → EnrichBlingOrderJob (GET /pedidos/vendas/{id})
 *   - outras       → re-check via DraftOrderPromoter (sem API)
 *
 * Classificacao por draft_reason (INF-036 A) — DraftReasonClassifier:
 *   - PERMANENT (order_not_found, token_unavailable, no_cost_source, etc):
 *     SKIP no scheduler; so processa via --force
 *   - TRANSIENT (invalid_token, api_error, empty_response):
 *     dispatch com cooldown normal
 *   - PENDING_DATA (observer_safety_net, awaiting_hub_relay, incomplete:*):
 *     dispatch com cooldown normal
 *
 * Cooldown 30 min entre tentativas + teto (--max-attempts, default 20) pra nao
 * martelar API com casca irrecuperavel. Endpoint manual ignora o teto (--force).
 *
 * INF-037 — resolucao definitiva de exauridos (resolveExhausted):
 * rascunho que estourou o teto ou esta PERMANENT ha 24h+ NAO fica preso na
 * lista em loop. NADA e deletado (decisao Ruan 10/07) — so muda status.
 * Desfechos, nesta ordem, todos registrados em order_events:
 *   1. promote normal (dados podem ter chegado por outro caminho);
 *   2. completo exceto paid_at → flip direto paid_at=created_at (sem AutoPay);
 *   3. resto → is_draft=0 + draft_reason=enrichment_exhausted (sai da lista
 *      de rascunhos e do ciclo, mas continua no banco pra auditoria).
 */
class EnrichDraftOrdersCommand extends Command
{
    protected $signature = 'orders:enrich-drafts
                            {--limit=100 : Maximo de rascunhos por execucao}
                            {--max-attempts=20 : Teto de tentativas automaticas por pedido}
                            {--force : Ignora cooldown, teto e skip PERMANENT (backfill manual)}';

    protected $description = 'MUL-197/INF-036: enriquece rascunhos por source (Shopee/ML/Bling via API; demais via re-check)';

    public function handle(DraftOrderPromoter $promoter): int
    {
        $limit       = max(1, (int) $this->option('limit'));
        $maxAttempts = max(1, (int) $this->option('max-attempts'));
        $force       = (bool) $this->option('force');

        // INF-037: ordenacao justa — quem nunca foi tentado vem primeiro (novos
        // rascunhos enriquecem na hora), depois o menos recentemente tentado.
        // orderBy('id') puro deixava backlog antigo monopolizar o limit e
        // rascunhos novos NUNCA entravam na janela (starvation).
        $query = Order::where('is_draft', 1)
            ->orderBy('enrich_attempts')
            ->orderByRaw('last_enriched_at IS NULL DESC')
            ->orderBy('last_enriched_at')
            ->orderByDesc('id');

        if (! $force) {
            $query->where('enrich_attempts', '<', $maxAttempts)
                ->where(function ($w) {
                    $w->whereNull('last_enriched_at')
                      ->orWhere('last_enriched_at', '<', now()->subMinutes(30));
                });
        }

        $drafts = $query->limit($limit)->get();

        $dispatched     = 0;
        $promoted       = 0;
        $stillDraft     = 0;
        $skippedPermanent = 0;
        $dispatchIndex  = 0;
        $byClass        = ['transient' => 0, 'pending_data' => 0, 'permanent' => 0];

        foreach ($drafts as $order) {
            $class = DraftReasonClassifier::classify($order->draft_reason);
            $byClass[$class] = ($byClass[$class] ?? 0) + 1;

            // INF-036 A: PERMANENT nao entra em ciclo automatico (a menos que --force).
            if ($class === DraftReasonClassifier::PERMANENT && ! $force) {
                $skippedPermanent++;
                continue;
            }

            // INF-036 B: roteamento por source. Bling resolve contas via
            // marketplace_accounts do supplier dentro do job (fix 1944b99).
            $canApi = match ($order->source) {
                'shopee', 'mercadolivre' => $order->marketplace_order_id && $order->marketplace_account_id,
                'bling'                  => (bool) $order->external_order_id,
                default                  => false,
            };

            if ($canApi) {
                switch ($order->source) {
                    case 'shopee':
                        // Espacar 2s entre dispatches pra nao estourar rate limit
                        EnrichShopeeOrderJob::dispatch($order->id)
                            ->delay(now()->addSeconds(2 * $dispatchIndex));
                        $dispatched++;
                        $dispatchIndex++;
                        continue 2;
                    case 'mercadolivre':
                        EnrichMercadoLivreOrderJob::dispatch($order->id)
                            ->delay(now()->addSeconds(2 * $dispatchIndex));
                        $dispatched++;
                        $dispatchIndex++;
                        continue 2;
                    case 'bling':
                        EnrichBlingOrderJob::dispatch($order->id)
                            ->delay(now()->addSeconds(2 * $dispatchIndex));
                        $dispatched++;
                        $dispatchIndex++;
                        continue 2;
                }
            }

            // Fallback: re-check via promoter (sem API).
            // INF-037: incrementa enrich_attempts no fracasso — sem isso o
            // rascunho sem conta/marketplace_order_id ficava em loop eterno
            // com attempts=0 e nunca chegava no teto.
            try {
                [$ok] = $promoter->promote($order, 'scheduler_recheck');
                $ok ? $promoted++ : $stillDraft++;
            } catch (\Throwable $e) {
                $ok = false;
                $stillDraft++;
                Log::channel('marketplace')->warning('[INF-036] recheck de rascunho falhou', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }

            if (! $ok) {
                $order->enrich_attempts  = (int) $order->enrich_attempts + 1;
                $order->last_enriched_at = now();
                $order->saveQuietly();
            }
        }

        // INF-037: rascunhos exauridos nao ficam presos na lista.
        $resolved = $force ? ['promoted' => 0, 'flipped' => 0, 'marked' => 0]
            : $this->resolveExhausted($promoter, $maxAttempts);

        $this->info(
            "Rascunhos: {$drafts->count()} processados — "
            . "{$dispatched} jobs despachados, {$promoted} promovidos no re-check, "
            . "{$stillDraft} continuam rascunho, {$skippedPermanent} skip PERMANENT. "
            . "Exauridos: {$resolved['promoted']} promovidos, {$resolved['flipped']} flip paid_at, "
            . "{$resolved['marked']} marcados exhausted (fora da lista)."
        );

        Log::channel('marketplace')->info('[INF-036] orders:enrich-drafts concluido', [
            'total'             => $drafts->count(),
            'dispatched'        => $dispatched,
            'promoted'          => $promoted,
            'still'             => $stillDraft,
            'skipped_permanent' => $skippedPermanent,
            'by_class'          => $byClass,
            'exhausted'         => $resolved,
        ]);

        return self::SUCCESS;
    }

    /**
     * INF-037: da desfecho definitivo a rascunhos exauridos (teto de tentativas
     * estourado) ou PERMANENT parados ha 24h+. NADA e deletado — irrecuperavel
     * sai da lista via is_draft=0 + enrichment_exhausted. Todo desfecho registra
     * o motivo em order_events (batch INF-037-auto-resolve) pra auditoria.
     *
     * @return array{promoted:int,flipped:int,marked:int}
     */
    private function resolveExhausted(DraftOrderPromoter $promoter, int $maxAttempts): array
    {
        $out = ['promoted' => 0, 'flipped' => 0, 'marked' => 0];

        $candidates = Order::where('is_draft', 1)
            ->where(function ($w) use ($maxAttempts) {
                $w->where('enrich_attempts', '>=', $maxAttempts)
                  ->orWhereNotNull('draft_reason');
            })
            ->where(function ($w) {
                $w->whereNull('draft_reason')
                  ->orWhere('draft_reason', '!=', 'enrichment_exhausted');
            })
            ->limit(100)
            ->get();

        foreach ($candidates as $order) {
            $exhausted = (int) $order->enrich_attempts >= $maxAttempts;
            $permanentStale = DraftReasonClassifier::classify($order->draft_reason) === DraftReasonClassifier::PERMANENT
                && $order->updated_at
                && $order->updated_at->lt(now()->subHours(24));

            if (! $exhausted && ! $permanentStale) {
                continue;
            }

            // 1) Ultima chance de promote normal — dados podem ter chegado
            //    por webhook/sync desde a ultima tentativa.
            try {
                [$ok] = $promoter->promote($order, 'exhausted_recheck');
            } catch (\Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $out['promoted']++;
                continue;
            }

            $itens    = DB::table('order_items')->where('order_id', $order->id)->count();
            $autopay  = $order->client_id
                ? (bool) DB::table('clients')->where('id', $order->client_id)->value('auto_pay_from_wallet')
                : false;
            $complete = $order->customer_name && $order->total > 0 && $itens > 0;

            // 2) Completo exceto paid_at → flip direto (paid_at aproximado =
            //    created_at). Sem promoter = sem AutoPay/fanout; so quando o
            //    client nao tem debito automatico de wallet.
            if ($complete && ! $order->paid_at && ! $autopay
                && in_array($order->status, ['paid', 'completed', 'shipped', 'delivered'], true)) {
                DB::table('orders')->where('id', $order->id)->update([
                    'paid_at'      => $order->created_at,
                    'is_draft'     => 0,
                    'draft_reason' => null,
                ]);
                $this->logOutcome($order, 'draft_promoted',
                    'Auto-resolve INF-037: pedido completo exceto paid_at apos esgotar tentativas de enriquecimento; paid_at aproximado = created_at. Sem AutoPay.');
                // MUL-310: o update acima usa DB::table() e NAO passa pelo Eloquent — nao
                // dispara OrderObserver nem DraftOrderPromoter, e e o promoter quem emite o
                // fanout order.created. Sem esta linha o pedido sai do rascunho e a WL nunca
                // fica sabendo que ele existe (149 pedidos perdidos so no MultDrop em julho).
                $this->emitirCriacao($order->id);
                $out['flipped']++;
                continue;
            }

            // 3) Irrecuperavel → sai da lista de rascunhos SEM deletar nada
            //    (decisao Ruan 10/07): is_draft=0 + marca enrichment_exhausted.
            //    Pedido continua no banco/auditoria; so --force ou analise
            //    manual reprocessa.
            DB::table('orders')->where('id', $order->id)->update([
                'is_draft'     => 0,
                'draft_reason' => 'enrichment_exhausted',
            ]);
            $this->logOutcome($order, 'enrichment_exhausted',
                'Auto-resolve INF-037: tentativas de enriquecimento esgotadas sem desfecho automatico possivel. Removido da lista de rascunhos (is_draft=0, nada deletado); marcado enrichment_exhausted para analise manual.');
            // MUL-310: mesmo motivo do desfecho acima — sair do rascunho tem que avisar a WL.
            $this->emitirCriacao($order->id);
            $out['marked']++;
        }

        return $out;
    }

    /**
     * MUL-310: emite order.created para a WL depois de tirar o pedido do rascunho.
     * Regra do sistema: TODO caminho que zera is_draft precisa emitir order.created,
     * senao o espelho perde o pedido em silencio.
     */
    private function emitirCriacao(int $orderId): void
    {
        try {
            \App\Jobs\FanoutOrderWebhookJob::dispatch($orderId, 'order.created', ['origem' => 'enrich-drafts'])
                ->delay(now()->addSeconds(30));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[MUL-310] fanout pos-enriquecimento falhou', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function logOutcome(Order $order, string $eventType, string $description): void
    {
        DB::table('order_events')->insert([
            'order_id'    => $order->id,
            'event_type'  => $eventType,
            'description' => $description,
            'metadata'    => json_encode([
                'batch'           => 'INF-037-auto-resolve',
                'last_reason'     => $order->draft_reason,
                'enrich_attempts' => (int) $order->enrich_attempts,
            ]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }
}
