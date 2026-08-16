<?php

namespace App\Console\Commands;

use App\Jobs\FetchShippingLabelJob;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MES-046-A: Reenfileira pedidos com etiqueta pendente que foram descartados silenciosamente.
 *
 * Busca pedidos com order_processing_status=awaiting_label (ou paid sem label_url)
 * dentro dos ultimos 90 dias:
 *  - Com marketplace_account_id: dispara FetchShippingLabelJob (trigger=backfill)
 *  - Sem marketplace_account_id: grava label_status_reason=missing_marketplace_account
 *
 * Usage:
 *   php artisan labels:requeue-stuck [--supplier=25] [--dry-run] [--days=90]
 */
class RequeueStuckLabelsCommand extends Command
{
    protected $signature = 'labels:requeue-stuck
                            {--supplier= : ID do supplier (opcional, processa todos se omitido)}
                            {--days=90   : Janela em dias para buscar pedidos antigos}
                            {--dry-run   : Apenas conta, nao dispara jobs}
                            {--limit=500 : Limite de pedidos por execucao}';

    protected $description = 'MES-046-A: Reenfileira pedidos awaiting_label descartados e grava motivo nos sem marketplace_account';

    /** Mapa de motivos legivel para log */
    private const REASON_MAP = [
        FetchShippingLabelJob::REASON_MISSING_MARKETPLACE_ACCOUNT => 'Sem conta marketplace vinculada',
        FetchShippingLabelJob::REASON_AWAITING_MARKETPLACE        => 'Aguardando marketplace liberar',
        FetchShippingLabelJob::REASON_PAYMENT_PENDING             => 'Pagamento pendente',
        FetchShippingLabelJob::REASON_INVOICE_REQUIRED            => 'NF-e necessaria',
        FetchShippingLabelJob::REASON_TOKEN_ERROR                 => 'Erro de token/autenticacao',
        FetchShippingLabelJob::REASON_FISCAL_DATA_MISSING         => 'Dados fiscais incompletos',
        FetchShippingLabelJob::REASON_ALREADY_SHIPPED             => 'Encomenda ja despachada (terminal)',
        FetchShippingLabelJob::REASON_TRACKING_INVALID            => 'Rastreio invalidado (terminal)',
        FetchShippingLabelJob::REASON_LABEL_UNAVAILABLE           => 'Marketplace recusa a etiqueta (terminal)',
    ];

    public function handle(): int
    {
        $supplierId = $this->option('supplier') ? (int) $this->option('supplier') : null;
        $days       = (int) $this->option('days');
        $dryRun     = (bool) $this->option('dry-run');
        $limit      = (int) $this->option('limit');

        $this->info("[MES-046-A] Buscando pedidos awaiting_label" . ($supplierId ? " (supplier={$supplierId})" : '') . " dos ultimos {$days} dias...");

        $query = Order::query()
            ->where('order_processing_status', 'awaiting_label')
            ->whereNull('label_url')
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('id');

        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        $total   = $query->count();
        $this->info("Total encontrado: {$total}");

        $withAccount    = 0;
        $withoutAccount = 0;
        $dispatched     = 0;
        $reasonCounts   = [];

        $query->limit($limit)->chunk(50, function ($orders) use (
            $dryRun, &$withAccount, &$withoutAccount, &$dispatched, &$reasonCounts
        ) {
            foreach ($orders as $order) {
                if ($order->marketplace_account_id) {
                    $withAccount++;
                    if (!$dryRun) {
                        FetchShippingLabelJob::dispatch($order->id, 'backfill')
                            ->delay(now()->addSeconds(rand(1, 30))); // spread para evitar burst
                        $dispatched++;
                    }
                } elseif ($order->source !== 'bling') {
                    // MUL-288: pedido Bling nao tem marketplace_account_id por desenho (conta em
                    // erp_accounts via supplier_id). Marcar aqui gera CTA falso no painel do seller.
                    $withoutAccount++;
                    $reason = FetchShippingLabelJob::REASON_MISSING_MARKETPLACE_ACCOUNT;
                    $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;

                    if (!$dryRun) {
                        $order->updateQuietly([
                            'label_status_reason' => $reason,
                            'label_error_at'      => now(),
                        ]);
                    }
                }
            }
        });

        $mode = $dryRun ? '[DRY RUN]' : '';
        $this->info("{$mode} Com marketplace_account_id: {$withAccount} → FetchShippingLabelJob disparado: {$dispatched}");
        $this->info("{$mode} Sem marketplace_account_id: {$withoutAccount} → label_status_reason gravado");

        foreach ($reasonCounts as $reason => $count) {
            $label = self::REASON_MAP[$reason] ?? $reason;
            $this->line("  - {$reason} ({$label}): {$count}");
        }

        Log::info('[MES-046-A] labels:requeue-stuck', [
            'supplier_id'    => $supplierId,
            'days'           => $days,
            'dry_run'        => $dryRun,
            'total'          => $total,
            'with_account'   => $withAccount,
            'without_account'=> $withoutAccount,
            'dispatched'     => $dispatched,
            'reason_counts'  => $reasonCounts,
        ]);

        $this->info('Concluido.');
        return 0;
    }
}
