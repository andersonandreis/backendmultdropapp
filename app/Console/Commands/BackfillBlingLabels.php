<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Order;

class BackfillBlingLabels extends Command
{
    protected $signature = 'labels:backfill-bling
                            {--limit=0 : Limit number of orders to process (0 = all)}
                            {--dry-run : Show counts without updating}';

    protected $description = 'Backfill label_url for paid Bling orders from legacy DB (url_img)';

    public function handle(): int
    {
        $limit  = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $query = Order::where('source', 'bling')
            ->whereNotNull('legacy_id')
            ->whereNull('label_url')
            ->where('status', 'paid');

        $total = $query->count();
        $this->info("Paid Bling orders without label: {$total}");

        if ($dryRun) {
            // How many have url_img in legacy?
            $withLabel = 0;
            $query->select(['id', 'legacy_id'])->chunkById(500, function ($chunk) use (&$withLabel) {
                $legacyIds = $chunk->pluck('legacy_id')->all();
                $count = DB::connection('legacy')
                    ->table('pedidos')
                    ->whereIn('id', $legacyIds)
                    ->whereNotNull('url_img')
                    ->count();
                $withLabel += $count;
            });
            $this->info("Legacy has label for {$withLabel} of those orders.");
            return self::SUCCESS;
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $disk    = Storage::disk('public');
        $ok      = 0;
        $skipped = 0;
        $fail    = 0;

        $query->select(['id', 'legacy_id'])->chunkById(500, function ($chunk) use ($disk, &$ok, &$skipped, &$fail) {
            $legacyIds = $chunk->pluck('legacy_id')->all();

            $legacyRows = DB::connection('legacy')
                ->table('pedidos')
                ->whereIn('id', $legacyIds)
                ->whereNotNull('url_img')
                ->pluck('url_img', 'id');

            foreach ($chunk as $order) {
                $legacyUrl = $legacyRows[$order->legacy_id] ?? null;
                if (!$legacyUrl) {
                    $skipped++;
                    continue;
                }

                // Check if already in local storage
                $hash = md5($legacyUrl);
                $found = false;
                foreach (['pdf', 'jpg', 'png', 'webp', 'gif'] as $ext) {
                    $path = 'labels/' . $hash . '.' . $ext;
                    if ($disk->exists($path)) {
                        $order->updateQuietly(['label_url' => '/storage/' . $path]);
                        $ok++;
                        $found = true;
                        break;
                    }
                }
                if ($found) continue;

                // Download from legacy URL
                $local = $this->downloadLabel($legacyUrl, $order, $disk);
                if ($local) {
                    $ok++;
                } else {
                    // Fallback: store external URL directly
                    $order->updateQuietly(['label_url' => $legacyUrl]);
                    $fail++;
                }
            }
        });

        $this->info("Done — updated={$ok} no_label_in_legacy={$skipped} download_fail={$fail}");

        return self::SUCCESS;
    }

    private function downloadLabel(string $url, Order $order, $disk): ?string
    {
        $isSistema = str_contains($url, 'sistemagrupoonline');
        $fetchUrl  = $isSistema
            ? str_replace(['https://', ' '], ['http://', '%20'], $url)
            : str_replace(' ', '%20', $url);

        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible)',
            CURLOPT_SSL_VERIFYPEER => false,
        ];
        if ($isSistema) {
            $legacyIp   = env('LEGACY_WEB_IP', '217.216.82.83');
            $legacyHost = env('LEGACY_WEB_HOST', 'www.sistemagrupoonline.com.br');
            $curlOpts[CURLOPT_RESOLVE] = [
                "{$legacyHost}:80:{$legacyIp}",
                "sistemagrupoonline.com.br:80:{$legacyIp}",
            ];
        }
        $ch = curl_init($fetchUrl);
        curl_setopt_array($ch, $curlOpts);
        $body = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if (!$body || $info['http_code'] < 200 || $info['http_code'] >= 300 || strlen($body) > 20971520) {
            return null;
        }

        $ext = $this->detectExt($body, $info['content_type'] ?? '');
        if (!$ext) return null;

        $filename = 'labels/' . md5($url) . '.' . $ext;
        $disk->put($filename, $body);

        $local = '/storage/' . $filename;
        $order->updateQuietly(['label_url' => $local]);

        return $local;
    }

    private function detectExt(string $bytes, string $contentType): ?string
    {
        if (strlen($bytes) >= 4) {
            if (substr($bytes, 0, 4) === '%PDF') return 'pdf';
            if (substr($bytes, 0, 3) === "\xFF\xD8\xFF") return 'jpg';
            if (substr($bytes, 0, 4) === "\x89PNG") return 'png';
            if (substr($bytes, 0, 4) === 'GIF8') return 'gif';
            if (substr($bytes, 0, 4) === 'RIFF' && strlen($bytes) >= 12 && substr($bytes, 8, 4) === 'WEBP') return 'webp';
        }
        $ct = strtolower(trim(explode(';', $contentType)[0]));
        return match ($ct) {
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'image/gif'       => 'gif',
            default           => null,
        };
    }
}
