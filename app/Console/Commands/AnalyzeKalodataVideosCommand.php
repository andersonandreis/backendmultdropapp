<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SEL-336 -- Cron diario que analisa videos virais do Kalodata via Whisper + GPT-4o-mini.
 *
 * Fluxo por video:
 *   1. Pega top videos de kalodata_raw (type=videos) sem analise em video_analysis
 *   2. Tenta baixar o mp4 via yt-dlp (fallback: URL direta do payload)
 *   3. Transcreve com Whisper API (openai audio transcriptions)
 *   4. Extrai estrutura viral (hook/problem/solution/cta/vibe) via GPT-4o-mini
 *   5. Salva em video_analysis com custos estimados
 *
 * Limites:
 *   --limit=20  por rodada (nao sobrecarregar cota Whisper -- R$0,05/video)
 *   --country=BR|US  (default BR)
 *   --dry  apenas lista, nao chama APIs externas
 *
 * Uso:
 *   php artisan kalodata:analyze-videos
 *   php artisan kalodata:analyze-videos --limit=50 --country=US
 *   php artisan kalodata:analyze-videos --dry
 */
class AnalyzeKalodataVideosCommand extends Command
{
    protected $signature = 'kalodata:analyze-videos
                            {--limit=20   : Maximo de videos a analisar por rodada}
                            {--country=BR : BR ou US}
                            {--dry        : Simula sem chamar Whisper/GPT}';

    protected $description = 'SEL-336 -- Transcreve videos virais Kalodata e extrai estrutura viral (hook/problem/solution/cta)';

    /** Custo estimado Whisper por minuto de audio (USD) */
    private const WHISPER_COST_PER_MIN = 0.006;
    /** Custo estimado GPT-4o-mini por 1K tokens de saida (USD) */
    private const GPT_COST_PER_1K_OUT = 0.0006;

    public function handle(): int
    {
        $limit   = (int) $this->option('limit');
        $country = strtoupper($this->option('country'));
        $dry     = (bool) $this->option('dry');

        $apiKey  = config('services.openai.api_key');
        if (!$apiKey && !$dry) {
            $this->error('OPENAI_API_KEY nao configurada -- abortando');
            return self::FAILURE;
        }

        $this->info("SEL-336 AnalyzeKalodataVideos | country=$country limit=$limit" . ($dry ? ' [DRY-RUN]' : ''));

        // -- 1. Busca videos sem analise
        $latestDate = DB::table('kalodata_raw')
            ->where('type', 'videos')
            ->max('snapshot_date');

        if (!$latestDate) {
            $this->warn("Nenhuma linha em kalodata_raw type=videos");
            return self::SUCCESS;
        }

        $existing = DB::table('video_analysis')
            ->where('country', $country)
            ->pluck('kalodata_video_id')
            ->flip()
            ->all();

        $rows = DB::table('kalodata_raw')
            ->where('type', 'videos')
            ->where('snapshot_date', $latestDate)
            ->orderBy('id')
            ->get(['external_id', 'payload']);

        $pending   = $rows->filter(fn ($r) => $r->external_id && !isset($existing[$r->external_id]));
        $toProcess = $pending->take($limit);

        $this->line("Encontrados: " . $pending->count() . " sem analise | processando: " . $toProcess->count());

        if ($dry) {
            foreach ($toProcess as $r) {
                $p = json_decode($r->payload, true);
                $this->line("  [DRY] {$r->external_id} -- " . ($p['title'] ?? '(sem titulo)'));
            }
            return self::SUCCESS;
        }

        // -- 2. Processa cada video
        $ok = 0;
        $fail = 0;

        foreach ($toProcess as $row) {
            try {
                $this->line("Analisando {$row->external_id}...");
                $payload = json_decode($row->payload, true);
                $result  = $this->analyzeVideo($row->external_id, $payload, $country, $apiKey);

                DB::table('video_analysis')->updateOrInsert(
                    ['kalodata_video_id' => $row->external_id, 'country' => $country],
                    array_merge($result, [
                        'analyzed_at' => now(),
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ])
                );

                $ok++;
                $this->line("  OK -- vibe={$result['vibe']} dur={$result['duration_sec']}s");
                usleep(500000); // 500ms entre videos (gentle rate limit)

            } catch (\Throwable $e) {
                $fail++;
                Log::error("[SEL-336] Erro em {$row->external_id}: " . $e->getMessage());
                $this->warn("  FAIL -- {$row->external_id}: " . $e->getMessage());
            }
        }

        $this->info("Concluido: $ok ok / $fail fail");
        return self::SUCCESS;
    }

    private function analyzeVideo(string $videoId, array $payload, string $country, string $apiKey): array
    {
        $videoUrl  = $this->resolveVideoUrl($payload);
        $localPath = $this->downloadVideo($videoUrl, $videoId);

        [$transcript, $durationSec, $whisperCost] = $this->transcribe($localPath, $apiKey);

        [$hook, $problem, $solution, $cta, $vibe, $gptCost] = $this->extractStructure(
            $transcript,
            $payload['title'] ?? '',
            $apiKey
        );

        $cachedUrl = $videoUrl;
        if ($localPath && file_exists($localPath)) {
            $storageName = "tt-analysis/{$videoId}.mp4";
            Storage::disk('public')->put($storageName, file_get_contents($localPath));
            $cachedUrl = Storage::disk('public')->url($storageName);
            @unlink($localPath);
        }

        return [
            'kalodata_video_id' => $videoId,
            'country'           => $country,
            'transcript'        => $transcript,
            'hook_0_3s'         => $hook,
            'problem'           => $problem,
            'solution'          => $solution,
            'cta'               => $cta,
            'vibe'              => in_array($vibe, ['review', 'unboxing', 'showcase', 'reacao', 'tutorial', 'outro']) ? $vibe : 'outro',
            'duration_sec'      => $durationSec,
            'video_url_cached'  => $cachedUrl,
            'whisper_cost_usd'  => round($whisperCost, 6),
            'gpt_cost_usd'      => round($gptCost, 6),
        ];
    }

    private function resolveVideoUrl(array $payload): string
    {
        foreach (['video_url', 'video_src', 'url', 'media_url', 'tiktok_url'] as $field) {
            if (!empty($payload[$field]) && str_starts_with($payload[$field], 'http')) {
                return $payload[$field];
            }
        }

        $id = $payload['video_id'] ?? $payload['id'] ?? null;
        if ($id) {
            return "https://www.tiktok.com/@user/video/{$id}";
        }

        throw new \RuntimeException("Nenhuma URL de video encontrada no payload");
    }

    private function downloadVideo(string $url, string $videoId): ?string
    {
        $tmpPath = sys_get_temp_dir() . "/sel336_{$videoId}.mp4";

        $ytdlp = '/usr/local/bin/yt-dlp';
        if (file_exists($ytdlp)) {
            $cmd = $ytdlp
                . ' --quiet --no-warnings'
                . ' -f "bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best"'
                . ' --merge-output-format mp4'
                . ' --no-playlist'
                . ' --max-filesize 50M'
                . ' -o ' . escapeshellarg($tmpPath)
                . ' ' . escapeshellarg($url)
                . ' 2>/dev/null';
            exec($cmd, $out, $code);
            if ($code === 0 && file_exists($tmpPath) && filesize($tmpPath) > 0) {
                return $tmpPath;
            }
        }

        if (str_contains($url, 'tikwm.com') || str_contains($url, 's3.') || str_ends_with($url, '.mp4')) {
            try {
                $resp = Http::withOptions(['sink' => $tmpPath])->timeout(30)->get($url);
                if ($resp->successful() && file_exists($tmpPath) && filesize($tmpPath) > 0) {
                    return $tmpPath;
                }
            } catch (\Throwable $e) {
                Log::debug("[SEL-336] curl fallback falhou: " . $e->getMessage());
            }
        }

        @unlink($tmpPath);
        return null;
    }

    private function transcribe(?string $localPath, string $apiKey): array
    {
        if (!$localPath || !file_exists($localPath)) {
            return ['(transcricao indisponivel -- download falhou)', 0, 0.0];
        }

        $fileSize = filesize($localPath);
        if ($fileSize > 25 * 1024 * 1024) {
            $compressed = sys_get_temp_dir() . '/sel336_compressed_' . uniqid() . '.mp3';
            exec("ffmpeg -i " . escapeshellarg($localPath) . " -ar 16000 -ac 1 -b:a 32k " . escapeshellarg($compressed) . " -y -loglevel quiet");
            if (file_exists($compressed) && filesize($compressed) > 0) {
                $localPath = $compressed;
            }
        }

        $resp = Http::withToken($apiKey)
            ->attach('file', fopen($localPath, 'r'), basename($localPath))
            ->post('https://api.openai.com/v1/audio/transcriptions', [
                'model'           => 'whisper-1',
                'response_format' => 'verbose_json',
                'language'        => 'pt',
            ]);

        if (!$resp->successful()) {
            throw new \RuntimeException("Whisper API error " . $resp->status() . ": " . $resp->body());
        }

        $data     = $resp->json();
        $text     = $data['text'] ?? '';
        $duration = (float) ($data['duration'] ?? 0);
        $cost     = ($duration / 60) * self::WHISPER_COST_PER_MIN;

        return [$text, (int) round($duration), $cost];
    }

    private function extractStructure(string $transcript, string $title, string $apiKey): array
    {
        if (empty(trim($transcript)) || str_starts_with($transcript, '(transcricao')) {
            return ['', '', '', '', 'outro', 0.0];
        }

        $systemPrompt = 'Voce e especialista em marketing viral no TikTok Shop Brasil. '
            . 'Dado a transcricao de um video viral, extraia a estrutura narrativa. '
            . 'Responda SOMENTE em JSON valido (sem markdown, sem explicacao): '
            . '{"hook_0_3s":"o gancho inicial -- o que prendeu a atencao nos primeiros 3 segundos",'
            . '"problem":"qual dor/problema o video aborda (pode ser vazio)",'
            . '"solution":"como o produto resolve o problema ou qual beneficio entrega",'
            . '"cta":"chamada pra acao no fim (ex: link na bio, compra agora, pode ser vazio)",'
            . '"vibe":"review|unboxing|showcase|reacao|tutorial|outro"}';

        $userMsg = "Titulo: {$title}\n\nTranscricao:\n{$transcript}";

        $resp = Http::withToken($apiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'       => 'gpt-4o-mini',
                'max_tokens'  => 300,
                'temperature' => 0.2,
                'messages'    => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userMsg],
                ],
            ]);

        if (!$resp->successful()) {
            throw new \RuntimeException("GPT API error " . $resp->status());
        }

        $content = $resp->json('choices.0.message.content', '{}');
        $tokens  = $resp->json('usage.completion_tokens', 200);
        $cost    = ($tokens / 1000) * self::GPT_COST_PER_1K_OUT;

        $json = json_decode($content, true);
        if (!is_array($json)) {
            preg_match('/\{[\s\S]+\}/u', $content, $m);
            $json = $m ? json_decode($m[0], true) : [];
        }

        return [
            $json['hook_0_3s'] ?? '',
            $json['problem']   ?? '',
            $json['solution']  ?? '',
            $json['cta']       ?? '',
            $json['vibe']      ?? 'outro',
            $cost,
        ];
    }
}
