<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MUL-137 -- Purga a pasta labels/ do BunnyCDN.
 *
 * Etiquetas de envio sao arquivos operacionais/transitorios e nunca devem
 * ficar no CDN. Este comando apaga a pasta inteira de labels de uma storage
 * zone do Bunny, sem tocar imagens de produto ou outros paths.
 *
 * Uso:
 *   php artisan labels:purge-cdn --storage-zone=multdrop-images --dry-run
 *   php artisan labels:purge-cdn --storage-zone=multdrop-images
 */
class PurgeLabelsCdn extends Command
{
    protected $signature = "labels:purge-cdn
                            {--storage-zone= : Nome da storage zone BunnyCDN}
                            {--access-key=   : Access key BunnyCDN}
                            {--batch=100     : Quantos arquivos deletar por batch}
                            {--dry-run       : Lista arquivos sem deletar}
                            {--manifest=     : Arquivo para salvar manifesto}
                            {--force         : Executa sem pedir confirmacao}";

    protected $description = "MUL-137: Purga apenas a pasta labels/ do BunnyCDN (nunca toca products/).";

    private const BUNNY_API = "https://storage.bunnycdn.com";
    private const SAFE_PATH = "labels/";

    public function handle(): int
    {
        $zone      = $this->option("storage-zone") ?: env("BUNNYCDN_STORAGE_ZONE");
        $accessKey = $this->option("access-key")   ?: env("BUNNYCDN_ACCESS_KEY");
        $batchSize = max(1, min(1000, (int) $this->option("batch")));
        $dryRun    = (bool) $this->option("dry-run");
        $force     = (bool) $this->option("force");
        $manifest  = $this->option("manifest");

        if (! $zone || ! $accessKey) {
            $this->error("storage-zone e access-key sao obrigatorios.");
            return 1;
        }

        $this->info("=== MUL-137: Purga de etiquetas do BunnyCDN ===");
        $this->info("Storage zone : {$zone}");
        $this->info("Path alvo    : " . self::SAFE_PATH . "  (SOMENTE este path)");
        $this->info("Modo         : " . ($dryRun ? "DRY-RUN (nao deleta nada)" : "REAL -- vai deletar"));
        $this->newLine();

        $url      = self::BUNNY_API . "/{$zone}/" . self::SAFE_PATH;
        $response = Http::withHeaders(["AccessKey" => $accessKey])->get($url);

        if ($response->failed()) {
            $this->error("Falha ao listar labels/ no Bunny: HTTP {$response->status()}");
            return 1;
        }

        $files = $response->json();
        if (! is_array($files)) {
            $this->warn("Nenhum arquivo encontrado em labels/.");
            return 0;
        }

        $files = array_filter($files, fn($f) => isset($f["IsDirectory"]) && ! $f["IsDirectory"]);
        $total = count($files);

        if ($total === 0) {
            $this->info("Nenhum arquivo em labels/. Pasta ja esta vazia.");
            return 0;
        }

        $totalBytes = array_sum(array_column($files, "Length"));
        $totalMb    = round($totalBytes / 1024 / 1024, 1);

        $this->info("Arquivos encontrados: {$total}");
        $this->info("Tamanho total       : {$totalMb}MB");
        $this->newLine();

        if ($dryRun) {
            $this->warn("[DRY-RUN] Nenhum arquivo sera deletado. Primeiros 20:");
            foreach (array_slice($files, 0, 20) as $f) {
                $this->line("  " . $f["ObjectName"] . "  (" . round(($f["Length"] ?? 0)/1024, 1) . "KB)");
            }
            if ($total > 20) $this->info("... e mais " . ($total - 20) . " arquivos.");
            return 0;
        }

        if (! $force && ! $this->confirm("Confirma deletar {$total} arquivos ({$totalMb}MB) de labels/ da zone {$zone}?")) {
            $this->info("Operacao cancelada.");
            return 0;
        }

        $deleted  = 0;
        $failed   = 0;
        $manifestLines = [];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($files as $file) {
            $objectName = $file["ObjectName"];
            $remotePath = self::SAFE_PATH . $objectName;

            // Seguranca: jamais deletar fora de labels/
            if (! str_starts_with($remotePath, self::SAFE_PATH)) {
                $this->error("
SEGURANCA: path suspeito ignorado: {$remotePath}");
                $failed++;
                $bar->advance();
                continue;
            }

            $delUrl  = self::BUNNY_API . "/{$zone}/{$remotePath}";
            $delResp = Http::withHeaders(["AccessKey" => $accessKey])->delete($delUrl);

            if ($delResp->successful()) {
                $deleted++;
                $manifestLines[] = $remotePath;
            } else {
                $failed++;
                Log::warning("[MUL-137] Falha ao deletar do Bunny", [
                    "path"   => $remotePath,
                    "status" => $delResp->status(),
                ]);
            }

            $bar->advance();

            if ($deleted % $batchSize === 0) {
                usleep(200000);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Deletados : {$deleted}");
        $this->warn("Falhas    : {$failed}");
        $this->info("Economia  : {$totalMb}MB removidos do CDN");

        if ($manifest && ! empty($manifestLines)) {
            file_put_contents($manifest, implode("
", $manifestLines));
            $this->info("Manifesto salvo em: {$manifest}");
        }

        Log::info("[MUL-137] Purga labels CDN concluida", [
            "zone"    => $zone,
            "deleted" => $deleted,
            "failed"  => $failed,
            "mb"      => $totalMb,
        ]);

        return $failed > 0 ? 1 : 0;
    }
}
