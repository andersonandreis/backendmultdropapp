<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * INF-023 - Backfill incremental de supplier_unit_cost.
 * Extraido do SyncLegacyOrdersJob que fazia N+1 queries causando 384 failed_jobs.
 * Cursor persistente + batch 200 pedidos/execucao = completa em <30s.
 */
class BackfillOrderCostsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('legacy-import');
    }

    public int $timeout = 120;
    public int $tries = 1;
    const BATCH_SIZE = 200;
    const LEGACY_CHUNK = 50;

    public function handle(): void
    {
        $lock = Cache::lock('backfill-order-costs', 130);
        if (!$lock->get()) {
            Log::info('BackfillOrderCostsJob: outra instancia em execucao, pulando.');
            return;
        }
        try {
            $this->run();
        } finally {
            $lock->release();
        }
    }

    private function run(): void
    {
        $cursorKey = 'backfill_order_costs_cursor';
        $lastId    = (int) Cache::get($cursorKey, 0);
        $startedAt = now();
        $filled    = 0;
        $skipped   = 0;
        $maxId     = 0;
        $processed = 0;

        $orderIds = DB::table('orders as o')
            ->join('order_items as oi', 'oi.order_id', '=', 'o.id')
            ->where('o.id', '>', $lastId)
            ->whereNotNull('o.legacy_id')
            ->whereNull('oi.supplier_unit_cost')
            ->select('o.id', 'o.legacy_id')
            ->groupBy('o.id', 'o.legacy_id')
            ->orderBy('o.id')
            ->limit(self::BATCH_SIZE)
            ->get();

        if ($orderIds->isEmpty()) {
            Cache::forget($cursorKey);
            Log::info('BackfillOrderCostsJob: nenhum pedido pendente, cursor resetado.');
            return;
        }

        $legacyIdMap = $orderIds->pluck('legacy_id', 'id');
        $legacyIds   = $legacyIdMap->values()->all();
        $legacyCosts = collect();

        foreach (array_chunk($legacyIds, self::LEGACY_CHUNK) as $sub) {
            $rows = DB::connection('legacy')
                ->table('pedidos_produtos')
                ->whereIn('id_pedido', $sub)
                ->select(['id_pedido', 'id_sku_pai', 'sku', 'custo_dia', 'custo_pago_dia'])
                ->get();
            foreach ($rows as $r) {
                if (!isset($legacyCosts[$r->id_pedido])) {
                    $legacyCosts[$r->id_pedido] = collect();
                }
                $legacyCosts[$r->id_pedido]->push($r);
            }
        }

        foreach ($orderIds as $row) {
            $processed++;
            $maxId = max($maxId, $row->id);

            $legacyItems = $legacyCosts[$row->legacy_id] ?? collect();
            if ($legacyItems->isEmpty()) { $skipped++; continue; }

            $orderItemsToFill = DB::table('order_items')
                ->where('order_id', $row->id)
                ->whereNull('supplier_unit_cost')
                ->select(['id', 'legacy_sku_pai_id', 'sku', 'quantity'])
                ->get();

            if ($orderItemsToFill->isEmpty()) { $skipped++; continue; }

            $newSupplierTotal = 0.0;
            foreach ($orderItemsToFill as $oi) {
                $li = null;
                if ($oi->legacy_sku_pai_id) {
                    $li = $legacyItems->firstWhere('id_sku_pai', $oi->legacy_sku_pai_id);
                }
                if (!$li && $oi->sku) {
                    $li = $legacyItems->firstWhere('sku', $oi->sku);
                }
                if (!$li) continue;

                $unitCost = (float) ($li->custo_dia ?? 0);
                if ($unitCost <= 0) $unitCost = (float) ($li->custo_pago_dia ?? 0);
                if ($unitCost <= 0) continue;

                $qty = max(1, (int) ($oi->quantity ?? 1));
                DB::table('order_items')->where('id', $oi->id)->update([
                    'supplier_unit_cost'  => $unitCost,
                    'supplier_total_cost' => round($unitCost * $qty, 2),
                    'updated_at'          => now(),
                ]);
                $newSupplierTotal += $unitCost * $qty;
                $filled++;
            }

            if ($newSupplierTotal > 0) {
                $currentTotal = DB::table('orders')->where('id', $row->id)->value('supplier_total');
                if (!$currentTotal || $currentTotal == 0) {
                    DB::table('orders')->where('id', $row->id)->update([
                        'supplier_total' => round($newSupplierTotal, 2),
                        'updated_at'     => now(),
                    ]);
                }
            }
        }

        if ($maxId > 0) {
            Cache::put($cursorKey, $maxId, now()->addHours(72));
        }

        Log::info(sprintf(
            'BackfillOrderCostsJob: %d processados, %d itens preenchidos, %d pulados, cursor=%d, %dms',
            $processed, $filled, $skipped, $maxId, (int) $startedAt->diffInMilliseconds(now())
        ));
    }
}
