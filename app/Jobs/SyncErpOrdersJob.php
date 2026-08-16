<?php

namespace App\Jobs;

use App\Services\Integrations\Erps\Bling\BlingOrderSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\Order;

class SyncErpOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $orderId;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    public function handle(): void
    {
        // MUL-222 item 1: kill switch de fila Bling (admin congela pra balanço de estoque)
        $frozen = \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', 'bling_queue_frozen')->value('value');
        if ($frozen === '1' || $frozen === 'true') {
            \Illuminate\Support\Facades\Log::info('[SyncErpOrdersJob] fila Bling congelada — release com delay', [
                'order_id' => $this->orderId,
            ]);
            $this->release(3600); // reenfileira em 1h
            return;
        }
        $order = Order::find($this->orderId);
        if (!$order || $order->status !== 'shipped')
            return;

        // MUL-226-02: idempotência — pedido que já tem id Bling (exportado antes OU importado
        // do próprio Bling) não re-exporta; evita pedido/NF duplicado no Bling em reenvios
        if (!empty($order->bling_order_id)) {
            Log::info('[SyncErpOrdersJob] skip: pedido já possui bling_order_id', [
                'order_id'       => $order->id,
                'bling_order_id' => $order->bling_order_id,
            ]);
            return;
        }

        // Verifica se client tem Bling ativo e empurra NF
        $bling = $order->client->erpAccounts()->where('platform', 'bling')->first();

        if ($bling) {
            $blingOrderSync = app(BlingOrderSync::class);
            $result = $blingOrderSync->exportOrder($bling, $order);

            if ($result === false) {
                Log::error('[SyncErpOrdersJob] Falha ao exportar pedido para Bling', [
                    'order_id'   => $order->id,
                    'account_id' => $bling->id,
                ]);
            } else {
                Log::info('[SyncErpOrdersJob] Pedido exportado para Bling com sucesso', [
                    'order_id'       => $order->id,
                    'account_id'     => $bling->id,
                    'bling_order_id' => $result['data']['id'] ?? null,
                ]);
            }
        }
    }
}
