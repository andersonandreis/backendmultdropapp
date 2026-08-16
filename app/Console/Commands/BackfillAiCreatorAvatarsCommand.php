<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * SEL-239 Ruan 18/07 — Backfill avatar_url REAL dos criadores IA via tikwm.
 *
 * Diferente do SEL-181 que trabalha em `tiktok_shop_trends`. Este mexe em
 * `ai_creators` (aba "Criadores IA"). Pra cada row sem avatar_url (30 novos
 * do SEL-235 + 1 legado), chama tikwm.com/api/user/info?unique_id=@{handle}
 * e salva avatarLarger URL + followers + likes atualizados.
 *
 * Roda dailyAt 07:15 BRT (entre backfill trends 07:00 e fetch products 07:30).
 */
class BackfillAiCreatorAvatarsCommand extends Command
{
    protected $signature = 'ai-creators:backfill-avatars
                            {--only-empty : Só rows com avatar_url NULL/vazio}
                            {--limit=60 : Máximo por execução}
                            {--sleep-ms=1100}';

    protected $description = 'SEL-239 Popula avatar_url real dos ai_creators via tikwm';

    public function handle(): int
    {
        $onlyEmpty = (bool) $this->option('only-empty');
        $limit = (int) $this->option('limit');
        $sleep = (int) $this->option('sleep-ms') * 1000;

        $q = DB::table('ai_creators')
            ->whereNotNull('handle')
            ->where('handle', '!=', '');
        if ($onlyEmpty) {
            $q->where(function ($w) {
                $w->whereNull('avatar_url')->orWhere('avatar_url', '');
            });
        }
        $rows = $q->limit($limit)->get(['id', 'handle']);
        $this->info("Processando {$rows->count()} criadores…");

        $ok = 0; $fail = 0;
        foreach ($rows as $r) {
            $handle = ltrim($r->handle, '@');
            try {
                $resp = Http::timeout(15)->get('https://tikwm.com/api/user/info', [
                    'unique_id' => '@' . $handle,
                ]);
                if (!$resp->successful()) { $fail++; continue; }
                $body = $resp->json();
                if (($body['code'] ?? 0) !== 0) {
                    $this->warn("[{$r->id}] @{$handle} tikwm code={$body['code']}");
                    $fail++;
                    if (str_contains($body['msg'] ?? '', 'Api Limit')) {
                        $this->warn('rate limit hit, sleeping 5s');
                        usleep(5_000_000);
                    }
                    continue;
                }
                $user = $body['data']['user'] ?? [];
                $stats = $body['data']['stats'] ?? [];

                $avatar = $user['avatarLarger'] ?? $user['avatarMedium'] ?? $user['avatarThumb'] ?? null;
                $update = ['updated_at' => now()];
                if ($avatar) $update['avatar_url'] = substr($avatar, 0, 1020);
                if (!empty($user['nickname'])) $update['name'] = mb_substr($user['nickname'], 0, 200);
                if (!empty($user['signature'])) $update['bio'] = mb_substr($user['signature'], 0, 490);
                if (isset($stats['followerCount'])) $update['followers'] = (int) $stats['followerCount'];
                if (isset($stats['videoCount'])) $update['videos_count'] = (int) $stats['videoCount'];
                if (isset($stats['heartCount'])) $update['likes_count'] = (int) $stats['heartCount'];

                DB::table('ai_creators')->where('id', $r->id)->update($update);
                $ok++;
                $this->line("[{$r->id}] @{$handle} → {$user['nickname']} ({$stats['followerCount']} followers)");
            } catch (\Throwable $e) {
                $this->error("[{$r->id}] @{$handle}: " . $e->getMessage());
                $fail++;
            }
            usleep($sleep);
        }

        $this->info("SEL-239 concluído: ok={$ok} fail={$fail}");
        return self::SUCCESS;
    }
}
