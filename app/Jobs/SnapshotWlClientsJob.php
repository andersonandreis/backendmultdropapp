<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NOV-214 / Antifraude WL MVP — snapshot semanal de clientes por empresa WL.
 *
 * Roda toda segunda-feira 03:00 UTC via Schedule (routes/console.php).
 * Grava em wl_client_snapshots: is_active + blocked_at + email de cada cliente
 * em cada banco WL configurado em $wlDatabases.
 */
class SnapshotWlClientsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 300;

    protected array $wlDatabases = [
        24 => ["multdrop",    "multdropapp_production"],
        22 => ["fornecefy",   "fornecefyapp_production"],
        20 => ["mestoredrop", "mestoredropapp_production"],
        17 => ["jtdrop",      "jtdrop_prod"],
        21 => ["dropksr",     "dropksrapp_production"],
    ];

    public function handle(): void
    {
        if (! config("antifraude.wl_snapshot_enabled", true)) {
            Log::info("[SnapshotWlClients] desabilitado via WL_SNAPSHOT_ENABLED=false");
            return;
        }

        $snapshotDate = now()->toDateString();
        $totalInserted = 0;

        foreach ($this->wlDatabases as $empresaId => [$connection, $database]) {
            try {
                $conn = DB::connection($connection);
                $conn->getPdo();

                try {
                    $clients = $conn->table("clients as c")
                        ->leftJoin("users as u", "u.id", "=", "c.user_id")
                        ->select(["c.id as client_id", "u.email as email", "c.is_active", "c.blocked_at"])
                        ->orderBy("c.id")
                        ->cursor();
                } catch (\Throwable $e) {
                    $clients = $conn->table("clients as c")
                        ->select(["c.id as client_id", DB::raw("NULL as email"), "c.is_active", "c.blocked_at"])
                        ->orderBy("c.id")
                        ->cursor();
                }

                $batch   = [];
                $dbCount = 0;

                foreach ($clients as $client) {
                    $batch[] = [
                        "empresa_id"    => $empresaId,
                        "wl_database"   => $database,
                        "snapshot_date" => $snapshotDate,
                        "client_id"     => $client->client_id,
                        "email"         => $client->email,
                        "is_active"     => (bool) $client->is_active,
                        "blocked_at"    => $client->blocked_at,
                        "created_at"    => now(),
                        "updated_at"    => now(),
                    ];
                    $dbCount++;

                    if (count($batch) >= 500) {
                        DB::table("wl_client_snapshots")->insertOrIgnore($batch);
                        $totalInserted += count($batch);
                        $batch = [];
                    }
                }

                if (! empty($batch)) {
                    DB::table("wl_client_snapshots")->insertOrIgnore($batch);
                    $totalInserted += count($batch);
                }

                Log::info("[SnapshotWlClients] empresa concluida", [
                    "empresa_id" => $empresaId,
                    "database"   => $database,
                    "clients"    => $dbCount,
                    "date"       => $snapshotDate,
                ]);

            } catch (\Throwable $e) {
                Log::error("[SnapshotWlClients] erro ao processar empresa", [
                    "empresa_id" => $empresaId,
                    "database"   => $database,
                    "error"      => $e->getMessage(),
                ]);
            }
        }

        Log::info("[SnapshotWlClients] snapshot semanal concluido", [
            "snapshot_date"  => $snapshotDate,
            "total_inserted" => $totalInserted,
        ]);
    }
}
