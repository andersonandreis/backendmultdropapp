<?php

namespace App\Console\Commands;

use App\Models\AiCreator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-217 — php artisan ai-creators:scrape-tiktok
 *
 * Busca perfis de criadores IA no TikTok via tikwm.com/api/feed/search
 * usando keywords BR de IA (criador ia, avatar ia, shop creator ia, etc).
 *
 * Complementa o ScrapeAiVideoCreatorsJob (hashtags globais) com keywords
 * em português focadas no mercado BR.
 *
 * Uso: php artisan ai-creators:scrape-tiktok [--keywords=xxx] [--dry-run]
 */
class ScrapeTikTokIaCreatorsCommand extends Command
{
    protected $signature = 'ai-creators:scrape-tiktok
                            {--dry-run : Apenas lista sem inserir}
                            {--keywords= : Keywords customizadas separadas por vírgula}';

    protected $description = 'SEL-217: Scrape tikwm keywords IA BR → ai_creators (source=scrape)';

    /** Keywords BR + EN focadas em criadores IA para TikTok Shop */
    private const DEFAULT_KEYWORDS = [
        'shop creator ia',
        'criador ia tiktok shop',
        'avatar ia shop br',
        'ugc ia brasil',
        'criador ia video',
        'kling ai brasil',
        'avatar ia tiktok',
        'video ia dropshipping',
        'criador digital ia',
        'ai ugc creator',
        'ai avatar shop',
        'kling creator br',
    ];

    private const TIKWM_API = 'https://www.tikwm.com/api/feed/search';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        $keywordsRaw = $this->option('keywords');
        $keywords    = $keywordsRaw
            ? array_map('trim', explode(',', $keywordsRaw))
            : self::DEFAULT_KEYWORDS;

        $this->info('[SEL-217] ScrapeTikTokIaCreatorsCommand iniciado' . ($isDryRun ? ' (dry-run)' : ''));
        $this->info('Keywords: ' . implode(', ', $keywords));

        $inserted = 0;
        $skipped  = 0;
        $seenHandles = AiCreator::pluck('handle')->flip()->toArray(); // índice rápido de handles existentes

        foreach ($keywords as $keyword) {
            $this->line("  Buscando: \"{$keyword}\"...");

            try {
                $profiles = $this->fetchByKeyword($keyword);
                $this->line("    -> {$profiles->count()} perfis encontrados");

                foreach ($profiles as $profile) {
                    $handle = $profile['handle'] ?? null;
                    if (!$handle) {
                        continue;
                    }

                    // Normaliza: remove @
                    $handle = ltrim($handle, '@');

                    if (isset($seenHandles[$handle])) {
                        $skipped++;
                        continue;
                    }

                    $seenHandles[$handle] = true; // marca pra não duplicar na mesma execução

                    $this->line("    + @{$handle} ({$profile['name']}) — {$profile['followers']} seguidores");

                    if (!$isDryRun) {
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
                            'is_approved'       => true, // SEL-217: auto-aprovar scrape BR
                        ]);
                    }
                    $inserted++;
                }

                // Rate limit gentil entre keywords
                if (!$isDryRun) {
                    sleep(1);
                }
            } catch (\Throwable $e) {
                $this->warn("    Erro ao scrape \"{$keyword}\": " . $e->getMessage());
                Log::warning("[SEL-217] scrape-tiktok error", [
                    'keyword' => $keyword,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $total = AiCreator::where('is_approved', true)->count();

        $this->newLine();
        $this->info("Scrape concluído:");
        $this->info("  Novos inseridos: {$inserted}");
        $this->info("  Já existiam: {$skipped}");
        $this->info("  Total no banco: {$total}");

        if ($total < 60) {
            $this->warn("  AVISO: total ({$total}) < 60 — pode precisar de mais keywords ou seed manual");
        }

        Log::info('[SEL-217] scrape-tiktok done', [
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'total'    => $total,
            'dry_run'  => $isDryRun,
        ]);

        return self::SUCCESS;
    }

    /**
     * Busca por keyword no tikwm feed/search.
     * Retorna Collection de perfis normalizados.
     */
    private function fetchByKeyword(string $keyword): \Illuminate\Support\Collection
    {
        // Tenta duas variantes: com e sem cursor
        $profiles = collect();

        foreach ([null, '0'] as $cursor) {
            try {
                $params = [
                    'keywords' => $keyword,
                    'count'    => 20,
                    'region'   => 'BR',
                ];

                if ($cursor !== null) {
                    $params['cursor'] = $cursor;
                }

                $response = Http::timeout(30)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get(self::TIKWM_API, $params);

                if (!$response->successful()) {
                    continue;
                }

                $json   = $response->json();
                $videos = $json['data']['videos'] ?? $json['data'] ?? [];

                if (!is_array($videos)) {
                    continue;
                }

                foreach ($videos as $video) {
                    $author = $video['author'] ?? null;
                    if (!$author) {
                        continue;
                    }

                    $handle = $author['unique_id'] ?? $author['uniqueId'] ?? null;
                    if (!$handle) {
                        continue;
                    }

                    // Dedup interna por handle dentro da keyword
                    if ($profiles->firstWhere('handle', $handle)) {
                        continue;
                    }

                    $profiles->push([
                        'handle'       => ltrim($handle, '@'),
                        'name'         => $author['nickname'] ?? $handle,
                        'avatar_url'   => $author['avatar_thumb'] ?? $author['avatarThumb'] ?? null,
                        'bio'          => $author['signature'] ?? null,
                        'followers'    => (int) ($author['follower_count'] ?? $author['followerCount'] ?? 0),
                        'videos_count' => (int) ($author['video_count'] ?? $author['videoCount'] ?? 0),
                        'raw'          => $author,
                    ]);
                }

                break; // sucesso na primeira variante que funcionar
            } catch (\Throwable $e) {
                // tenta próxima variante
            }
        }

        return $profiles;
    }

    private function estimateRevenue(int $followers): float
    {
        if ($followers < 1_000)     return rand(1_000, 5_000);
        if ($followers < 10_000)    return rand(5_000, 15_000);
        if ($followers < 100_000)   return rand(10_000, 50_000);
        if ($followers < 1_000_000) return rand(50_000, 200_000);
        return rand(200_000, 500_000);
    }
}
