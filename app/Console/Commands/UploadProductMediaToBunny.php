<?php

namespace App\Console\Commands;

use App\Services\Integrations\Cdn\BunnyCdnService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MUL-054 — Upload de imagens product_media faltantes pro BunnyCDN.
 *
 * Contexto:
 *   - 8.110 registros product_media tem url no padrao estruturado
 *     https://multdrop-images.b-cdn.net/products/{supplier}/{product}/{hash}.{ext}
 *   - O arquivo existe localmente em storage/app/public/{local_path}
 *   - Mas nunca foi feito upload pra Bunny Storage Zone — Bunny retorna 404
 *   - Frontend e mascarado pelo accessor ProductMedia::url (fallback original_url),
 *     mas endpoints que pluck() crua (ex: OrderController::topProducts) servem URL 404
 *
 * Este comando le os registros, confirma arquivo local, faz upload pro Bunny
 * e marca progresso. Idempotente — pode rodar em lote sem dobrar uploads.
 *
 * Uso:
 *   php artisan products:upload-bunny-missing --dry-run --limit=10
 *   php artisan products:upload-bunny-missing --batch=100 --limit=500
 *   nohup php artisan products:upload-bunny-missing --batch=100 > bunny.log 2>&1 &
 */
class UploadProductMediaToBunny extends Command
{
    protected $signature = 'products:upload-bunny-missing
                            {--batch=100 : Tamanho do batch para processar}
                            {--limit= : Limite total (debug)}
                            {--dry-run : Apenas mostra o que faria, sem upload}
                            {--sleep=200 : Sleep em ms entre uploads (rate limit Bunny)}
                            {--verify : Faz HEAD apos upload para confirmar 200}';

    protected $description = 'Sobe pro BunnyCDN imagens product_media com URL estruturada faltantes (MUL-054).';

    /** Regex de URLs Bunny estruturadas /products/{supplier}/{product}/{hash}.{ext}. */
    private const URL_PATTERN = '^https://multdrop-images\.b-cdn\.net/products/[0-9]+/[0-9]+/[a-zA-Z0-9]+\.[a-zA-Z0-9]+$';

    public function handle(BunnyCdnService $cdn): int
    {
        $batch  = max(1, (int) $this->option('batch'));
        $limit  = $this->option('limit') ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');
        $sleepMs = max(0, (int) $this->option('sleep'));
        $verify = (bool) $this->option('verify');

        $base = storage_path('app/public/');

        $q = DB::table('product_media')
            ->where('url', 'regexp', self::URL_PATTERN)
            ->whereNotNull('local_path');

        $total = (clone $q)->count();
        $this->info("[MUL-054] product_media com URL Bunny estruturada + local_path: {$total}");
        if ($limit !== null) {
            $this->info("[MUL-054] limit aplicado: {$limit}");
        }
        if ($dryRun) {
            $this->warn('[MUL-054] DRY RUN — nenhum upload sera feito.');
        }

        $processed = 0;
        $uploaded  = 0;
        $skipped   = 0;
        $missing   = 0;
        $failed    = 0;
        $verified  = 0;
        $startedAt = microtime(true);

        $q->orderBy('id')->chunkById(
            $batch,
            function ($rows) use (&$processed, &$uploaded, &$skipped, &$missing, &$failed, &$verified, $base, $cdn, $dryRun, $sleepMs, $verify, $limit) {
                foreach ($rows as $row) {
                    if ($limit !== null && $processed >= $limit) {
                        return false;
                    }
                    $processed++;

                    $localPath  = ltrim((string) $row->local_path, '/');
                    $remotePath = $this->extractRemotePath($row->url);
                    $absolute   = $base . $localPath;

                    if (!$remotePath) {
                        $skipped++;
                        $this->line("  [SKIP] id={$row->id} url nao bateu regex: {$row->url}");
                        continue;
                    }

                    if (!is_file($absolute)) {
                        $missing++;
                        $this->line("  [MISS] id={$row->id} arquivo local ausente: {$absolute}");
                        Log::warning('[MUL-054] arquivo local ausente', [
                            'media_id' => $row->id,
                            'path'     => $absolute,
                        ]);
                        continue;
                    }

                    if ($dryRun) {
                        $uploaded++;
                        $this->line("  [DRY] id={$row->id} would upload {$absolute} -> {$remotePath}");
                        continue;
                    }

                    try {
                        $ok = $cdn->upload($absolute, $remotePath);
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error("  [FAIL] id={$row->id} excecao: " . $e->getMessage());
                        Log::error('[MUL-054] upload excecao', [
                            'media_id' => $row->id,
                            'remote'   => $remotePath,
                            'error'    => $e->getMessage(),
                        ]);
                        continue;
                    }

                    if (!$ok) {
                        $failed++;
                        $this->error("  [FAIL] id={$row->id} bunny retornou erro: {$remotePath}");
                        continue;
                    }

                    $uploaded++;
                    if ($verify) {
                        if ($this->verifyCdn($row->url)) {
                            $verified++;
                        }
                    }

                    if ($sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }
                }

                $this->line(sprintf(
                    '  ... processados=%d uploaded=%d skipped=%d missing=%d failed=%d verified=%d',
                    $processed,
                    $uploaded,
                    $skipped,
                    $missing,
                    $failed,
                    $verified
                ));
            }
        );

        $elapsed = round(microtime(true) - $startedAt, 1);
        $this->info(sprintf(
            '[MUL-054] FIM em %ds — total=%d uploaded=%d skipped=%d missing=%d failed=%d verified=%d',
            (int) $elapsed,
            $processed,
            $uploaded,
            $skipped,
            $missing,
            $failed,
            $verified
        ));

        return self::SUCCESS;
    }

    /**
     * Extrai remote path do URL Bunny.
     * Ex: https://multdrop-images.b-cdn.net/products/1/1033/abc.webp
     *     -> products/1/1033/abc.webp
     */
    private function extractRemotePath(string $url): ?string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['path'])) {
            return null;
        }
        $path = ltrim($parts['path'], '/');
        if (!str_starts_with($path, 'products/')) {
            return null;
        }
        return $path;
    }

    /** HEAD request para confirmar 200 no CDN. */
    private function verifyCdn(string $url): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }
}
