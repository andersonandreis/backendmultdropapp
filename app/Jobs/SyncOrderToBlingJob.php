<?php

namespace App\Jobs;

use App\Models\ErpAccount;
use App\Models\Order;
use App\Services\Integrations\Erps\Bling\BlingOrderSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MUL-274: sync automatico pedido -> Bling do fornecedor quando o pedido e
 * marcado como pago ao fornecedor. Condicionado a erp_accounts.auto_sync_orders=1
 * (toggle do painel, default OFF). UNIDIRECIONAL (regra MUL-264): so envia/atualiza
 * no Bling, nunca puxa. Erro nao quebra o fluxo de pagamento — fica em
 * orders.bling_sync_error com retry do proprio job.
 */
class SyncOrderToBlingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];
    public int $timeout = 120;

    public function __construct(
        public readonly int $orderId,
        public readonly string $trigger = 'paid'
    ) {}

    public function handle(BlingOrderSync $sync): void
    {
        $order = Order::find($this->orderId);
        if (! $order) {
            return;
        }

        $erp = ErpAccount::where('supplier_id', $order->supplier_id)
            ->where('platform', 'bling')
            ->where('status', 'active')
            ->where('auto_sync_orders', 1)
            ->first();
        if (! $erp) {
            return; // toggle OFF ou sem conta Bling ativa — skip silencioso
        }

        try {
            $res = $sync->exportSupplierOrder($erp, $order);
            $blingId = $res ? ($res['data']['id'] ?? $order->bling_pedido_id) : null;

            if ($blingId) {
                // MUL-360 item 11: guardar o NUMERO do pedido de venda como o Bling o
                // conhece — e o que o painel mostra clicavel (a URL do Bling usa numero).
                $numero = $res['data']['numero'] ?? null;
                if (! $numero) {
                    try {
                        $numero = app(\App\Services\Integrations\Erps\Bling\BlingApiClient::class)
                            ->get($erp, "/pedidos/vendas/{$blingId}")['data']['numero'] ?? null;
                    } catch (\Throwable $eNum) {
                        Log::info('[SyncOrderToBling] numero da venda indisponivel: ' . $eNum->getMessage(), ['order_id' => $order->id]);
                    }
                }

                DB::table('orders')->where('id', $order->id)->update(array_filter([
                    'bling_pedido_id'         => $blingId,
                    'bling_order_number'      => $numero,
                    'bling_pedido_url'        => "https://www.bling.com.br/vendas.php#/venda/{$blingId}",
                    'bling_synced_at'         => now(),
                    'bling_sync_attempted_at' => now(),
                ], fn ($v) => $v !== null) + ['bling_sync_error' => null]);
                Log::info("[MUL-274] Pedido {$order->id} auto-sync Bling #{$blingId}", [
                    'trigger' => $this->trigger, 'supplier_id' => $order->supplier_id,
                ]);
                EmitBlingNfeJob::dispatchIfTrigger($order->id, $this->trigger); // MUL-276: NF-e automatica
                return;
            }

            DB::table('orders')->where('id', $order->id)->update([
                'bling_sync_error'        => 'auto_sync: export retornou vazio (ver logs)',
                'bling_sync_attempted_at' => now(),
            ]);
            throw new \RuntimeException("Export Bling vazio pro pedido {$order->id}");
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            DB::table('orders')->where('id', $order->id)->update([
                'bling_sync_error'        => mb_substr('auto_sync: ' . $e->getMessage(), 0, 500),
                'bling_sync_attempted_at' => now(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::warning("[MUL-274] Auto-sync Bling falhou definitivo pedido {$this->orderId}: {$e->getMessage()}");
    }
}
