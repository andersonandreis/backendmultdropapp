<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Services\Webhooks\LegacyMLRelayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-processa pedidos ML existentes sem legacy_id fazendo relay ao bridge do legado.
 *
 * Criado em 2026-06-23 (HUB-ML-RELAY) para recuperar 30 pedidos ML que foram criados
 * antes do fix que captura legacy_order_id da resposta do bridge.
 *
 * Apenas pedidos com marketplace_account_id preenchido e conta com legacy_id_login
 * sao processados -- os sem conta sao importacoes manuais antigas sem relay possivel.
 *
 * Uso: php artisan orders:repair-ml-legacy-id [--dry-run] [--client-id=X]
 */
class RepairMLLegacyIdCommand extends Command
{
    protected $signature = 'orders:repair-ml-legacy-id
                            {--dry-run : Mostra o que seria feito sem executar}
                            {--client-id= : Processa apenas pedidos de um client_id especifico}';

    protected $description = 'Re-processa pedidos ML sem legacy_id fazendo relay ao bridge do legado';

    public function handle(LegacyMLRelayService $relay): int
    {
        $dryRun   = $this->option('dry-run');
        $clientId = $this->option('client-id');

        $this->info('[RepairMLLegacyId] Buscando pedidos ML sem legacy_id...');

        $query = Order::where('source', 'mercadolivre')
            ->whereNull('legacy_id')
            ->whereNotNull('marketplace_account_id');

        if ($clientId) {
            $query->where('client_id', (int) $clientId);
        }

        $orders = $query->get();

        $this->info("Encontrados {$orders->count()} pedidos para processar.");

        if ($dryRun) {
            $this->warn('[DRY-RUN] Nenhuma alteracao sera feita.');
        }

        // Pre-carregar contas para evitar N+1
        $accountIds = $orders->pluck('marketplace_account_id')->filter()->unique()->all();
        $accounts   = MarketplaceAccount::whereIn('id', $accountIds)
            ->with('client')
            ->get()
            ->keyBy('id');

        $relayed  = 0;
        $updated  = 0;
        $skipped  = 0;
        $noLegacy = 0;
        $failed   = 0;

        foreach ($orders as $order) {
            $account  = $accounts->get($order->marketplace_account_id);
            $client   = $account?->client;
            $legacyId = $client?->legacy_id_login;

            if (!$account || !$legacyId) {
                $this->line("  SKIP order #{$order->id}: conta sem legacy_id_login");
                $noLegacy++;
                continue;
            }

            // Monta o resource no formato que o bridge espera: /orders/<ml_order_id>
            $mlOrderId = $order->external_order_id ?? $order->marketplace_order_id;
            if (!$mlOrderId) {
                $this->line("  SKIP order #{$order->id}: sem ml_order_id");
                $skipped++;
                continue;
            }

            $resource = "/orders/{$mlOrderId}";
            $topic    = 'orders_v2';

            $this->line("  Relay order #{$order->id} (ML {$mlOrderId}) -> legacy user {$legacyId}...");

            if ($dryRun) {
                $relayed++;
                continue;
            }

            try {
                // Payload minimo para o bridge identificar o pedido
                $payload = [
                    'resource'  => $resource,
                    'topic'     => $topic,
                    'user_id'   => $account->ml_user_id,
                    'legacy_id' => $legacyId,
                ];

                $legacyOrderId = $relay->relayIfLegacy($topic, $resource, (string) $account->ml_user_id, $payload);

                if ($legacyOrderId) {
                    $this->info("    OK: legacy_order_id={$legacyOrderId}");
                    $updated++;
                } else {
                    $this->warn("    Bridge nao retornou legacy_order_id (bridge offline ou pedido nao encontrado no legado)");

                    Log::warning('[RepairMLLegacyId] Bridge nao retornou legacy_order_id', [
                        'order_id'    => $order->id,
                        'ml_order_id' => $mlOrderId,
                        'legacy_id'   => $legacyId,
                    ]);

                    $failed++;
                }

                $relayed++;

                // Pequeno delay para nao sobrecarregar o bridge
                usleep(200000); // 200ms

            } catch (\Throwable $e) {
                $this->error("    ERRO: " . $e->getMessage());
                Log::error('[RepairMLLegacyId] Excecao ao processar pedido', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
                $failed++;
                $relayed++;
            }
        }

        $this->newLine();
        $this->info('Resultado:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total relayed',           $relayed],
                ['legacy_id atualizado',    $updated],
                ['Sem legacy_id_login',     $noLegacy],
                ['Sem ml_order_id',         $skipped],
                ['Bridge sem retorno/erro', $failed],
            ]
        );

        // Conta final no banco
        $remaining = Order::where('source', 'mercadolivre')->whereNull('legacy_id')->count();
        $this->info("ML pedidos ainda sem legacy_id no banco: {$remaining}");

        return self::SUCCESS;
    }
}
