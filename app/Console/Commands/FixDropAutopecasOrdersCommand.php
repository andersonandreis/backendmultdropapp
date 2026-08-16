<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NOV-038 Fix: Corrige pedidos importados com supplier_id errado.
 *
 * Bug histórico: 21.533 pedidos do Drop Autopeças (id_empresa=20, supplier_id=10)
 * foram importados em 23/06/2026 com supplier_id=25 (MEStoreDrop) e client_ids=511/596.
 *
 * Este comando corrige:
 *   - supplier_id: 25 -> 10
 *   - tenant_slug: 'mestoredrop' -> 'dropautopecas'
 *   - client_id: baseado no legacy login real via integracao.id_login
 */
class FixDropAutopecasOrdersCommand extends Command
{
    protected $signature = 'fix:drop-autopecas-orders
                                {--dry-run   : Simula sem gravar}
                                {--chunk=100 : Tamanho do chunk}';

    protected $description = 'NOV-038: Corrige supplier_id/client_id/tenant_slug dos pedidos do Drop Autopeças';

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $chunkSize = (int) ($this->option('chunk') ?: 100);

        $this->info('NOV-038 Fix: Drop Autopeças orders correction');
        $this->info('dry-run=' . ($dryRun ? 'yes' : 'no'));

        $total    = Order::where('supplier_id', 25)->whereNotNull('legacy_id')->count();
        $this->info("Total orders to fix: {$total}");

        if ($dryRun) {
            $this->warn('--dry-run ativo: nenhum dado sera gravado');
            return self::SUCCESS;
        }

        $fixed   = 0;
        $noLogin = 0;
        $errors  = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Order::where('supplier_id', 25)
            ->whereNotNull('legacy_id')
            ->select(['id', 'legacy_id'])
            ->chunkById($chunkSize, function ($orders) use (&$fixed, &$noLogin, &$errors, $bar) {
                $legacyIds = $orders->pluck('legacy_id')->all();

                // Busca id_integracao dos pedidos legados
                $pedidos = DB::connection('legacy')
                    ->table('pedidos')
                    ->whereIn('id', $legacyIds)
                    ->select(['id', 'id_integracao'])
                    ->get()
                    ->keyBy('id');

                $integIds = $pedidos->pluck('id_integracao')->filter()->unique()->all();

                // Busca id_login de cada integracao
                $integs = DB::connection('legacy')
                    ->table('integracao')
                    ->whereIn('id', $integIds)
                    ->select(['id', 'id_login'])
                    ->get()
                    ->keyBy('id');

                // Busca clients do NovoHubAI por legacy_id_login
                $loginIds = $integs->pluck('id_login')->filter()->unique()->all();
                $clientsByLogin = Client::whereIn('legacy_id_login', $loginIds)
                    ->select(['id', 'legacy_id_login'])
                    ->get()
                    ->keyBy('legacy_id_login');

                foreach ($orders as $order) {
                    try {
                        $pedido    = $pedidos->get($order->legacy_id);
                        $integId   = $pedido ? $pedido->id_integracao : null;
                        $integ     = $integId ? $integs->get($integId) : null;
                        $idLogin   = $integ ? $integ->id_login : null;
                        $client    = $idLogin ? $clientsByLogin->get($idLogin) : null;

                        if (!$client) {
                            $noLogin++;
                            $bar->advance();
                            continue;
                        }

                        DB::table('orders')->where('id', $order->id)->update([
                            'supplier_id'  => 10,
                            'client_id'    => $client->id,
                            'tenant_slug'  => 'dropautopecas',
                            'updated_at'   => now(),
                        ]);

                        $fixed++;
                    } catch (\Throwable $e) {
                        Log::error('fix:drop-autopecas-orders: order_id=' . $order->id . ' ' . $e->getMessage());
                        $errors++;
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);
        $this->info("Corrigidos: {$fixed} | sem login: {$noLogin} | erros: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
