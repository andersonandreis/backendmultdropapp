<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SEL-256 — importa snapshot Kalodata pré-fetched de um arquivo JSON local.
 *
 * Uso: quando o server é bloqueado por Cloudflare pra falar com kalodata.com,
 * a gente puxa os dados localmente (browser) e faz upload do JSON pra cá.
 * Este comando lê o JSON estruturado e insere via ORM (parametrizado, seguro).
 *
 * Formato esperado do arquivo (JSON):
 * {
 *   "snapshot_date": "2026-07-19",
 *   "window_range": "7d",
 *   "types": {
 *     "products": [ {payload...}, ... ],
 *     "creators": [ ... ],
 *     "videos":   [ ... ],
 *     "lives":    [ ... ],
 *     "shops":    [ ... ]
 *   }
 * }
 *
 * Exemplo: php artisan kalodata:import-json --file=/tmp/kalodata-dump.json
 */
class KalodataImportJsonCommand extends Command
{
    protected $signature = 'kalodata:import-json {--file= : caminho do JSON}';
    protected $description = 'SEL-256 importa Kalodata pré-fetched (contorna bloqueio Cloudflare no server)';

    public function handle(): int
    {
        $file = $this->option('file');
        if (!$file || !is_readable($file)) {
            $this->error("Arquivo não encontrado: $file");
            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data) || !isset($data['types']) || !isset($data['snapshot_date'])) {
            $this->error('JSON inválido — precisa {snapshot_date, window_range, types:{...}}');
            return self::FAILURE;
        }

        $snapshotDate = $data['snapshot_date'];
        $window = $data['window_range'] ?? '7d';
        $now = now();
        $total = 0;

        foreach ($data['types'] as $type => $items) {
            if (!is_array($items) || empty($items)) continue;
            $this->info("[$type] " . count($items) . " items");

            $rows = [];
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $extId = match ($type) {
                    'products' => $item['id'] ?? null,
                    'creators' => $item['handle'] ?? ($item['uid'] ?? null),
                    'videos'   => $item['id'] ?? null,
                    'lives'    => $item['uid'] ?? ($item['id'] ?? null),
                    'shops'    => $item['shop_id'] ?? ($item['id'] ?? null),
                    default    => null,
                };
                $rows[] = [
                    'type'          => $type,
                    'snapshot_date' => $snapshotDate,
                    'window_range'  => $window,
                    'external_id'   => (string) ($extId ?? ''),
                    'payload'       => json_encode($item, JSON_UNESCAPED_UNICODE),
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }
            if (empty($rows)) continue;

            // Dedup por (type, snapshot_date, external_id): apaga antes de inserir
            $ids = array_filter(array_column($rows, 'external_id'));
            if (!empty($ids)) {
                DB::table('kalodata_raw')
                    ->where('type', $type)
                    ->where('snapshot_date', $snapshotDate)
                    ->whereIn('external_id', $ids)
                    ->delete();
            }
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('kalodata_raw')->insert($chunk);
            }
            $total += count($rows);
        }

        $this->info("✅ Total inserido: $total linhas em kalodata_raw");
        return self::SUCCESS;
    }
}
