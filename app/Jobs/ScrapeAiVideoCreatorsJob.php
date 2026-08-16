<?php

namespace App\Jobs;

use App\Models\AiCreator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-199 — ScrapeAiVideoCreatorsJob
 *
 * Busca criadores de vídeo IA no TikTok via hashtags de IA
 * (#aiugc #aivideo #klingai #midjourney #sora) usando tikwm API pública.
 *
 * Para cada vídeo retornado, extrai o perfil do autor e insere/atualiza
 * em ai_creators com source='scrape'.
 *
 * Schedule: diário às 8h BRT (11h UTC) — ver routes/console.php.
 *
 * Timeout: 900s (15 min).
 */
class ScrapeAiVideoCreatorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries = 2;

    /** Hashtags IA que definem o nicho. */
    private const HASHTAGS = [
        'aiugc',
        'aivideo',
        'klingai',
        'midjourney',
        'sora',
        'aiavatar',
    ];

    /** tikwm endpoint público (sem API key). */
    private const TIKWM_API = 'https://www.tikwm.com/api/';

    public function handle(): void
    {
        Log::info('[SEL-199] ScrapeAiVideoCreatorsJob started', ['hashtags' => self::HASHTAGS]);

        $inserted = 0;
        $skipped  = 0;

        foreach (self::HASHTAGS as $tag) {
            try {
                $profiles = $this->fetchProfilesByHashtag($tag);

                foreach ($profiles as $profile) {
                    $handle = $profile['handle'] ?? null;
                    if (!$handle) {
                        continue;
                    }

                    $exists = AiCreator::where('handle', $handle)->exists();
                    if ($exists) {
                        $skipped++;
                        continue;
                    }

                    AiCreator::create([
                        'handle'            => $handle,
                        'name'              => $profile['name'] ?? $handle,
                        'avatar_url'        => $profile['avatar_url'] ?? null,
                        'bio'               => $profile['bio'] ?? null,
                        'followers'         => $profile['followers'] ?? 0,
                        'videos_count'      => $profile['videos_count'] ?? 0,
                        'estimated_revenue' => $this->estimateRevenue($profile['followers'] ?? 0),
                        'source'            => 'scrape',
                        'raw'               => $profile['raw'] ?? null,
                        'is_visible'        => true,
                        'is_approved'       => false, // admin precisa aprovar scrape
                    ]);

                    $inserted++;
                }

                // Rate limit gentil: 2s entre hashtags
                sleep(2);
            } catch (\Throwable $e) {
                Log::warning("[SEL-199] Erro ao scrape #{$tag}: " . $e->getMessage());
            }
        }

        Log::info('[SEL-199] ScrapeAiVideoCreatorsJob done', [
            'inserted' => $inserted,
            'skipped'  => $skipped,
        ]);
    }

    /**
     * Busca perfis de autores de vídeos com a hashtag via tikwm.
     * Retorna array de perfis normalizados.
     */
    private function fetchProfilesByHashtag(string $tag): array
    {
        try {
            // tikwm pesquisa por keyword/hashtag, retorna lista de vídeos com author
            $response = Http::timeout(30)
                ->get(self::TIKWM_API, [
                    'keywords' => '#' . $tag,
                    'count'    => 20,
                    'region'   => 'BR',
                ]);

            if (!$response->successful()) {
                return [];
            }

            $json = $response->json();
            $videos = $json['data']['videos'] ?? $json['data'] ?? [];

            if (!is_array($videos)) {
                return [];
            }

            $profiles = [];
            $seen     = [];

            foreach ($videos as $video) {
                $author = $video['author'] ?? null;
                if (!$author) {
                    continue;
                }

                $handle = $author['unique_id'] ?? $author['uniqueId'] ?? null;
                if (!$handle || isset($seen[$handle])) {
                    continue;
                }
                $seen[$handle] = true;

                $profiles[] = [
                    'handle'       => $handle,
                    'name'         => $author['nickname'] ?? $handle,
                    'avatar_url'   => $author['avatar_thumb'] ?? $author['avatarThumb'] ?? null,
                    'bio'          => $author['signature'] ?? null,
                    'followers'    => (int) ($author['follower_count'] ?? $author['followerCount'] ?? 0),
                    'videos_count' => (int) ($author['video_count'] ?? $author['videoCount'] ?? 0),
                    'raw'          => $author,
                ];
            }

            return $profiles;
        } catch (\Throwable $e) {
            Log::warning("[SEL-199] tikwm fetch failed for #{$tag}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Estima faturamento com base em seguidores (heurística simples).
     * Micro: 1k-10k → R$5k-15k / Nano: 10k-100k → R$10k-50k / Macro: 100k+ → R$50k+
     */
    private function estimateRevenue(int $followers): float
    {
        if ($followers < 1_000) {
            return rand(1_000, 5_000);
        }
        if ($followers < 10_000) {
            return rand(5_000, 15_000);
        }
        if ($followers < 100_000) {
            return rand(10_000, 50_000);
        }
        if ($followers < 1_000_000) {
            return rand(50_000, 200_000);
        }

        return rand(200_000, 500_000);
    }
}
