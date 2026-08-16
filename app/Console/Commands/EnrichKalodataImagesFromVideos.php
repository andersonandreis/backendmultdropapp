<?php

namespace App\Console\Commands;

use App\Services\TikTokMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * SEL-439 — foto REAL do produto Kalodata, ligada pelo ID (nunca por palavra-chave).
 *
 * ================== POR QUE ESTE CAMINHO ==================
 * Ruan, 30/07, literal: "quero a imagem real dos produtos porra, se nao vai
 * gerar videos de outro produto, tu ta doido, arruma isso, nao inventa".
 *
 * Fontes descartadas, e o motivo de cada uma (NAO refaca esses testes):
 *
 *  1. API de ranking da Kalodata (queryProductTops) — NAO tem campo de imagem.
 *     Verificado na resposta ao vivo: as 27 chaves do item nao incluem imagem.
 *
 *  2. PDP do TikTok Shop por HTTP (5 User-Agents, inclusive Googlebot,
 *     facebookexternalhit, WhatsApp, Twitterbot) — devolve shell SPA de ~5KB.
 *     O unico og:image que sai e o LOGO do TikTok, nao o produto.
 *
 *  3. PDP do TikTok Shop em Chromium real + stealth — cai em captcha:
 *     title="Security Check", "Drag the puzzle piece into place", zero dado de
 *     produto. ARMADILHA CRITICA: a pagina do captcha CARREGA imagens em
 *     https://p16-oec-*.tiktokcdn-us.com/... que sao as PECAS DO CAPTCHA.
 *     Um scraper que raspe "toda imagem p*-oec-*" grava captcha como foto do
 *     produto. Foi essa a pegadinha que quase entrou no sistema.
 *
 *  4. Pagina de produto da Kalodata (/product/<id>) — redireciona pra /login.
 *
 *  5. Busca por palavra-chave (Bing/Google/marketplace) — PROIBIDO. Foi o que
 *     o caçador de 23/07 fez e encheu o banco de foto de Mercado Livre e Shein
 *     servida como se fosse produto do TikTok Shop. E a causa raiz do
 *     "video de outro produto".
 *
 * ================== O CAMINHO QUE FUNCIONA ==================
 * POST https://www.kalodata.com/product/enrich (SEM login) devolve, por ID de
 * produto, os videos que a PROPRIA Kalodata associa aquele produto. E vinculo
 * por ID — autoritativo — nao palpite por titulo. Do video tiramos a capa real
 * (tikwm), que e filmagem do produto de verdade.
 *
 * Confirmacao independente do vinculo: o produto 1734909824886146496
 * ("GOKOCO Escova modeladora de ions negativos") mapeia pro video
 * 7667239906250345749, cujo titulo e "Escova modeladora gokoco ORIGINAL".
 *
 * Uso:
 *   php artisan tiktok:enrich-images-from-videos
 *   php artisan tiktok:enrich-images-from-videos --dry
 *   php artisan tiktok:enrich-images-from-videos --all-snapshots
 */
class EnrichKalodataImagesFromVideos extends Command
{
    protected $signature = 'tiktok:enrich-images-from-videos
                            {--dry : nao grava, so reporta}
                            {--all-snapshots : nao so o snapshot mais recente}
                            {--limit=100 : maximo de produtos por rodada}
                            {--frames : baixa o mp4 e busca o frame com o produto maior/mais nitido}
                            {--refresh-frames : roda os frames tambem em quem JA tem foto}';

    protected $description = 'SEL-439 foto real do produto via mapeamento produto->video da Kalodata (ligado por ID)';

    /** Capa de video do proprio produto: real, mas nao e foto de estudio do anuncio. */
    private const QUALITY_VIDEO_COVER = 65;

    /** Frame escolhido por detalhe central — costuma ser o close do produto. */
    private const QUALITY_VIDEO_FRAME = 70;

    public function handle(TikTokMediaService $media): int
    {
        $dry = (bool) $this->option('dry');

        $q = DB::table('kalodata_raw')->where('type', 'products');
        if (! $this->option('all-snapshots')) {
            $last = DB::table('kalodata_raw')->where('type', 'products')->max('snapshot_date');
            $q->where('snapshot_date', $last);
            $this->info("Snapshot: {$last}");
        }
        $rows = $q->orderByDesc('snapshot_date')->get();

        // Só os que NAO tem imagem utilizavel hoje.
        $alvos = [];
        foreach ($rows as $r) {
            $p = json_decode($r->payload, true);
            if (! is_array($p)) continue;
            $pid = (string) ($p['id'] ?? $r->external_id ?? '');
            if ($pid === '' || isset($alvos[$pid])) continue;
            if (! empty($p['image_url'])) continue;

            $temFoto = DB::table('tiktok_product_images')
                ->where('product_key', $pid)
                ->whereNotNull('url_local')
                ->where('url_original', '<>', '__scrape_queued__')
                ->exists();
            if ($temFoto) continue;

            $alvos[$pid] = $p['product_title'] ?? '';
        }
        $alvos = array_slice($alvos, 0, (int) $this->option('limit'), true);

        if (! $alvos) {
            $this->info('Nenhum produto sem foto. Nada a fazer.');
            return self::SUCCESS;
        }
        $this->info('Produtos sem foto: ' . count($alvos));

        // 1. Mapeamento produto -> videos (por ID, via Kalodata)
        $map = $this->fetchVideoMap(array_keys($alvos));
        if (! $map) {
            $this->error('Mapeamento produto->video vazio — Kalodata nao respondeu.');
            return self::FAILURE;
        }

        $ok = 0; $semVideo = 0; $semCapa = 0; $frameTrocou = 0; $frameExtras = 0;
        foreach ($alvos as $pid => $titulo) {
            $vids = $map[$pid] ?? [];
            if (! $vids) {
                $semVideo++;
                $this->line("  [{$pid}] " . mb_substr($titulo, 0, 45) . ' -> SEM video na Kalodata');
                continue;
            }

            $gravou = false;
            foreach (array_slice($vids, 0, 3) as $vid) {
                $info = $this->tikwm($vid);
                if (! $info || empty($info['cover'])) { continue; }

                // Corroboracao (auditoria, nao selecao): o titulo do video bate
                // com o do produto? Guardamos o resultado pra ser verificavel.
                $overlap = $this->tokenOverlap($titulo, $info['title'] ?? '');

                if ($dry) {
                    $this->line("  [{$pid}] " . mb_substr($titulo, 0, 35)
                        . " -> vid {$vid} | overlap={$overlap} | " . mb_substr($info['title'] ?? '', 0, 40));
                    $gravou = true;
                    break;
                }

                $local = $media->downloadAndStore($info['cover']);
                if (! $local) { continue; }

                DB::table('tiktok_product_images')->updateOrInsert(
                    [
                        'product_key'  => $pid,
                        'source'       => 'listing',
                        'url_original' => 'kalovid_' . $vid . '#cover',
                    ],
                    [
                        'url_local'     => $local,
                        'quality_score' => self::QUALITY_VIDEO_COVER,
                        'scrape_status' => 'done',
                        'scrape_error'  => 'kalodata:product/enrich id-keyed; overlap_titulo=' . $overlap,
                        'updated_at'    => now(),
                        'created_at'    => now(),
                    ]
                );
                $this->info("  [{$pid}] " . mb_substr($titulo, 0, 35) . " -> OK (vid {$vid}, overlap {$overlap})");
                $gravou = true;

                // Frame com o produto maior/mais nitido (Ruan: "ele e a estrela do video")
                if ($this->option('frames') && ! empty($info['play'])) {
                    $r = $this->extrairFrames($pid, $vid, $info['play'], $local);
                    $frameTrocou += $r['trocou'] ? 1 : 0;
                    $frameExtras += $r['extras'];
                    $this->line('        frames: ' . ($r['trocou'] ? 'capa trocada pelo close' : 'capa mantida')
                        . ", +{$r['extras']} na galeria");
                }
                break;
            }

            if ($gravou) { $ok++; } else { $semCapa++; $this->warn("  [{$pid}] videos sem capa recuperavel"); }
        }

        // --refresh-frames: quem JA tem foto tambem ganha a disputa capa vs close
        if ($this->option('frames') && $this->option('refresh-frames') && ! $dry) {
            $this->newLine();
            $this->info('Reavaliando capa vs close em quem ja tem foto...');
            foreach ($this->comCapaDeVideo() as $pid => $vid) {
                $info = $this->tikwm($vid);
                if (! $info || empty($info['play'])) continue;
                $capa = DB::table('tiktok_product_images')
                    ->where('product_key', $pid)->where('url_original', 'kalovid_' . $vid . '#cover')
                    ->value('url_local');
                $r = $this->extrairFrames($pid, $vid, $info['play'], $capa);
                $frameTrocou += $r['trocou'] ? 1 : 0;
                $frameExtras += $r['extras'];
                $this->line("  [{$pid}] " . ($r['trocou'] ? 'capa trocada pelo close' : 'capa mantida')
                    . ", +{$r['extras']} na galeria");
            }
        }

        $this->newLine();
        $this->info("Com foto agora: {$ok} · sem video na Kalodata: {$semVideo} · video sem capa: {$semCapa}"
            . ($dry ? ' (dry-run)' : ''));
        if ($this->option('frames')) {
            $this->info("Frames: capa trocada pelo close em {$frameTrocou} · {$frameExtras} imagens extras na galeria");
        }

        return self::SUCCESS;
    }

    /** POST /product/enrich via navegador (Cloudflare bloqueia cliente HTTP simples). */
    private function fetchVideoMap(array $ids): array
    {
        $dir    = config('services.kalodata.browser_dir', '/home/api.seller.global/browser-worker');
        $script = $dir . '/kalodata_enrich.js';
        if (! is_file($script)) {
            $this->error("worker ausente: {$script}");
            return [];
        }

        // Janela de 7 dias terminando ontem — mesma da coleta de ranking.
        $end   = now()->subDay()->toDateString();
        $start = now()->subDays(7)->toDateString();

        $payload = json_encode([
            'ids'       => array_values($ids),
            'startDate' => $start,
            'endDate'   => $end,
            'country'   => 'BR',
        ], JSON_UNESCAPED_UNICODE);

        $proc = new Process(['node', $script, $payload], $dir, [
            'PLAYWRIGHT_BROWSERS_PATH' => env('PLAYWRIGHT_BROWSERS_PATH', '/opt/ms-playwright'),
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ]);
        $proc->setTimeout(420);
        $proc->run();

        $decoded = json_decode(trim($proc->getOutput()), true);
        if (! is_array($decoded) || empty($decoded['ok'])) {
            $this->error('enrich falhou: ' . mb_substr($decoded['error'] ?? $proc->getErrorOutput(), 0, 300));
            return [];
        }
        return $decoded['map'] ?? [];
    }

    /** tikwm: video id -> capa + titulo. Free tier limita a 1 req/s. */
    private function tikwm(string $videoId): ?array
    {
        for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
            usleep(1500000); // 1.5s — respeita o limite de 1 req/s
            try {
                $r = Http::timeout(15)->asForm()->post('https://tikwm.com/api/', [
                    'url' => 'https://www.tiktok.com/@placeholder/video/' . $videoId,
                    'hd'  => 0,
                ]);
                if (! $r->successful()) continue;
                $j = $r->json();
                if (($j['code'] ?? null) === -1) { continue; } // rate limit: tenta de novo
                $d = $j['data'] ?? [];
                $cover = $d['origin_cover'] ?? ($d['cover'] ?? null);
                if (! is_string($cover) || ! str_starts_with($cover, 'http')) return null;
                return [
                    'cover' => $cover,
                    'title' => (string) ($d['title'] ?? ''),
                    'play'  => is_string($d['play'] ?? null) ? $d['play'] : null,
                ];
            } catch (\Throwable $e) {
                continue;
            }
        }
        return null;
    }

    /** product_key => video_id de quem já tem capa de vídeo gravada. */
    private function comCapaDeVideo(): array
    {
        $out = [];
        $rows = DB::table('tiktok_product_images')
            ->where('url_original', 'LIKE', 'kalovid_%#cover')
            ->whereNotNull('url_local')
            ->get(['product_key', 'url_original']);
        foreach ($rows as $r) {
            if (preg_match('/^kalovid_(\d+)#cover$/', $r->url_original, $m)) {
                $out[$r->product_key] = $m[1];
            }
        }
        return $out;
    }

    /**
     * Frames do vídeo, escolhendo aquele em que o produto aparece MAIOR e mais nítido.
     *
     * Ruan, 30/07: "aproximação do produto, destaque nele, ele é a estrela do vídeo".
     * A capa do TikTok costuma ser o rosto do criador falando — o produto fica pequeno
     * ao fundo. No meio do vídeo quase sempre tem o close do produto.
     *
     * COMO SE MEDE (e a limitação honesta disso): o servidor não tem OpenCV, numpy nem
     * PIL — só ffmpeg. Então "produto grande e nítido" é aproximado por DETALHE VISUAL
     * no centro do quadro: recorta os 60% centrais, salva em JPEG de qualidade fixa e
     * usa o TAMANHO do arquivo como nota. Imagem detalhada e em foco comprime pior e
     * gera arquivo maior; imagem lisa/desfocada gera arquivo menor. Close de produto
     * centralizado pontua alto.
     *
     * Isto NÃO reconhece o produto. Um rosto nítido no centro também pontua alto. É uma
     * melhora de probabilidade, não uma garantia — por isso a troca é conservadora:
     *
     *   - a capa atual entra na disputa medida pela MESMA régua;
     *   - o frame só substitui a capa se ganhar por margem (>=25%);
     *   - empate ou dúvida => fica a capa. Nunca troca foto boa por foto pior.
     *
     * Os frames que não viram capa entram como galeria (o modal mostra até 5), então o
     * Estúdio ganha material real do produto certo de qualquer jeito.
     *
     * @return array{trocou:bool, extras:int}
     */
    private function extrairFrames(string $productKey, string $videoId, string $playUrl, ?string $coverLocal): array
    {
        $tmpDir = storage_path('app/tmp-sel439');
        if (! is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
        $mp4 = $tmpDir . '/' . $videoId . '.mp4';

        try {
            $resp = Http::timeout(60)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($playUrl);
            if (! $resp->successful()) return ['trocou' => false, 'extras' => 0];
            file_put_contents($mp4, $resp->body());
        } catch (\Throwable $e) {
            return ['trocou' => false, 'extras' => 0];
        }
        if (! is_file($mp4) || filesize($mp4) < 20000) { @unlink($mp4); return ['trocou' => false, 'extras' => 0]; }

        $dur = (float) trim((string) shell_exec(
            'ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($mp4) . ' 2>/dev/null'
        ));
        if ($dur <= 1.5) { @unlink($mp4); return ['trocou' => false, 'extras' => 0]; }

        // 8 candidatos espalhados, evitando o começo (quase sempre o rosto falando)
        $candidatos = [];
        foreach ([0.15, 0.25, 0.35, 0.45, 0.55, 0.65, 0.75, 0.85] as $i => $pos) {
            $ts   = number_format($dur * $pos, 2, '.', '');
            $full = $tmpDir . '/' . $videoId . '_f' . $i . '.jpg';
            shell_exec('ffmpeg -y -ss ' . escapeshellarg($ts) . ' -i ' . escapeshellarg($mp4)
                . ' -frames:v 1 -q:v 3 ' . escapeshellarg($full) . ' 2>/dev/null');
            if (! is_file($full) || filesize($full) < 5000) { @unlink($full); continue; }
            $candidatos[] = ['path' => $full, 'nota' => $this->notaDetalheCentral($full), 'ts' => $ts];
        }
        @unlink($mp4);

        if (! $candidatos) return ['trocou' => false, 'extras' => 0];
        usort($candidatos, fn ($a, $b) => $b['nota'] <=> $a['nota']);

        // A capa atual disputa com a MESMA régua.
        $notaCapa = 0.0;
        if ($coverLocal) {
            $capaPath = $this->caminhoLocal($coverLocal);
            if ($capaPath && is_file($capaPath)) $notaCapa = $this->notaDetalheCentral($capaPath);
        }

        $melhor = $candidatos[0];
        $trocou = false;

        // Margem de 25%: só troca quando é claramente melhor.
        if ($notaCapa <= 0 || $melhor['nota'] >= $notaCapa * 1.25) {
            $destino = 'prodframe_' . $productKey . '_best.jpg';
            @copy($melhor['path'], storage_path('app/public/tt-media/' . $destino));
            DB::table('tiktok_product_images')->updateOrInsert(
                ['product_key' => $productKey, 'source' => 'listing', 'url_original' => 'kalovid_' . $videoId . '#bestframe'],
                [
                    'url_local'     => url('/storage/tt-media/' . $destino),
                    'quality_score' => self::QUALITY_VIDEO_FRAME,
                    'scrape_status' => 'done',
                    'scrape_error'  => sprintf('frame t=%ss detalhe=%d (capa=%d)', $melhor['ts'], $melhor['nota'], $notaCapa),
                    'updated_at'    => now(),
                    'created_at'    => now(),
                ]
            );
            $trocou = true;
        }

        // Os demais viram galeria (material real do produto pro Estúdio).
        $extras = 0;
        foreach (array_slice($candidatos, $trocou ? 1 : 0, 3) as $n => $c) {
            $destino = 'prodframe_' . $productKey . '_' . $n . '.jpg';
            @copy($c['path'], storage_path('app/public/tt-media/' . $destino));
            DB::table('tiktok_product_images')->updateOrInsert(
                ['product_key' => $productKey, 'source' => 'listing', 'url_original' => 'kalovid_' . $videoId . '#frame' . $n],
                [
                    'url_local'     => url('/storage/tt-media/' . $destino),
                    'quality_score' => self::QUALITY_VIDEO_FRAME - 5,
                    'scrape_status' => 'done',
                    'scrape_error'  => sprintf('galeria t=%ss detalhe=%d', $c['ts'], $c['nota']),
                    'updated_at'    => now(),
                    'created_at'    => now(),
                ]
            );
            $extras++;
        }

        foreach ($candidatos as $c) @unlink($c['path']);
        return ['trocou' => $trocou, 'extras' => $extras];
    }

    /**
     * Nota de detalhe visual no centro do quadro (bytes do JPEG do recorte central).
     * Maior = mais detalhe/foco ali — proxy de "o produto está grande e nítido no meio".
     */
    private function notaDetalheCentral(string $path): int
    {
        $tmp = $path . '.center.jpg';
        shell_exec('ffmpeg -y -i ' . escapeshellarg($path)
            . ' -vf "crop=iw*0.6:ih*0.6:iw*0.2:ih*0.2,scale=400:-1" -q:v 5 '
            . escapeshellarg($tmp) . ' 2>/dev/null');
        $nota = (is_file($tmp) && filesize($tmp) > 0) ? (int) filesize($tmp) : 0;
        @unlink($tmp);
        return $nota;
    }

    /** URL pública local -> caminho no disco. */
    private function caminhoLocal(string $url): ?string
    {
        if (! preg_match('#/storage/tt-media/(.+)$#', $url, $m)) return null;
        return storage_path('app/public/tt-media/' . $m[1]);
    }

    /** Quantas palavras significativas do titulo do produto aparecem no do video. */
    private function tokenOverlap(string $produto, string $video): int
    {
        $norm = function (string $s): array {
            $s = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s));
            return array_values(array_filter(preg_split('/\s+/', $s), fn ($w) => mb_strlen($w) >= 4));
        };
        $a = $norm($produto);
        $b = $norm($video);
        if (! $a || ! $b) return 0;
        return count(array_intersect(array_slice($a, 0, 8), $b));
    }
}
