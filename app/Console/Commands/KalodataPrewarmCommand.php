<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-426 — Prewarm noturno de imagens Kalodata.
 *
 * Roda de madrugada (02:00 BRT) e tenta enriquecer TODOS os produtos
 * que ainda estão sem foto no último snapshot. Usa o mesmo worker Node.js
 * de SEL-467 (kalodata_product_images.js) em lotes menores pra não
 * travar o servidor.
 *
 * Critério "sem foto":
 *   - Está no kalodata_raw type=products do último snapshot
 *   - NÃO tem linha em tiktok_product_images com url_local preenchido
 *
 * Saída:
 *   - Grava tentativas em tiktok_product_images.scrape_error (auditoria)
 *   - Nunca grava foto de produto diferente do que foi pedido (regra SEL-467)
 *   - Registra quantidade tentada/acertada no log
 *
 * USAGE:
 *   php artisan tiktok:prewarm-kalodata-photos
 *   php artisan tiktok:prewarm-kalodata-photos --limit=50
 *   php artisan tiktok:prewarm-kalodata-photos --dry
 */
class KalodataPrewarmCommand extends Command
{
    protected $signature = 'tiktok:prewarm-kalodata-photos
                            {--limit=30 : máximo de produtos por rodada}
                            {--dry : reporta quem precisa de foto sem gravar}';

    protected $description = 'SEL-426 prewarm noturno — enriquece produtos sem foto via Kalodata logada';

    public function handle(): int
    {
        $dry   = (bool) $this->option('dry');
        $limit = (int)  $this->option('limit');

        $this->info('[prewarm] iniciando prewarm de fotos Kalodata...');

        // 1. Quem está sem foto no último snapshot
        $last = DB::table('kalodata_raw')->where('type', 'products')->max('snapshot_date');
        if (!$last) {
            $this->warn('[prewarm] nenhum snapshot de produtos — nada a fazer');
            return self::SUCCESS;
        }
        $this->info("[prewarm] snapshot alvo: {$last}");

        $rows = DB::table('kalodata_raw')
            ->where('type', 'products')
            ->where('snapshot_date', $last)
            ->orderBy('id')
            ->get(['external_id', 'payload']);

        $semFoto = [];
        foreach ($rows as $r) {
            $p   = json_decode($r->payload, true);
            $pid = (string) ($p['id'] ?? $r->external_id ?? '');
            if (!$pid || isset($semFoto[$pid])) {
                continue;
            }
            if (!empty($p['image_url'])) {
                continue;  // já tem url no payload
            }
            $temFoto = DB::table('tiktok_product_images')
                ->where('product_key', $pid)
                ->whereNotNull('url_local')
                ->where('url_original', '<>', '__scrape_queued__')
                ->exists();
            if ($temFoto) {
                continue;
            }
            $semFoto[$pid] = $p['product_title'] ?? '';
        }

        $total = count($semFoto);
        if ($total === 0) {
            $this->info('[prewarm] todos os produtos do snapshot já têm foto. Nada a fazer.');
            return self::SUCCESS;
        }

        $this->info("[prewarm] {$total} produto(s) sem foto encontrados");

        if ($dry) {
            foreach ($semFoto as $id => $title) {
                $this->line("  DRY: {$id} — " . mb_substr($title, 0, 60));
            }
            return self::SUCCESS;
        }

        // 2. Delegar para o EnrichKalodataProductPhotos em lotes
        $lotes   = array_chunk(array_keys($semFoto), 5, true); // 5 ids por lote
        $ok      = 0;
        $falhou  = 0;
        $rodadas = 0;

        foreach ($lotes as $lote) {
            if ($rodadas >= ceil($limit / 5)) {
                break;
            }
            $ids = implode(',', $lote);
            $exitCode = \Artisan::call('tiktok:enrich-images-from-kalodata', [
                '--ids'   => $ids,
                '--limit' => count($lote),
            ]);
            $rodadas++;
            if ($exitCode === 0) {
                $ok += count($lote);
            } else {
                $falhou += count($lote);
                $this->warn("[prewarm] lote {$ids} falhou (exitCode={$exitCode})");
            }
            // Pausa breve entre lotes pra não sobrecarregar o Node
            sleep(5);
        }

        $msg = "[prewarm] concluído: {$ok} fotos buscadas, {$falhou} falhas, {$rodadas} rodadas";
        $this->info($msg);
        Log::info($msg, ['snapshot' => $last, 'total_sem_foto' => $total]);

        return self::SUCCESS;
    }
}
