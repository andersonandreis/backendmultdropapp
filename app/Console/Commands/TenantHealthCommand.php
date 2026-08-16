<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantApiCredential;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan tenant:health [slug?]
 * Visao operacional de saude por tenant.
 */
class TenantHealthCommand extends Command
{
    protected $signature = 'tenant:health {slug? : Tenant slug (opcional)}';
    protected $description = 'Resumo de saude por tenant (orders, webhooks, divergencia, credenciais)';

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->when($this->argument('slug'), fn($q, $s) => $q->where('slug', $s))
            ->orderBy('legacy_empresa_id')
            ->get();

        foreach ($tenants as $t) {
            $this->report($t);
        }

        return self::SUCCESS;
    }

    private function report(Tenant $t): void
    {
        $this->newLine();
        $this->info("=== Tenant {$t->slug} ({$t->name}) ===");
        $this->line("  uuid={$t->id}");
        $this->line("  status={$t->status} write_enabled=" . ($t->write_enabled ? 'YES' : 'no') . " legacy_empresa_id={$t->legacy_empresa_id}");

        // Orders por canonical_status
        $supplierIds = \Illuminate\Support\Facades\DB::table('tenant_supplier')->where('tenant_id', $t->id)->pluck('supplier_id');
        $orders = Order::whereIn('supplier_id', $supplierIds)
            ->selectRaw('canonical_status, COUNT(*) c')
            ->groupBy('canonical_status')
            ->pluck('c', 'canonical_status')
            ->toArray();
        $total = array_sum($orders);
        $this->line("  orders total: {$total}");
        foreach ($orders as $s => $c) {
            $this->line("    - {$s}: {$c}");
        }

        // Credenciais
        $creds = TenantApiCredential::where('tenant_id', $t->id)->get();
        $this->line("  api_credentials: " . $creds->count() . " (ativas: " . $creds->whereNull('revoked_at')->count() . ")");
        foreach ($creds as $c) {
            $last = $c->last_used_at?->diffForHumans() ?? 'nunca';
            $rev = $c->revoked_at ? ' [REVOGADA]' : '';
            $this->line("    - {$c->key_id} ultimo uso: {$last}{$rev}");
        }

        // Webhook endpoints
        $endpoints = DB::table('tenant_webhook_endpoints')->where('tenant_id', $t->id)->get();
        $this->line("  webhook_endpoints: " . $endpoints->count());
        foreach ($endpoints as $e) {
            $flag = ($e->active ? '[ACTIVE]' : '[INACTIVE]') . ($e->shadow ? ' [SHADOW]' : '');
            $this->line("    - {$flag} " . substr($e->url, 0, 60));
        }

        // Deliveries 24h por status
        $delv = DB::table('webhook_deliveries as d')
            ->join('tenant_webhook_endpoints as e', 'e.id', '=', 'd.endpoint_id')
            ->where('e.tenant_id', $t->id)
            ->where('d.created_at', '>', now()->subDay())
            ->selectRaw('d.status, COUNT(*) c')
            ->groupBy('d.status')
            ->pluck('c', 'status')
            ->toArray();
        $totalD = array_sum($delv);
        $this->line("  webhook deliveries 24h: {$totalD}");
        foreach ($delv as $s => $c) {
            $this->line("    - {$s}: {$c}");
        }

        // Divergencias abertas
        $divs = DB::table('tenant_divergence_log')
            ->where('tenant_id', $t->id)
            ->where('resolved', false)
            ->orderByDesc('updated_at')
            ->get();
        if ($divs->isNotEmpty()) {
            $this->warn("  divergencias abertas: " . $divs->count());
            foreach ($divs as $d) {
                $this->line("    - [{$d->kind}] {$d->check_id} -> {$d->detail}");
            }
        }
    }
}
