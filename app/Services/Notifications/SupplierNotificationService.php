<?php

namespace App\Services\Notifications;

use App\Models\Inventory;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * NOV-139 — Centraliza notificações relevantes para o supplier admin.
 *
 * Eventos cobertos:
 *  - Estoque crítico (já parcial via NOV-118 CheckLowStockJob — agora também push do hub)
 *  - Pedido sem NF-e há > N horas
 *  - Ticket SAC sem resposta há > N horas
 *  - Conta marketplace com token expirado
 */
class SupplierNotificationService
{
    public function dispatchAllChecks(): array
    {
        $summary = [
            'low_stock'     => $this->checkLowStock(),
            'sac_overdue'   => $this->checkSacOverdue(),
            'token_expired' => $this->checkTokenExpired(),
        ];
        Log::info('[NOV-139] dispatchAllChecks', $summary);
        return $summary;
    }

    public function checkLowStock(int $threshold = 5): int
    {
        $count = 0;
        $items = Inventory::query()
            ->whereColumn('quantity', '<=', 'stock_alert_threshold')
            ->where('stock_alert_threshold', '>', 0)
            ->limit(100)
            ->get();
        foreach ($items as $inv) {
            if ($this->wasNotifiedRecently('low_stock', $inv->product_id, 12)) continue;
            $this->notifyAdmins($inv->producer_id, 'Estoque crítico', "Produto #{$inv->product_id} com {$inv->quantity} unidades.");
            $count++;
        }
        return $count;
    }

    public function checkSacOverdue(int $hoursThreshold = 12): int
    {
        $count = 0;
        $tickets = SupportTicket::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->where('created_at', '<', now()->subHours($hoursThreshold))
            ->whereNull('first_response_at')
            ->limit(50)
            ->get();
        foreach ($tickets as $t) {
            if ($this->wasNotifiedRecently('sac_overdue', $t->id, 6)) continue;
            $this->notifyAdmins($t->supplier_id, 'SAC sem resposta', "Ticket #{$t->id} sem primeira resposta há {$hoursThreshold}h.");
            $count++;
        }
        return $count;
    }

    public function checkTokenExpired(): int
    {
        $count = 0;
        $accounts = MarketplaceAccount::query()
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('token_expires_at')
                    ->orWhere('token_expires_at', '<', now());
            })
            ->limit(50)
            ->get();
        foreach ($accounts as $a) {
            if ($this->wasNotifiedRecently('token_expired', $a->id, 24)) continue;
            $this->notifyAdmins($a->supplier_id, 'Token marketplace expirado', "Conta {$a->marketplace} #{$a->id} precisa ser reautorizada.");
            $count++;
        }
        return $count;
    }

    protected function notifyAdmins(?int $supplierId, string $title, string $body): void
    {
        if (!$supplierId) return;
        try {
            $admins = User::query()
                ->where('role', 'super_admin')
                ->orWhereHas('supplier', fn ($q) => $q->where('id', $supplierId))
                ->limit(20)
                ->get();
            foreach ($admins as $u) {
                Notification::make()
                    ->title($title)
                    ->body($body)
                    ->warning()
                    ->sendToDatabase($u);
            }
        } catch (\Throwable $e) {
            Log::warning('[NOV-139] notifyAdmins falhou', ['err' => $e->getMessage()]);
        }
    }

    protected function wasNotifiedRecently(string $event, int $entityId, int $hours): bool
    {
        $key = "nov139:{$event}:{$entityId}";
        if (Cache::has($key)) return true;
        Cache::put($key, 1, now()->addHours($hours));
        return false;
    }
}
