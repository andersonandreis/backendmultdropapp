<?php

namespace App\Console\Commands;

use App\Services\KalodataService;
use App\Services\TelegramNotificationService;
use App\Services\TikTokMediaService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-256 — sync diário Kalodata TikTok Shop BR.
 *
 * Roda 3x/dia (06:00, 12:00, 20:00 BRT). Cada rodada puxa top 100 products +
 * top 50 creators + top 50 videos + top 30 lives + top 50 shops últimos 7 dias
 * e grava snapshot cru em `kalodata_raw`.
 *
 * Uso:
 *   php artisan kalodata:sync                    (todos os tipos)
 *   php artisan kalodata:sync --type=products    (só produtos)
 *   php artisan kalodata:sync --window=30d       (janela 30d em vez de 7d)
 *
 * SEL-409 (30/07/2026) — este comando passou 7 dias imprimindo
 * "✅ Total inserido: 0 linhas" e saindo com sucesso enquanto a Cloudflare da
 * kalodata.com devolvia 403 em todas as chamadas. Zero linha em failed_jobs,
 * zero erro no log (o único aviso era um Log::warning, invisível com
 * LOG_LEVEL=error). O cliente do TikTok Shopping decidiu o que vender em cima
 * de métrica de 6 dias atrás.
 *
 * Regra que ficou: sync que traz zero é FALHA, não sucesso. Nunca mais imprimir
 * ✅ sem ter gravado linha.
 */
class KalodataSyncCommand extends Command
{
    protected $signature = 'kalodata:sync
                            {--type= : produtos|creators|videos|lives|shops (default: todos)}
                            {--window=7d : 7d ou 30d}
                            {--dry : não grava no banco, só reporta contagem}';

    protected $description = 'SEL-256 puxa snapshot Kalodata TikTok Shop BR e grava em kalodata_raw';

    private const PAGES_BY_TYPE = [
        'products' => 10, // 100 items
        'creators' => 5,  // 50 items
        'videos'   => 5,  // 50 items
        'lives'    => 3,  // 30 items
        'shops'    => 5,  // 50 items
    ];

    public function handle(KalodataService $svc, TikTokMediaService $media): int
    {
        $window = $this->option('window');
        $endDate = Carbon::now('America/Sao_Paulo')->subDay()->format('Y-m-d');
        $days = $window === '30d' ? 30 : 7;
        $startDate = Carbon::parse($endDate)->subDays($days - 1)->format('Y-m-d');
        $snapshotDate = Carbon::now('America/Sao_Paulo')->format('Y-m-d');

        $typeOpt = $this->option('type');
        $types = $typeOpt ? [$typeOpt] : array_keys(KalodataService::TYPES);

        $dry = (bool) $this->option('dry');
        $totalRows = 0;
        $falhas = [];   // type => motivo
        $vazios = [];   // types que responderam sem erro mas sem item

        foreach ($types as $type) {
            if (!isset(KalodataService::TYPES[$type])) {
                $this->warn("tipo desconhecido: $type — pulando");
                continue;
            }
            $maxPages = self::PAGES_BY_TYPE[$type] ?? 5;
            $this->info("[$type] fetching $startDate..$endDate × $maxPages páginas");

            // SEL-409: falha de um tipo não pode derrubar os outros, mas também
            // não pode sumir. Fica registrada e o comando sai com erro no fim.
            try {
                $items = $svc->fetch($type, $startDate, $endDate, $maxPages);
            } catch (\Throwable $e) {
                $falhas[$type] = $e->getMessage();
                $this->error("[$type] FALHOU: " . $e->getMessage());
                continue;
            }

            $this->line("[$type] recebidos " . count($items) . " items");
            if (empty($items)) {
                $vazios[] = $type;
            }

            if ($dry) { $totalRows += count($items); continue; }

            $rows = [];
            $now = now();
            foreach ($items as $item) {
                $item = $this->localizeMedia($type, $item, $media);
                $rows[] = [
                    'type'          => $type,
                    'snapshot_date' => $snapshotDate,
                    'window_range'  => $window,
                    'external_id'   => (string) ($svc->extractExternalId($type, $item) ?? ''),
                    'payload'       => json_encode($item, JSON_UNESCAPED_UNICODE),
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }
            if (!empty($rows)) {
                // Dedup manual por (type, snapshot_date, external_id): apaga antes de reinserir
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
            }
            $totalRows += count($rows);
        }

        return $this->encerra($totalRows, $falhas, $vazios, $dry);
    }

    /**
     * SEL-409: o desfecho do sync. Zero linha = falha barulhenta (log de erro,
     * Telegram e exit code != 0). Sucesso parcial também avisa — 3 dos 5 tipos
     * vindo vazio é sintoma de bloqueio começando.
     */
    private function encerra(int $totalRows, array $falhas, array $vazios, bool $dry): int
    {
        $problemas = [];
        foreach ($falhas as $type => $motivo) {
            $problemas[] = "$type: " . mb_substr($motivo, 0, 160);
        }
        if (!empty($vazios)) {
            $problemas[] = 'sem nenhum item: ' . implode(', ', $vazios);
        }

        if ($totalRows === 0) {
            $msg = 'kalodata:sync não trouxe NADA' . ($dry ? ' (dry-run)' : '')
                . ($problemas ? ' — ' . implode(' | ', $problemas) : '');
            $this->error("❌ $msg");
            Log::error("[SEL-409] $msg");
            $this->avisaRuan("🚨 Kalodata sem dados\n\n$msg\n\nO TikTok Shopping está servindo métrica velha pro cliente decidir o que vender.");
            return self::FAILURE;
        }

        $this->info("✅ Total inserido: $totalRows linhas em kalodata_raw" . ($dry ? ' (DRY-RUN, não gravou)' : ''));

        if (!empty($problemas)) {
            $msg = "kalodata:sync parcial ($totalRows linhas) — " . implode(' | ', $problemas);
            $this->warn("⚠️  $msg");
            Log::error("[SEL-409] $msg");
            $this->avisaRuan("⚠️ Kalodata veio pela metade\n\n$msg");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Alarme nunca pode derrubar o sync — se o Telegram cair, o log já registrou. */
    private function avisaRuan(string $mensagem): void
    {
        try {
            app(TelegramNotificationService::class)->send($mensagem);
        } catch (\Throwable $e) {
            $this->warn('aviso Telegram falhou: ' . $e->getMessage());
        }
    }

    /**
     * SEL-308 (Ruan 21/07): midia ja nasce no storage proprio — nunca gravar
     * URL assinada crua. Falha aqui nao derruba o sync (tt:media-localize cobre).
     */
    private function localizeMedia(string $type, array $item, TikTokMediaService $media): array
    {
        $map = [
            'products' => ['field' => 'image_url',   'sleep_us' => 300_000],
            'creators' => ['field' => 'avatar',      'sleep_us' => 1_200_000],
            'lives'    => ['field' => 'host_avatar', 'sleep_us' => 1_200_000],
        ];
        if (!isset($map[$type])) return $item;

        $field = $map[$type]['field'];
        $url = $item[$field] ?? null;
        if ($url && str_contains($url, 'api.seller.global/storage/')) return $item;

        $hints = $type === 'products'
            ? ['video_id' => $item['video_id'] ?? null, 'tiktok_url' => $item['tiktok_url'] ?? null]
            : ['handle' => $item['handle'] ?? null];

        try {
            $local = $media->ensureLocal($url, $hints);
            if ($local) {
                $item[$field] = $local;
                usleep($map[$type]['sleep_us']); // rate limit tikwm
            }
        } catch (\Throwable $e) {
            $this->warn("[$type] localizeMedia falhou: " . $e->getMessage());
        }

        return $item;
    }
}
