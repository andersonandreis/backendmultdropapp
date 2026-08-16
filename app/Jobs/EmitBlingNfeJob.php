<?php

namespace App\Jobs;

use App\Models\ErpAccount;
use App\Models\Order;
use App\Services\Integrations\Erps\Bling\BlingNfeService;
use App\Services\Integrations\Erps\Bling\BlingOrderSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MUL-276: emissao automatica de NF-e no Bling do fornecedor, condicionada a
 * erp_accounts.nfe_entrada_trigger ('off'|'paid'|'label_printed'|'shipped').
 * UNIDIRECIONAL (MUL-264): so envia pro Bling, nunca importa. Idempotente via
 * BlingNfeService (nota ja autorizada -> skip; numero gravado -> reusa, nunca duplica).
 */
class EmitBlingNfeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [120, 600, 1800];
    public int $timeout = 180;

    public function __construct(
        public readonly int $orderId,
        public readonly string $trigger
    ) {}

    /**
     * Despacha so pros pedidos cujo fornecedor tem conta Bling ativa com
     * nfe_entrada_trigger igual ao evento. Nunca lanca excecao — nao pode
     * quebrar o fluxo de impressao/pagamento que chamou.
     */
    public static function dispatchIfTrigger(int|array $orderIds, string $trigger): void
    {
        try {
            $ids = array_values(array_filter(array_map('intval', (array) $orderIds)));
            if (empty($ids)) {
                return;
            }

            $orders = Order::whereIn('id', $ids)->get(['id', 'supplier_id']);
            $supplierHasTrigger = [];
            foreach ($orders as $o) {
                if (! $o->supplier_id) {
                    continue;
                }
                if (! array_key_exists($o->supplier_id, $supplierHasTrigger)) {
                    $supplierHasTrigger[$o->supplier_id] = ErpAccount::where('supplier_id', $o->supplier_id)
                        ->where('platform', 'bling')
                        ->where('status', 'active')
                        ->where('nfe_entrada_trigger', $trigger)
                        ->exists();
                }
                if ($supplierHasTrigger[$o->supplier_id]) {
                    self::dispatch($o->id, $trigger);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[MUL-276] dispatchIfTrigger falhou', [
                'trigger' => $trigger,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    public function handle(BlingNfeService $nfe, BlingOrderSync $sync): void
    {
        $order = Order::find($this->orderId);
        if (! $order || $order->status === 'cancelled') {
            return;
        }
        if ($order->nfe_entrada_number && $order->nfe_entrada_status === 'authorized') {
            return; // ja emitida
        }

        $erp = ErpAccount::where('supplier_id', $order->supplier_id)
            ->where('platform', 'bling')
            ->where('status', 'active')
            ->where('nfe_entrada_trigger', $this->trigger)
            ->first();
        if (! $erp) {
            return; // config mudou entre o dispatch e a execucao — skip silencioso
        }

        // NF-e exige pedido de venda no Bling; sincroniza antes se preciso
        if (! $order->bling_pedido_id) {
            $res     = $sync->exportSupplierOrder($erp, $order);
            $blingId = $res ? ($res['data']['id'] ?? null) : null;
            if (! $blingId) {
                throw new \RuntimeException("MUL-276: sync pre-NFe falhou pro pedido {$order->id} (export vazio)");
            }
            DB::table('orders')->where('id', $order->id)->update([
                'bling_pedido_id'         => $blingId,
                'bling_pedido_url'        => "https://www.bling.com.br/vendas.php#/venda/{$blingId}",
                'bling_synced_at'         => now(),
                'bling_sync_error'        => null,
                'bling_sync_attempted_at' => now(),
            ]);
            $order->refresh();
        }

        $result = $nfe->emitForOrder($erp, $order);

        Log::info('[MUL-276] NF-e automatica', [
            'order_id' => $order->id,
            'trigger'  => $this->trigger,
            'action'   => $result['action'] ?? null,
            'numero'   => $result['nfe_number'] ?? null,
            'status'   => $result['nfe_status'] ?? null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[MUL-276] EmitBlingNfeJob esgotou tentativas', [
            'order_id' => $this->orderId,
            'trigger'  => $this->trigger,
            'error'    => $e->getMessage(),
        ]);
    }
}
