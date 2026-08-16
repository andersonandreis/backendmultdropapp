<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Supplier Core / Fase 3 / M5 — verifica divergencias por tenant.
 *
 * Checks rodados:
 *  - webhook_stuck: deliveries com status=failed e next_retry_at < now() - 30min
 *    (significa que worker nao esta processando).
 *  - webhook_dead_burst: > 20 deliveries dead nas ultimas 24h pro mesmo endpoint.
 *  - orders_no_progress: orders em accepted/in_fulfillment ha > 7 dias.
 *  - idempotency_keys_growth: > 10k entradas nao expiradas (sinal de bug ou abuso).
 *
 * Cada divergencia vira uma linha em tenant_divergence_log (idempotente por
 * subject — duas deteccoes do mesmo problema atualizam, nao duplicam).
 *
 * Rodar manual: php artisan tenant:divergence-check [slug?]
 * Scheduler: registrar em routes/console.php pra everyFiveMinutes().
 */
class DivergenceCheckCommand extends Command
{
    protected $signature = 'tenant:divergence-check {slug? : Tenant slug (opcional, default todos write_enabled)}';
    protected $description = 'Roda checks de divergencia e grava em tenant_divergence_log';

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->when($this->argument('slug'), fn($q, $s) => $q->where('slug', $s))
            ->where('write_enabled', true)
            ->where('status', 'active')
            ->get();

        if ($tenants->isEmpty()) {
            $this->line('Nenhum tenant write_enabled+active pra checar.');
            return self::SUCCESS;
        }

        $totalFound = 0;
        foreach ($tenants as $tenant) {
            $found = $this->checkTenant($tenant);
            $totalFound += $found;
            $this->line("[{$tenant->slug}] {$found} divergencia(s)");
        }

        $this->info("Total: {$totalFound} divergencia(s) detectada(s).");
        return self::SUCCESS;
    }

    private function checkTenant(Tenant $tenant): int
    {
        $count = 0;

        // 1) webhook_stuck
        $stuck = DB::table('webhook_deliveries as d')
            ->join('tenant_webhook_endpoints as e', 'e.id', '=', 'd.endpoint_id')
            ->where('e.tenant_id', $tenant->id)
            ->where('d.status', WebhookDelivery::STATUS_FAILED)
            ->where(function ($q) {
                $q->whereNull('d.next_retry_at')
                  ->orWhere('d.next_retry_at', '<', Carbon::now()->subMinutes(30));
            })
            ->count();
        if ($stuck > 0) {
            $this->upsertDivergence($tenant->id, 'webhook_stuck', 'warning',
                "tenant:{$tenant->slug}",
                "{$stuck} webhook deliveries em failed sem retry agendado (>30min)");
            $count++;
        }

        // 2) webhook_dead_burst (>20 dead nas ultimas 24h)
        $dead24h = DB::table('webhook_deliveries as d')
            ->join('tenant_webhook_endpoints as e', 'e.id', '=', 'd.endpoint_id')
            ->where('e.tenant_id', $tenant->id)
            ->where('d.status', WebhookDelivery::STATUS_DEAD)
            ->where('d.updated_at', '>', Carbon::now()->subDay())
            ->count();
        if ($dead24h > 20) {
            $this->upsertDivergence($tenant->id, 'webhook_dead_burst', 'critical',
                "tenant:{$tenant->slug}",
                "{$dead24h} webhook deliveries dead nas ultimas 24h. Endpoint provavel down.");
            $count++;
        }

        // 3) orders_no_progress (orders em accepted/in_fulfillment ha > 7 dias)
        // Fix MES-053: orders.tenant_id nao existe (dropada 30/05/2026, regra 10 do 00-INDEX).
        // Filtrar por supplier_id via tenant_supplier conforme TenantSupplierScope.
        $supplierIds = DB::table('tenant_supplier')
            ->where('tenant_id', $tenant->id)
            ->pluck('supplier_id');
        $stuckOrders = Order::query()
            ->whereIn('supplier_id', $supplierIds)
            ->whereIn('canonical_status', ['accepted', 'in_fulfillment'])
            ->where('updated_at', '<', Carbon::now()->subDays(7))
            ->count();
        if ($stuckOrders > 0) {
            $this->upsertDivergence($tenant->id, 'orders_no_progress', 'warning',
                "tenant:{$tenant->slug}",
                "{$stuckOrders} order(s) parado(s) em accepted/in_fulfillment ha mais de 7 dias");
            $count++;
        }

        // 4) idempotency_keys_growth (>10k nao expiradas — sinal de bug)
        $idemCount = DB::table('idempotency_keys')
            ->where('tenant_id', $tenant->id)
            ->where('expires_at', '>', Carbon::now())
            ->count();
        if ($idemCount > 10000) {
            $this->upsertDivergence($tenant->id, 'idempotency_growth', 'warning',
                "tenant:{$tenant->slug}",
                "{$idemCount} idempotency_keys ativas. Cliente pode estar gerando keys novas demais.");
            $count++;
        }

        return $count;
    }

    private function upsertDivergence(string $tenantId, string $checkId, string $kind, string $subject, string $detail): void
    {
        $existing = DB::table('tenant_divergence_log')
            ->where('tenant_id', $tenantId)
            ->where('check_id', $checkId)
            ->where('subject', $subject)
            ->where('resolved', false)
            ->first();

        if ($existing) {
            DB::table('tenant_divergence_log')->where('id', $existing->id)->update([
                'detail'     => $detail,
                'kind'       => $kind,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('tenant_divergence_log')->insert([
                'tenant_id'  => $tenantId,
                'check_id'   => $checkId,
                'kind'       => $kind,
                'subject'    => $subject,
                'detail'     => $detail,
                'resolved'   => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
