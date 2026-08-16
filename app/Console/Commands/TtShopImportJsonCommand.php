<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SEL-261 — Importa dump JSON do TikTok Shop BR pra tabela tt_shop_raw.
 *
 * O scraper Playwright (próxima sessão) salva o payload capturado em JSON.
 * Este command lê esse JSON e insere na tabela, preservando o payload cru
 * pra retrocompat quando o TT muda campos.
 *
 * Formatos aceitos:
 *
 * Formato 1 — dump único de produtos (homepage):
 * {
 *   "snapshot_date": "2026-07-19",
 *   "type": "product",
 *   "category_slug": "beauty-personal-care",
 *   "items": [ {product_id, title, image, product_price_info, ...}, ... ]
 * }
 *
 * Formato 2 — dump multi-tipo (análogo ao KalodataImportJsonCommand):
 * {
 *   "snapshot_date": "2026-07-19",
 *   "types": {
 *     "product": [ {...}, ... ],
 *     "shop":    [ {...}, ... ]
 *   }
 * }
 *
 * external_id = product_id | shop_id | category_slug | query (dependendo do type).
 *
 * Uso:
 *   php artisan tt-shop:import-json --file=/tmp/ttshop-dump.json
 *   php artisan tt-shop:import-json --file=/tmp/ttshop-dump.json --type=product --category=beauty-personal-care
 */
class TtShopImportJsonCommand extends Command
{
    protected $signature = 'tt-shop:import-json
                            {--file= : Caminho absoluto do JSON}
                            {--type= : Tipo padrão (product|shop|category|search) se não informado no JSON}
                            {--category= : category_slug padrão se não informado no JSON}';

    protected $description = 'SEL-261 importa dump JSON do TikTok Shop BR para tt_shop_raw';

    public function handle(): int
    {
        $file = $this->option('file');
        if (!$file || !is_readable($file)) {
            $this->error("Arquivo não encontrado ou ilegível: {$file}");
            return self::FAILURE;
        }

        $raw = file_get_contents($file);
        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $this->error('JSON inválido: ' . json_last_error_msg());
            return self::FAILURE;
        }

        if (!isset($data['snapshot_date'])) {
            $this->error('Campo snapshot_date é obrigatório no JSON.');
            return self::FAILURE;
        }

        $snapshotDate   = $data['snapshot_date'];
        $defaultType    = $this->option('type') ?? ($data['type'] ?? 'product');
        $defaultCategory = $this->option('category') ?? ($data['category_slug'] ?? null);
        $now            = now();
        $total          = 0;

        // Formato 2 — multi-tipo
        if (isset($data['types'])) {
            foreach ($data['types'] as $type => $items) {
                if (!is_array($items) || empty($items)) continue;
                $valid = ['product', 'shop', 'category', 'search'];
                if (!in_array($type, $valid, true)) {
                    $this->warn("Tipo desconhecido '$type' — pulando.");
                    continue;
                }
                $total += $this->insertBatch($type, $items, $snapshotDate, $defaultCategory, $now);
            }
        } elseif (isset($data['items'])) {
            // Formato 1 — lista única
            $total += $this->insertBatch($defaultType, $data['items'], $snapshotDate, $defaultCategory, $now);
        } else {
            $this->error('JSON deve ter "types" ou "items".');
            return self::FAILURE;
        }

        $this->info("Concluido. Total inserido: {$total} linhas em tt_shop_raw (snapshot: {$snapshotDate}).");
        return self::SUCCESS;
    }

    private function insertBatch(
        string $type,
        array  $items,
        string $snapshotDate,
        ?string $categorySlug,
        mixed  $now
    ): int {
        $this->info("[{$type}] " . count($items) . " items");

        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;

            $extId = match ($type) {
                'product'  => $item['product_id'] ?? null,
                'shop'     => $item['seller_info']['seller_id'] ?? ($item['shop_id'] ?? null),
                'category' => $item['cate_id'] ?? ($item['category_id'] ?? null),
                'search'   => $item['product_id'] ?? null,
                default    => null,
            };

            $rows[] = [
                'type'          => $type,
                'snapshot_date' => $snapshotDate,
                'category_slug' => $categorySlug,
                'external_id'   => (string) ($extId ?? ''),
                'payload'       => json_encode($item, JSON_UNESCAPED_UNICODE),
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        // Dedup: apaga o snapshot anterior do mesmo (type, snapshot_date, external_id).
        $ids = array_filter(array_column($rows, 'external_id'));
        if (!empty($ids)) {
            DB::table('tt_shop_raw')
                ->where('type', $type)
                ->where('snapshot_date', $snapshotDate)
                ->whereIn('external_id', $ids)
                ->delete();
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('tt_shop_raw')->insert($chunk);
        }

        return count($rows);
    }
}
