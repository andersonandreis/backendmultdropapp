<?php

namespace App\Console\Commands;

use App\Services\TikTokMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * SEL-467 — foto OFICIAL do anuncio, ligada pelo ID do TikTok Shop.
 *
 * ================== O PROBLEMA ==================
 * Os produtos #1, #2 e #3 de "Produtos em alta" apareciam SEM NENHUMA foto —
 * os tres cards mais bem colocados da tela, os primeiros que o cliente ve.
 * Eles nao tem video mapeado na Kalodata, entao o caminho de SEL-439
 * (tiktok:enrich-images-from-videos, capa/frame de video) nao alcanca eles.
 *
 * ================== O CAMINHO ==================
 * A Kalodata logada expoe, keyed pelo MESMO id do TikTok Shop:
 *   POST /product/detail                        {id}  -> id + product_title
 *   GET  /product/detail/getImages?productId=<id>     -> fotos do anuncio
 *
 * Isto e melhor que a capa de video: e a foto do proprio anuncio, nao um frame
 * de alguem falando. Por isso a nota aqui e maior que a de SEL-439 (65/70).
 *
 * ================== A REGRA DURA ==================
 * Ruan, 30/07: "quero a imagem real dos produtos, se nao vai gerar videos de
 * outro produto, nao inventa."
 *
 * Este comando NUNCA casa produto por texto. A unica chave e o id. O guarda:
 * so grava se o `id` devolvido por /product/detail for IDENTICO ao id pedido.
 * Se a Kalodata nao tiver foto pra um id, ele CONTINUA SEM FOTO — bloqueado e
 * melhor que errado, porque video do produto errado e pior que video nenhum.
 *
 * O titulo devolvido e gravado em scrape_error como trilha de auditoria, pra
 * qualquer pessoa conferir depois de qual produto a foto veio. Ele NAO
 * participa da selecao.
 *
 * ================== AUTENTICACAO ==================
 * O worker NAO faz login e NAO digita codigo de verificacao. Ele so reusa a
 * sessao ja existente em browser-worker/kalodata-session.json. Se a sessao
 * expirar, o comando FALHA com aviso claro e nao escreve nada no banco —
 * relogar e decisao humana.
 *
 * Uso:
 *   php artisan tiktok:enrich-images-from-kalodata --dry
 *   php artisan tiktok:enrich-images-from-kalodata --ids=1732944449481050041
 *   php artisan tiktok:enrich-images-from-kalodata
 */
class EnrichKalodataProductPhotos extends Command
{
    protected $signature = 'tiktok:enrich-images-from-kalodata
                            {--ids= : ids separados por virgula; sem isto, pega quem esta sem foto no snapshot mais recente}
                            {--limit=20 : maximo de produtos por rodada}
                            {--max-imgs=5 : quantas fotos guardar por produto}
                            {--dry : nao grava, so reporta}';

    protected $description = 'SEL-467 foto oficial do anuncio via Kalodata logada, ligada pelo id (nunca por palavra-chave)';

    /** Foto do proprio anuncio — melhor que capa (65) e frame (70) de video. */
    private const QUALITY_PRIMEIRA = 95;
    private const QUALITY_EXTRA    = 88;

    public function handle(TikTokMediaService $media): int
    {
        $dry = (bool) $this->option('dry');

        $alvos = $this->option('ids')
            ? $this->porIdsInformados()
            : $this->semFotoNoUltimoSnapshot();

        if (! $alvos) {
            $this->info('Nenhum produto sem foto. Nada a fazer.');
            return self::SUCCESS;
        }
        $this->info('Produtos a resolver: ' . count($alvos));

        // ARMADILHA: o id do TikTok Shop tem 19 digitos. Como chave de array o
        // PHP o converte pra int, e o json_encode/JSON.parse do worker o
        // transformaria em float, perdendo precisao
        // (1732944449481050041 -> 1732944449481050000) e pedindo um produto que
        // nao existe. Por isso o id volta a ser STRING antes de sair daqui.
        $resp = $this->buscarNaKalodata(array_map('strval', array_keys($alvos)));
        if ($resp === null) {
            return self::FAILURE;
        }
        $this->info('Sessao Kalodata: ' . ($resp['usuario'] ?? '?'));

        $ok = 0; $semFoto = 0; $falhouDownload = 0;

        foreach ($resp['produtos'] ?? [] as $p) {
            $pid    = (string) ($p['id'] ?? '');
            $titulo = (string) ($p['title'] ?? '');
            $imgs   = array_values(array_filter((array) ($p['images'] ?? [])));

            // GUARDA: a resposta tem que ser do id que pedimos. Sem isso, nada grava.
            if ($pid === '' || (string) ($p['detailId'] ?? '') !== $pid) {
                $this->error("  [{$pid}] id devolvido diferente do pedido ({$p['detailId']}) — IGNORADO");
                $semFoto++;
                continue;
            }
            if (! $imgs) {
                $this->warn("  [{$pid}] " . mb_substr($titulo, 0, 45) . ' -> Kalodata nao tem foto. CONTINUA BLOQUEADO.');
                $semFoto++;
                continue;
            }

            $imgs = array_slice($imgs, 0, (int) $this->option('max-imgs'));

            if ($dry) {
                $this->line("  [{$pid}] " . mb_substr($titulo, 0, 45) . ' -> ' . count($imgs) . ' fotos');
                foreach ($imgs as $u) $this->line('        ' . $u);
                $ok++;
                continue;
            }

            $gravadas = 0;
            foreach ($imgs as $i => $url) {
                // O img.kalocdn.com devolve 403 com o Referer padrao do service
                // ('https://shop.tiktok.com/') e 200 sem Referer nenhum.
                // Verificado com curl nos dois modos antes de mexer aqui.
                $local = $media->downloadAndStore($url, [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36',
                    'Accept'     => 'image/webp,image/apng,image/*,*/*;q=0.8',
                ]);
                if (! $local) {
                    $this->warn("        download falhou: " . mb_substr($url, 0, 80));
                    continue;
                }

                DB::table('tiktok_product_images')->updateOrInsert(
                    [
                        'product_key'  => $pid,
                        'source'       => 'kalodata',
                        'url_original' => $url,
                    ],
                    [
                        'url_local'     => $local,
                        'quality_score' => $i === 0 ? self::QUALITY_PRIMEIRA : self::QUALITY_EXTRA,
                        'scrape_status' => 'done',
                        // trilha de auditoria: de qual produto veio, e por qual chave
                        'scrape_error'  => 'kalodata:/product/detail/getImages?productId=' . $pid
                            . ' | titulo=' . mb_substr($titulo, 0, 120),
                        'updated_at'    => now(),
                        'created_at'    => now(),
                    ]
                );
                $gravadas++;
            }

            if ($gravadas > 0) {
                $ok++;
                $this->info("  [{$pid}] " . mb_substr($titulo, 0, 45) . " -> OK ({$gravadas} fotos)");
            } else {
                $falhouDownload++;
                $this->error("  [{$pid}] tinha URL mas nenhum download funcionou");
            }
        }

        $this->newLine();
        $this->info("Com foto agora: {$ok} · sem foto na Kalodata: {$semFoto} · download falhou: {$falhouDownload}"
            . ($dry ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }

    /** @return array<string,string> product_key => titulo conhecido (so referencia) */
    private function porIdsInformados(): array
    {
        $out = [];
        foreach (explode(',', (string) $this->option('ids')) as $id) {
            $id = trim($id);
            if ($id !== '') $out[$id] = '';
        }
        return $out;
    }

    /**
     * Quem esta no ranking mais recente e nao tem NENHUMA foto utilizavel.
     * Mesmo criterio de SEL-439, pra os dois comandos concordarem sobre
     * "sem foto".
     *
     * @return array<string,string>
     */
    private function semFotoNoUltimoSnapshot(): array
    {
        $last = DB::table('kalodata_raw')->where('type', 'products')->max('snapshot_date');
        if (! $last) return [];
        $this->info("Snapshot: {$last}");

        $rows = DB::table('kalodata_raw')
            ->where('type', 'products')
            ->where('snapshot_date', $last)
            ->orderBy('id')
            ->get();

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

        return array_slice($alvos, 0, (int) $this->option('limit'), true);
    }

    /** Chama o worker de navegador. null = falha (nada deve ser gravado). */
    private function buscarNaKalodata(array $ids): ?array
    {
        $dir    = config('services.kalodata.browser_dir', '/home/api.seller.global/browser-worker');
        $script = $dir . '/kalodata_product_images.js';
        if (! is_file($script)) {
            $this->error("worker ausente: {$script}");
            return null;
        }

        $proc = new Process(['node', $script, json_encode(['ids' => array_values($ids)], JSON_UNESCAPED_UNICODE)], $dir, [
            'PLAYWRIGHT_BROWSERS_PATH' => env('PLAYWRIGHT_BROWSERS_PATH', '/opt/ms-playwright'),
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ]);
        $proc->setTimeout(600);
        $proc->run();

        $decoded = json_decode(trim($proc->getOutput()), true);
        if (! is_array($decoded) || empty($decoded['ok'])) {
            if (! empty($decoded['expirada'])) {
                $this->error('SESSAO KALODATA EXPIRADA — ' . ($decoded['error'] ?? ''));
                $this->error('Relogar e decisao humana. Nada foi gravado.');
                return null;
            }
            $this->error('worker falhou: ' . mb_substr((string) ($decoded['error'] ?? $proc->getErrorOutput()), 0, 400));
            return null;
        }

        return $decoded;
    }
}
