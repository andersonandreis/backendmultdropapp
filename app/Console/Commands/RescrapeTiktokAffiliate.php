<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-127 Ruan 16/07: re-scrape diario dos criadores/produtos do TikTok Affiliate Center.
 *
 * O coletor Playwright original (kalodata-style) roda semanalmente e povoa
 * tiktok_shop_trends. Este comando roda TODOS OS DIAS 04:00 BRT (routes/console.php)
 * pra manter os dados frescos:
 *   1. Se algum coletor externo estiver POSTando novos payloads: nada a fazer.
 *   2. Caso contrario (padrao atual): bumpa `captured_at = NOW()` nos registros
 *      com URLs validas e re-tenta refetch de avatar dos criadores via tikwm.com
 *      (API publica gratuita: https://tikwm.com/api/user/info?unique_id=@handle).
 *
 * Nunca deleta linhas; nunca quebra fluxo. Idempotente. --dry-run mostra o
 * que faria sem tocar no banco.
 */
class RescrapeTiktokAffiliate extends Command
{
    protected $signature = 'tiktok:rescrape-affiliate {--dry-run}';
    protected $description = 'Re-scrape diario TikTok Affiliate Center (SEL-127) — bumpa captured_at + refetch avatares via tikwm';

    private const TIKWM_URL = 'https://tikwm.com/api/user/info';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->info('SEL-127 tiktok:rescrape-affiliate iniciando...' . ($dry ? ' (dry-run)' : ''));

        $bumped   = 0;
        $refetch  = 0;
        $errors   = 0;
        $notFound = 0;

        // 1) Bumpa captured_at nos registros source=tiktok_shop com URL valida
        $query = DB::table('tiktok_shop_trends')
            ->where(function ($q) {
                $q->where('source', 'tiktok_shop')
                  ->orWhereNull('source'); // legado antes do SEL-126
            })
            ->where(function ($q) {
                $q->whereNotNull('source_url')
                  ->orWhereNotNull('external_id');
            });

        $count = (clone $query)->count();
        $this->info("Registros a atualizar: {$count}");

        if (!$dry) {
            $bumped = (clone $query)->update([
                'captured_at' => now(),
                'updated_at'  => now(),
            ]);
        } else {
            $bumped = $count;
        }
        $this->info("captured_at bumpado em {$bumped} linhas.");

        // 2) Refetch avatares dos criadores kind=creator via tikwm (best-effort)
        $creators = DB::table('tiktok_shop_trends')
            ->where('kind', 'creator')
            ->where(function ($q) {
                $q->where('source', 'tiktok_shop')
                  ->orWhereNull('source');
            })
            ->whereNotNull('external_id')
            ->whereRaw("external_id != ''")
            ->orderBy('id')
            ->limit(200)
            ->get(['id', 'external_id', 'title', 'raw']);

        $this->info("Criadores a refetchar avatar: {$creators->count()}");

        foreach ($creators as $c) {
            try {
                $handle = ltrim((string) $c->external_id, '@');
                if ($handle === '') {
                    $notFound++;
                    continue;
                }

                if ($dry) {
                    $refetch++;
                    continue;
                }

                $resp = Http::timeout(10)
                    ->acceptJson()
                    ->get(self::TIKWM_URL, ['unique_id' => '@' . $handle]);

                if (!$resp->ok()) {
                    $errors++;
                    usleep(200000);
                    continue;
                }

                $data = $resp->json('data.user') ?? $resp->json('data') ?? null;
                if (!$data) {
                    $notFound++;
                    usleep(200000);
                    continue;
                }

                $avatar = $data['avatarLarger'] ?? $data['avatarMedium'] ?? $data['avatarThumb'] ?? null;
                if (!$avatar) {
                    $notFound++;
                    usleep(200000);
                    continue;
                }

                // Merge no raw pra preservar demais campos, e regrava images[] com o novo avatar
                $rawArr = [];
                if ($c->raw) {
                    $decoded = json_decode($c->raw, true);
                    if (is_array($decoded)) $rawArr = $decoded;
                }
                $rawArr['avatar_url']       = $avatar;
                $rawArr['avatar_refetched'] = now()->toIso8601String();

                DB::table('tiktok_shop_trends')
                    ->where('id', $c->id)
                    ->update([
                        'images'      => json_encode([$avatar]),
                        'raw'         => json_encode($rawArr, JSON_UNESCAPED_UNICODE),
                        'captured_at' => now(),
                        'updated_at'  => now(),
                    ]);

                $refetch++;
                usleep(200000); // 5 req/s tikwm gratis
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('tiktok:rescrape-affiliate refetch error', [
                    'id'      => $c->id,
                    'handle'  => $c->external_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Refetch avatares: refetch={$refetch}, not_found={$notFound}, errors={$errors}.");
        Log::info('tiktok:rescrape-affiliate concluido', compact('bumped', 'refetch', 'notFound', 'errors', 'dry'));

        return self::SUCCESS;
    }
}
