<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-054: o webhook da conta Pagar.me (compartilhada HubAI/Tokfy) aponta pra
 * edge function Supabase do HubAI, entao POST /api/webhooks/pagarme deste
 * backend nunca recebe charge.paid. Este comando reconcilia: consulta as
 * orders PIX pendentes direto na API Pagar.me e ativa a subscription se paid
 * (mesma logica do PagarmeWebhookController::onChargePaid).
 */
class ReconcilePagarmePixSubscriptions extends Command
{
    protected $signature = 'pagarme:reconcile-pix {--days=14 : janela de criacao das subscriptions}';

    protected $description = 'Ativa subscriptions pendentes cujo pagamento consta paid no Pagar.me (webhook orfao)';

    public function handle(): int
    {
        $key = config('services.pagarme.api_key');
        if (!$key) {
            $this->error('PAGARME_API_KEY ausente');
            return self::FAILURE;
        }

        $subs = Subscription::whereIn('status', ['trialing', 'pending'])
            ->where('pagarme_status', 'pending')
            ->where('pagarme_subscription_id', 'like', 'or\_%')
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->get();

        $activated = 0;
        foreach ($subs as $sub) {
            $resp = Http::withBasicAuth($key, '')
                ->timeout(15)
                ->get('https://api.pagar.me/core/v5/orders/' . $sub->pagarme_subscription_id);
            if (!$resp->ok()) {
                continue;
            }
            $order = $resp->json();
            if (($order['status'] ?? null) !== 'paid') {
                continue;
            }

            $charge = collect($order['charges'] ?? [])->firstWhere('status', 'paid');
            $sub->update([
                'status'               => 'active',
                'pagarme_status'       => 'paid',
                'external_payment_id'  => $charge['id'] ?? $sub->pagarme_subscription_id,
                'current_period_start' => now(),
                'current_period_end'   => now()->addMonth(),
                'trial_ends_at'        => null,
                'cancelled_at'         => null,
            ]);

            $client = Client::find($sub->client_id);
            if ($client) {
                $client->update(array_filter([
                    'is_active'           => true,
                    'pagarme_customer_id' => $order['customer']['id'] ?? null,
                ], fn ($v) => $v !== null));
                User::where('id', $client->user_id)->update(['is_active' => true]);
            }

            $activated++;
            \App\Services\SalePushNotifier::notifySale($sub->id);

            // SEL-META-CEGO (14/08) — MEDIDO: 36 vendas REAIS em 28 dias contra 7
            // eventos de Compra no pixel. O evento so era disparado pelo webhook do
            // Pagar.me, e o proprio codigo deste arquivo admite que "o backend nunca
            // recebe charge.paid" — quem ativa a venda de verdade e ESTE cron, e ele
            // nunca avisava ninguem. Resultado: o Meta otimizava as cegas e o ROAS
            // mentia, enquanto o trafego gastava R$47/dia.
            // Chamo o MESMO metodo do webhook (nao uma copia) pra nao existir duas
            // versoes do rastreamento. O event_id e o charge, entao se o webhook um dia
            // voltar a chegar o Meta deduplica sozinho — nao conta venda dobrada.
            try {
                $emailCliente = $client->email
                    ?? \App\Models\User::where('id', $client?->user_id)->value('email')
                    ?? ($order['customer']['email'] ?? null);
                $valor = ((int) ($charge['amount'] ?? 0)) / 100;

                if ($valor > 0 && $emailCliente) {
                    $wh = app(\App\Http\Controllers\Api\PagarmeWebhookController::class);
                    $wh->dispatchCapiPurchase((string) ($charge['id'] ?? $sub->pagarme_subscription_id), $emailCliente, $valor, $order);
                    $wh->dispatchTiktokPurchase((string) ($charge['id'] ?? $sub->pagarme_subscription_id), $emailCliente, $valor, $order);
                    Log::error('[SEL-META-CEGO] venda reconciliada REPORTADA ao pixel', [
                        'subscription_id' => $sub->id, 'valor' => $valor,
                    ]);
                } else {
                    Log::error('[SEL-META-CEGO] venda ativada mas NAO reportada (falta email ou valor)', [
                        'subscription_id' => $sub->id, 'valor' => $valor, 'tem_email' => (bool) $emailCliente,
                    ]);
                }
            } catch (\Throwable $e) {
                // rastreamento NUNCA derruba a ativacao: o acesso do cliente vem primeiro
                Log::error('[SEL-META-CEGO] falhou ao reportar a venda', [
                    'subscription_id' => $sub->id, 'erro' => mb_substr($e->getMessage(), 0, 160),
                ]);
            }
            Log::info('[ReconcilePagarmePix] Subscription ativada', [
                'subscription_id' => $sub->id,
                'order_id'        => $sub->pagarme_subscription_id,
                'charge_id'       => $charge['id'] ?? null,
            ]);
            $this->info("Ativada subscription {$sub->id} (order {$sub->pagarme_subscription_id})");
        }

        $this->info("Verificadas: {$subs->count()} | Ativadas: {$activated}");
        return self::SUCCESS;
    }
}
