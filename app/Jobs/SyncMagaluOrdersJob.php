<?php

namespace App\Jobs;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Services\Integrations\Marketplaces\MagaluService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** NOV-132 — Sync de pedidos Magalu. */
class SyncMagaluOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public ?int $accountId = null) {}

    public function handle(MagaluService $svc): void
    {
        $accounts = $this->accountId
            ? MarketplaceAccount::query()->where('id', $this->accountId)->get()
            : MarketplaceAccount::query()->where('platform', 'magalu')->where('status', 'active')->get();

        foreach ($accounts as $account) {
            try {
                $orders = $svc->fetchOrders($account, now()->subHours(6)->toIso8601String());
                $imported = 0;
                foreach ($orders as $raw) {
                    $exists = Order::query()
                        ->where('external_order_id', $raw['id'] ?? null)
                        ->where('platform', 'magalu')
                        ->exists();
                    if ($exists) continue;
                    Order::query()->create([
                        'supplier_id'        => $account->supplier_id,
                        'client_id'          => $account->client_id ?? null,
                        'external_order_id'  => $raw['id'] ?? null,
                        'order_number'       => 'MGL-'.($raw['id'] ?? rand(1000,9999)),
                        'source'             => 'magalu',
                        'marketplace'        => 'magalu',
                        'status'             => 'pending_payment',
                        'subtotal'           => (float) ($raw['subtotal'] ?? 0),
                        'total'              => (float) ($raw['total'] ?? 0),
                    ]);
                    $imported++;
                }
                Log::info('[NOV-132] Magalu sync', ['account_id' => $account->id, 'imported' => $imported]);
            } catch (\Throwable $e) {
                Log::error('[NOV-132] Magalu sync falhou', ['account_id' => $account->id, 'err' => $e->getMessage()]);
            }
        }
    }
}
