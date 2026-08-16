<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\GoolhubBridgeService;
use App\Services\ImageDownloadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillProductMedia extends Command
{
    protected $signature = 'products:backfill-media
                            {--supplier= : ID do supplier (omitir = todos)}
                            {--batch=50 : Tamanho do batch}
                            {--limit= : Limite de produtos (debug)}
                            {--force : Reprocessa mesmo se ja tiver imagens}
                            {--only-missing : Processa apenas produtos sem nenhuma entrada em product_media}';

    protected $description = 'Backfill product_media usando sku_pai_imagens do legado';

    public function handle(GoolhubBridgeService $bridge, ImageDownloadService $imageSvc): int
    {
        $supplierId = $this->option('supplier');
        $batch      = max(1, (int) $this->option('batch'));
        $force      = (bool) $this->option('force');
        $limit      = $this->option('limit') ? (int) $this->option('limit') : null;

        $onlyMissing = (bool) $this->option('only-missing');
        $q = Product::query()->whereNotNull('legacy_sku_pai_id');
        if ($supplierId) $q->where('supplier_id', (int) $supplierId);
        if ($onlyMissing) {
            $q->whereNotIn('id', fn($sub) => $sub->select('product_id')->from('product_media'));
        }
        if ($limit) $q->limit($limit);

        $total = (clone $q)->count();
        $this->info("Produtos a processar: $total (supplier=" . ($supplierId ?? 'all') . ", batch=$batch, force=" . ($force ? 'yes' : 'no') . ')');

        $processed = 0;
        $insertedRows = 0;
        $skipped = 0;
        $errors = 0;

        $q->orderBy('id')->chunkById($batch, function ($chunk) use (&$processed, &$insertedRows, &$skipped, &$errors, $bridge, $imageSvc, $force) {
            $ids = $chunk->pluck('legacy_sku_pai_id')->filter()->values()->all();
            if (!$ids) return;

            $res = $bridge->getSkuImagens($ids);
            if (!$res['success']) {
                $this->error('Bridge falhou: ' . ($res['error'] ?? '?'));
                $errors += count($chunk);
                return;
            }

            $map = (array) ($res['data'] ?? []);

            foreach ($chunk as $product) {
                $processed++;
                $key = (string) $product->legacy_sku_pai_id;
                $imgs = $map[$key] ?? [];

                if (!$imgs) { $skipped++; continue; }

                $existing = DB::table('product_media')
                    ->where('product_id', $product->id)
                    ->pluck('url')->all();

                if ($existing && !$force) {
                    $existingSet = array_flip($existing);
                    $newRows = [];
                    foreach ($imgs as $idx => $im) {
                        $url = $this->normalizeImageUrl($im['img'] ?? null);
                        if (!$url) continue;
                        if (isset($existingSet[$url])) continue;

                        $mat = $imageSvc->ensureLocal($url, (int) $product->supplier_id, (int) $product->id);
                        $newRows[] = [
                            'product_id'   => $product->id,
                            'url'          => $mat['url'],
                            'original_url' => $mat['local'] ? $url : null,
                            'local_path'   => $mat['path'],
                            'type'         => 'image',
                            'is_cover'     => 0,
                            'position'     => $im['posicao'] ?? $idx,
                            'created_at'   => now(),
                            'updated_at'   => now(),
                        ];
                    }
                    if ($newRows) {
                        DB::table('product_media')->insert($newRows);
                        $insertedRows += count($newRows);
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                // force OR vazio: limpa e regrava
                DB::table('product_media')->where('product_id', $product->id)->delete();
                $newRows = [];
                foreach ($imgs as $idx => $im) {
                    $url = $this->normalizeImageUrl($im['img'] ?? null);
                    if (!$url) continue;

                    $mat = $imageSvc->ensureLocal($url, (int) $product->supplier_id, (int) $product->id);
                    $newRows[] = [
                        'product_id'   => $product->id,
                        'url'          => $mat['url'],
                        'original_url' => $mat['local'] ? $url : null,
                        'local_path'   => $mat['path'],
                        'type'         => 'image',
                        'is_cover'     => $idx === 0 ? 1 : 0,
                        'position'     => $im['posicao'] ?? $idx,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ];
                }
                if ($newRows) {
                    DB::table('product_media')->insert($newRows);
                    $insertedRows += count($newRows);
                }
            }

            $this->line("  ... processados $processed, inseridos $insertedRows, skip $skipped");
        });

        $this->info("OK — processados $processed, inseridos $insertedRows, skip $skipped, erros $errors");
        return 0;
    }

    /**
     * Normaliza URL de imagem vinda do bridge legado.
     * Reescreve host historico (www.)sistemagrupoonline.com.br -> goolhub.io,
     * onde os arquivos realmente moram.
     */
    private function normalizeImageUrl(?string $url): ?string
    {
        if (!$url) return null;
        $u = trim((string) $url);
        if (!$u) return null;

        // Rejeita URLs de outros tenants (nunca inserir fornecefy.io no MultDrop)
        $ownHost = parse_url(config('app.url', ''), PHP_URL_HOST) ?? '';
        $urlHost = parse_url($u, PHP_URL_HOST) ?? '';
        if ($urlHost && $urlHost !== $ownHost && stripos($urlHost, 'fornecefy') !== false) {
            return null;
        }

        // URLs relativas do legado: /storage/products/filename.ext
        // Estes arquivos foram migrados para BunnyCDN em 24/06 como /products/filename.ext
        // Usa CDN_URL da WL atual (Fornecefy -> fornecefy-images; MultDrop -> multdrop-images)
        if (str_starts_with($u, '/storage/products/')) {
            $filename = basename($u);
            $cdnBase = rtrim(config('services.bunnycdn.pull_zone_url', env('CDN_URL', 'https://multdrop-images.b-cdn.net')), '/');
            return $cdnBase . '/products/' . $filename;
        }

        // Normaliza host legado obsoleto -> goolhub.io
        $u = str_replace('www.sistemagrupoonline.com.br', 'goolhub.io', $u);
        $u = str_replace('://sistemagrupoonline.com.br', '://goolhub.io', $u);

        return $u;
    }

}
