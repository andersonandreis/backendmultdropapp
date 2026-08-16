<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SEL-096 Ruan 20:20: libera fornecedores gradualmente pros clientes
 * do plano supplier_only (id 90). Roda TODA SEGUNDA 06:00 BRT + também
 * ao criar assinatura (chamado inline no primeiro pagamento).
 *
 * Regra: cada cliente ganha até 50 fornecedores/semana, priorizados por
 * cover_url + verified (os melhores primeiro). Idempotente — só insere
 * o que ainda não tá em client_supplier_unlocks.
 */
class UnlockWeeklySuppliersCommand extends Command
{
    protected $signature = 'suppliers:unlock-weekly {--per-week=50} {--client=} {--dry}';
    protected $description = 'Libera 50 fornecedores por semana pra clientes do plano supplier_only (SEL-096)';

    public function handle(): int
    {
        $perWeek = (int) $this->option('per-week');
        $filterClientId = $this->option('client') ? (int) $this->option('client') : null;
        $dry = (bool) $this->option('dry');

        // 1) Pega clientes elegíveis (subscription active no plan 90 supplier_only OU plano pago full)
        $clientsQuery = DB::table('clients as c')
            ->join('subscriptions as s', 's.client_id', '=', 'c.id')
            ->join('plans as p', 'p.id', '=', 's.plan_id')
            ->whereIn('s.status', ['active', 'trialing'])
            ->whereIn('p.slug', ['supplier_only', 'start', 'scaling', 'pro'])
            ->select('c.id as cid', 'c.supplier_unlock_starts_at', 'p.slug as plan_slug', 's.created_at as sub_at');

        if ($filterClientId) $clientsQuery->where('c.id', $filterClientId);
        $clients = $clientsQuery->get();

        $this->info("Processando {$clients->count()} clientes elegíveis...");
        $totalUnlocked = 0;

        foreach ($clients as $c) {
            // Data de início da liberação (usa sub_at se supplier_unlock_starts_at não seteado)
            $startAt = $c->supplier_unlock_starts_at ?: $c->sub_at;
            if (! $startAt) continue;

            $weeksSince = max(1, (int) floor(now()->diffInDays($startAt) / 7) + 1);
            // Planos pagos (start/scaling/pro) já veem tudo — supplier_only é o gradual
            $maxUnlocks = ($c->plan_slug === 'supplier_only') ? ($weeksSince * $perWeek) : PHP_INT_MAX;

            $currentUnlocks = DB::table('client_supplier_unlocks')->where('client_id', $c->cid)->count();
            $slotsToFill = max(0, $maxUnlocks - $currentUnlocks);
            if ($slotsToFill === 0) continue;

            // Fornecedores que ainda não foram liberados pra esse cliente,
            // priorizando cover_url + verified (melhores primeiro)
            $alreadyUnlocked = DB::table('client_supplier_unlocks')
                ->where('client_id', $c->cid)
                ->pluck('directory_supplier_id');

            $newSuppliers = DB::table('directory_suppliers')
                ->where('is_active', 1)
                ->whereNotIn('id', $alreadyUnlocked)
                ->orderByRaw('CASE WHEN cover_url IS NOT NULL AND cover_url <> "" THEN 0 ELSE 1 END')
                ->orderByRaw('CASE WHEN verified = 1 THEN 0 ELSE 1 END')
                ->orderByRaw('CASE WHEN catalog_url IS NOT NULL AND catalog_url <> "" THEN 0 ELSE 1 END')
                ->orderBy('id')
                ->limit($slotsToFill)
                ->pluck('id');

            if ($newSuppliers->isEmpty()) continue;

            if ($dry) {
                $this->line("[DRY] cliente {$c->cid} ({$c->plan_slug}): +{$newSuppliers->count()} suppliers (total já: {$currentUnlocks})");
                continue;
            }

            $rows = $newSuppliers->map(fn($sid) => [
                'client_id' => $c->cid,
                'directory_supplier_id' => $sid,
                'unlocked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();

            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('client_supplier_unlocks')->insertOrIgnore($chunk);
            }
            $totalUnlocked += count($rows);
            $this->line("Cliente {$c->cid} ({$c->plan_slug}): +{$newSuppliers->count()} liberados (total: " . ($currentUnlocks + $newSuppliers->count()) . ")");
        }

        $this->info("Total liberado: {$totalUnlocked} unlocks");
        return self::SUCCESS;
    }
}
