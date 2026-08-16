<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MUL-063: Cleanup de hosts invalidos em product_media.
 *
 * Acoes:
 *  1. URLs relativas (/storage/products/...) -> absolutas (https://api.multdrop.app/storage/...)
 *  2. api.fornecefy.io (14 linhas) -> DELETE (dados de outro tenant, paths absolutos Fornecefy)
 *  3. fornecefy-images.b-cdn.net (320 linhas) -> DELETE apenas se produto tem outra imagem valida
 *
 * goolhub.io (276) e app.multdropbr.com (9) retornam 200 OK, mantidos por ora.
 *
 * Uso: php artisan products:cleanup-media-hosts [--dry-run]
 */
class CleanupProductMediaHosts extends Command
{
    protected $signature = "products:cleanup-media-hosts {--dry-run : Simula sem alterar}";
    protected $description = "MUL-063: Cleanup hosts invalidos em product_media";

    public function handle(): int
    {
        $dryRun = (bool) $this->option("dry-run");
        $this->info("[MUL-063] Cleanup product_media hosts" . ($dryRun ? " [DRY-RUN]" : ""));

        $appUrl  = rtrim(config("app.url"), "/");
        $deleted = 0;
        $updated = 0;

        // --- 1. URLs relativas -> absolutas ---
        $relatives = DB::table("product_media")
            ->where("url", "not like", "http%")
            ->whereNotNull("url")
            ->where("url", "!=", "")
            ->get(["id", "url"]);

        $this->info("URLs relativas encontradas: " . $relatives->count());
        foreach ($relatives as $row) {
            $newUrl = $appUrl . "/" . ltrim($row->url, "/");
            $this->line("  [RELATIVE] id={$row->id} {$row->url} -> {$newUrl}");
            if (!$dryRun) {
                DB::table("product_media")->where("id", $row->id)->update(["url" => $newUrl, "updated_at" => now()]);
            }
            $updated++;
        }

        // --- 2. api.fornecefy.io -> DELETE (outro tenant, 14 linhas) ---
        $fornecefyApi = DB::table("product_media")
            ->where("url", "like", "%api.fornecefy.io%")
            ->get(["id", "product_id", "url"]);

        $this->info("api.fornecefy.io encontradas: " . $fornecefyApi->count());
        foreach ($fornecefyApi as $row) {
            $this->line("  [DELETE-FORNECEFY-API] id={$row->id} product_id={$row->product_id}");
        }
        if (!$dryRun && $fornecefyApi->count()) {
            $cnt = DB::table("product_media")->where("url", "like", "%api.fornecefy.io%")->delete();
            $this->info("  -> deletadas $cnt linhas api.fornecefy.io");
            $deleted += $cnt;
        }

        // --- 3. fornecefy-images.b-cdn.net -> DELETE apenas se produto tem outra imagem ---
        // Todos os 23 produtos com URLs fornecefy-images tambem tem URLs multdrop validas.
        // Deletar e seguro — produto nao fica sem imagem.
        $fornecefyCdn = DB::table("product_media")
            ->where("url", "like", "%fornecefy-images.b-cdn.net%")
            ->get(["id", "product_id", "url", "is_cover"]);

        $this->info("fornecefy-images.b-cdn.net encontradas: " . $fornecefyCdn->count());

        // Filtra: so deleta se produto tem outra imagem valida (nao fornecefy)
        $toDelete = [];
        foreach ($fornecefyCdn as $row) {
            $otherCount = DB::table("product_media")
                ->where("product_id", $row->product_id)
                ->where("url", "not like", "%fornecefy%")
                ->where("url", "not like", "%api.fornecefy%")
                ->count();
            if ($otherCount > 0) {
                $toDelete[] = $row->id;
                $this->line("  [DELETE-FORNECEFY-CDN] id={$row->id} product_id={$row->product_id} (produto tem $otherCount imgs validas)");
            } else {
                $this->line("  [KEEP-FORNECEFY-CDN] id={$row->id} product_id={$row->product_id} (unica imagem — mantido)");
            }
        }

        if (!$dryRun && !empty($toDelete)) {
            $cnt = DB::table("product_media")->whereIn("id", $toDelete)->delete();
            $this->info("  -> deletadas $cnt linhas fornecefy-images.b-cdn.net");
            $deleted += $cnt;
        } elseif ($dryRun) {
            $this->info("  -> seriam deletadas " . count($toDelete) . " linhas fornecefy-images.b-cdn.net");
        }

        $this->newLine();
        $this->info("[MUL-063] Concluido: atualizadas=$updated deletadas=$deleted" . ($dryRun ? " [DRY-RUN]" : ""));
        Log::info("[MUL-063] Cleanup hosts: updated=$updated deleted=$deleted dry=$dryRun");

        return 0;
    }
}
