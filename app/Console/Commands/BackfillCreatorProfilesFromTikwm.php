<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-181 Ruan 16/07 — Backfill de perfis dos criadores TikTok Shop via tikwm.
 *
 * Contexto:
 *   Os ~120 criadores em tiktok_shop_trends WHERE kind='creator' foram criados
 *   manualmente (SEL-101 e outros seeds). Todos ja tem `handle` populado
 *   (SEL-175BE fez o backfill do handle). Mas o campo `raw` esta vazio ou
 *   incompleto — impede foto+seguidores+videos aparecerem no /admin/creators-review
 *   sem depender de chamada tikwm client-side.
 *
 * Solucao:
 *   Consulta tikwm.com/api/user/info?unique_id=@{handle} pra cada criador e
 *   grava o payload no raw JSON com nickname/avatar/follower_cnt/video_cnt/verified.
 *
 * Rate limit tikwm gratis: ~1 req/s (default --sleep-ms=1100 respeita).
 * Se bater "Free Api Limit": sleep 5s + retry 1x, se falhar de novo pula.
 */
class BackfillCreatorProfilesFromTikwm extends Command
{
    protected $signature = 'creators:backfill-from-tikwm
                            {--limit=200 : Numero maximo de criadores a processar por execucao}
                            {--only-empty : So rows sem raw popular (raw NULL, vazio ou "{}")}
                            {--sleep-ms=1100 : Sleep entre requests (rate limit tikwm gratis ~1req/s)}';

    protected $description = 'SEL-181: Backfill do raw JSON dos criadores TikTok (nickname/avatar/followers) via tikwm.com/api/user/info';

    private const TIKWM_URL = 'https://tikwm.com/api/user/info';

    public function handle(): int
    {
        $limit    = (int) $this->option('limit');
        $onlyEmpty = (bool) $this->option('only-empty');
        $sleepMs  = (int) $this->option('sleep-ms');

        $this->info("SEL-181 creators:backfill-from-tikwm iniciando... limit={$limit} only-empty=" . ($onlyEmpty ? 'true' : 'false') . " sleep-ms={$sleepMs}");

        $query = DB::table('tiktok_shop_trends')
            ->where('kind', 'creator')
            ->whereNotNull('handle')
            ->where('handle', '!=', '');

        if ($onlyEmpty) {
            $query->where(function ($q) {
                $q->whereNull('raw')
                  ->orWhere('raw', '')
                  ->orWhere('raw', '{}');
            });
        }

        $rows = $query->orderBy('id')->limit($limit)->get(['id', 'handle', 'raw']);
        $total = $rows->count();

        if ($total === 0) {
            $this->info('Nenhum criador para processar. Nada a fazer.');
            return self::SUCCESS;
        }

        $this->info("Criadores encontrados: {$total}");

        $processed  = 0;
        $sucesso    = 0;
        $semDados   = 0;
        $rateLimits = 0;

        foreach ($rows as $i => $row) {
            $processed++;
            $handle = ltrim((string) $row->handle, '@');

            if ($handle === '') {
                $semDados++;
                continue;
            }

            $result = $this->fetchTikwmUser($handle);

            // Rate limit: sleep 5s + retry 1x
            if ($result['rate_limited'] ?? false) {
                $rateLimits++;
                $this->warn("[{$processed}/{$total}] {$handle} -> rate limit, sleep 5s + retry");
                sleep(5);
                $result = $this->fetchTikwmUser($handle);
                if ($result['rate_limited'] ?? false) {
                    $this->warn("[{$processed}/{$total}] {$handle} -> rate limit persistiu, pulando");
                    usleep($sleepMs * 1000);
                    continue;
                }
            }

            if (empty($result['ok'])) {
                $semDados++;
                $this->line("[{$processed}/{$total}] {$handle} -> sem dados TT (" . ($result['reason'] ?? 'unknown') . ')');
                usleep($sleepMs * 1000);
                continue;
            }

            $user  = $result['user'];
            $stats = $result['stats'];

            // Merge no raw existente pra preservar campos setados por outros comandos
            $rawArr = [];
            if ($row->raw) {
                $decoded = json_decode($row->raw, true);
                if (is_array($decoded)) {
                    $rawArr = $decoded;
                }
            }

            $payload = array_merge($rawArr, [
                'handle'         => $user['uniqueId'] ?? $handle,
                'nickname'       => $user['nickname'] ?? null,
                'avatar'         => $user['avatarLarger'] ?? $user['avatarMedium'] ?? $user['avatarThumb'] ?? null,
                'follower_cnt'   => $stats['followerCount'] ?? null,
                'video_cnt'      => $stats['videoCount'] ?? null,
                'verified'       => $user['verified'] ?? false,
                'tikwm_fetched_at' => now()->toIso8601String(),
            ]);

            $update = [
                'raw'         => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'captured_at' => now(),
                'updated_at'  => now(),
            ];

            if (!empty($payload['avatar'])) {
                $update['images'] = json_encode([$payload['avatar']]);
            }

            DB::table('tiktok_shop_trends')->where('id', $row->id)->update($update);

            $sucesso++;
            $this->info("[{$processed}/{$total}] {$handle} -> nickname={$payload['nickname']} followers={$payload['follower_cnt']}");

            usleep($sleepMs * 1000);
        }

        $this->newLine();
        $this->info("=== SEL-181 concluido ===");
        $this->info("Processados: {$processed} | Sucesso: {$sucesso} | Sem dados TT: {$semDados} | Rate limited: {$rateLimits}");

        Log::info('creators:backfill-from-tikwm concluido', compact('processed', 'sucesso', 'semDados', 'rateLimits'));

        return self::SUCCESS;
    }

    /**
     * Faz o GET em tikwm.com/api/user/info e devolve normalizado.
     *
     * @return array{ok:bool, rate_limited?:bool, user?:array, stats?:array, reason?:string}
     */
    private function fetchTikwmUser(string $handle): array
    {
        try {
            $resp = Http::timeout(15)
                ->acceptJson()
                ->get(self::TIKWM_URL, ['unique_id' => '@' . $handle]);
        } catch (\Throwable $e) {
            Log::warning('tikwm request threw', ['handle' => $handle, 'error' => $e->getMessage()]);
            return ['ok' => false, 'reason' => 'http_exception'];
        }

        if (!$resp->ok()) {
            return ['ok' => false, 'reason' => 'http_' . $resp->status()];
        }

        $body = $resp->json();

        // Free Api Limit -> code=-1 msg="Free Api Limit"
        $code = $body['code'] ?? null;
        $msg  = $body['msg'] ?? '';
        if ($code === -1 && stripos($msg, 'Free Api Limit') !== false) {
            return ['ok' => false, 'rate_limited' => true];
        }

        $data  = $body['data'] ?? null;
        $user  = $data['user']  ?? null;
        $stats = $data['stats'] ?? [];

        if (!is_array($user) || empty($user['uniqueId'] ?? null)) {
            return ['ok' => false, 'reason' => 'no_user_data'];
        }

        return [
            'ok'    => true,
            'user'  => $user,
            'stats' => is_array($stats) ? $stats : [],
        ];
    }
}
