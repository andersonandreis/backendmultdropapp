<?php

namespace App\Console\Commands;

use App\Models\PlanUpgradeCharge;
use App\Services\Subscription\PlanUpgradeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SEL-UPGRADE (09/08): reconcilia cobrancas de DIFERENCA (upgrade de plano)
 * pendentes — mesmo padrao e mesmo motivo do `pagarme:reconcile-pix`
 * (ReconcilePagarmePixSubscriptions.php): a conta Pagar.me e compartilhada
 * com o HubAI e o webhook configurado no painel aponta pra edge function
 * Supabase do HubAI, NAO pra este backend. Sem este comando, uma cobranca de
 * upgrade paga via PIX nunca seria confirmada automaticamente aqui.
 *
 * NAO cobra nada — so CONSULTA o status no Pagar.me (GET /orders/{id}) e
 * aplica o upgrade local se `status === paid`. Tambem marca como `expired`
 * cobrancas PIX vencidas (expires_at no passado) que nunca foram pagas, pra
 * nao ficarem "pending" pra sempre no admin.
 *
 * Registrar no cron SO depois que a sessao principal ligar a feature
 * (PLAN_UPGRADE_ENABLED=true) — antes disso a tabela plan_upgrade_charges
 * fica vazia e rodar isso e um no-op inofensivo, mas nao ha motivo pra
 * agendar antes de ligar o resto do fluxo.
 */
class ReconcilePlanUpgrades extends Command
{
    protected $signature = 'pagarme:reconcile-plan-upgrades {--minutes=180 : janela de criacao das cobrancas pendentes}';

    protected $description = 'Confirma cobrancas de diferenca (upgrade de plano) pagas via PIX e sobe o plano; expira as vencidas';

    public function handle(PlanUpgradeService $service): int
    {
        $pending = PlanUpgradeCharge::where('status', 'pending')
            ->where('payment_method', 'pix')
            ->where('created_at', '>=', now()->subMinutes((int) $this->option('minutes')))
            ->get();

        $confirmed = 0;
        $expired   = 0;

        foreach ($pending as $charge) {
            // Vencida e nunca paga: marca expired, nao mexe no plano.
            if ($charge->expires_at && $charge->expires_at->isPast()) {
                $charge->update(['status' => 'expired']);
                $expired++;
                continue;
            }

            $order = $service->fetchOrderStatus($charge->gateway_order_id);
            if (!$order) {
                continue;
            }

            if (($order['status'] ?? null) === 'paid') {
                $upgraded = $service->markChargePaidAndUpgrade($charge, $order);
                if ($upgraded) {
                    $confirmed++;
                    $this->info("Upgrade confirmado: charge {$charge->id} (client {$charge->client_id})");
                    Log::info('[ReconcilePlanUpgrades] Upgrade confirmado', [
                        'charge_id' => $charge->id,
                        'client_id' => $charge->client_id,
                        'order_id'  => $charge->gateway_order_id,
                    ]);
                }
            } elseif (in_array($order['status'] ?? null, ['canceled', 'failed'], true)) {
                $charge->update(['status' => 'failed']);
            }
        }

        $this->info("Verificadas: {$pending->count()} | Confirmadas: {$confirmed} | Expiradas: {$expired}");

        return self::SUCCESS;
    }
}
