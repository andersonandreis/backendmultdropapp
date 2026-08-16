<?php

namespace App\Console\Commands;

use App\Models\OrderLabelQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MUL-339 — alimenta a fila de verificacao de etiqueta.
 *
 * O CheckLabelAvailabilityJob roda a cada 30 min lendo order_label_queues, e essa tabela so era
 * preenchida por clique manual no admin. Medido em 06/08: 2 linhas no hub (ambas de 02/05, ambas
 * ja 'available') e zero no WL, contra 2.160 pedidos parados em awaiting_marketplace — 82,6%
 * deles ha mais de 3 dias. O job processava nada havia tres meses.
 *
 * O criterio de quem entra e deliberadamente estreito:
 *   - sem label_url e sem etiqueta manual
 *   - nao cancelado
 *   - criado dentro da janela (--dias, padrao 15) — pedido velho demais nao vale re-tentar
 *   - sem linha na fila ainda
 *
 * Fica de fora, e o motivo importa: pedido do Bling sem legacy_id. O ShippingLabelService exige
 * `source === 'bling' && legacy_id` para entrar no checkBlingLabel, e legacy_id e nulo em 100%
 * dos pedidos Bling atuais — enfileirar so gastaria tentativa em caminho que nao existe. Enquanto
 * a origem da etiqueta do Bling nao for definida, esses ficam fora.
 */
class EnqueueLabelChecksCommand extends Command
{
    protected $signature = 'labels:enqueue
                            {--dias=15 : janela de pedidos a considerar}
                            {--limite=500 : maximo por execucao}
                            {--dry-run : so conta, nao escreve}';

    protected $description = 'MUL-339: enfileira pedidos sem etiqueta para o CheckLabelAvailabilityJob';

    public function handle(): int
    {
        $dias   = (int) $this->option('dias');
        $limite = (int) $this->option('limite');
        $dry    = (bool) $this->option('dry-run');

        // ══ SEL-PAGO-NAO-VENCE (16/08) ═══════════════════════════════════════════════
        // A janela de $dias existe pra nao varrer o historico inteiro a cada 30 min. So que
        // pedido PAGO e cliente esperando: se ele passar da janela sem nunca ter entrado na
        // fila, some calado e nunca mais volta. Foi o #1386 — pago em 28/07, R$78,99, 19
        // dias sem etiqueta e sem nunca ter sido enfileirado.
        // Entao: pedido pago entra por idade nenhuma. O resto continua com a janela.
        $candidatos = DB::table('orders as o')
            ->leftJoin('order_label_queues as q', 'q.order_id', '=', 'o.id')
            ->whereNull('q.id')
            ->where(function ($w) use ($dias) {
                $w->where('o.created_at', '>=', now()->subDays($dias))
                  ->orWhere('o.canonical_status', 'paid');
            })
            // Pedido espelho/demonstracao nao existe no marketplace: buscar etiqueta dele e
            // queimar chamada de API pra sempre e esconder o pedido de verdade no meio.
            // (Descoberto na marra: ao deixar 'pago' entrar por idade nenhuma, os 500 demos
            //  entraram junto numa unica execucao.)
            ->whereNull('o.mirror_source_backend')
            ->where(function ($w) {
                $w->whereNull('o.label_url')->orWhere('o.label_url', '');
            })
            ->whereNull('o.manual_label_path')
            ->where(function ($w) {
                $w->whereNull('o.canonical_status')->orWhere('o.canonical_status', '!=', 'cancelled');
            })
            ->where(function ($w) {
                $w->whereNull('o.status')->orWhere('o.status', '!=', 'cancelled');
            })
            // Bling sem legacy_id nao tem caminho de etiqueta hoje — ver a nota da classe
            ->where(function ($w) {
                $w->where('o.source', '!=', 'bling')->orWhereNotNull('o.legacy_id');
            })
            ->orderByDesc('o.created_at')
            ->limit($limite)
            ->pluck('o.id');

        $this->info(sprintf('Pedidos sem etiqueta e fora da fila (janela de %d dias): %d',
            $dias, $candidatos->count()));

        if ($candidatos->isEmpty()) {
            return self::SUCCESS;
        }

        if ($dry) {
            $this->line('Dry-run — ids: ' . $candidatos->take(20)->implode(',') .
                ($candidatos->count() > 20 ? '...' : ''));

            return self::SUCCESS;
        }

        $agora = now();
        $linhas = $candidatos->map(fn ($id) => [
            'order_id'      => $id,
            'status'        => 'pending',
            'next_check_at' => $agora,
            'attempts'      => 0,
            'created_at'    => $agora,
            'updated_at'    => $agora,
        ])->all();

        foreach (array_chunk($linhas, 200) as $lote) {
            DB::table('order_label_queues')->insertOrIgnore($lote);
        }

        Log::warning('[MUL-339] labels:enqueue', [
            'janela_dias' => $dias,
            'enfileirados' => count($linhas),
        ]);
        $this->info('Enfileirados: ' . count($linhas));

        return self::SUCCESS;
    }
}