<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-258 — refactor do KalodataController.
 * SEL-319 — enriquecimento maximo: todos os campos ricos Kalodata expostos,
 *            fix views/followers/sale (strings BR "2,68 M" → float),
 *            endpoint detail produto, endpoint video-refresh, rank preservado.
 *
 * Prioridade de dados:
 *   1) tt_shop_raw (fonte primaria — dados nativos TikTok Shop BR, foto real, shop_logo real)
 *   2) kalodata_raw (fallback — dados enriquecidos Kalodata quando TT Shop esta vazio)
 *
 * Rotas mantidas identicas: /api/v1/insights/tiktok/* (backward-compat).
 * KalodataController e mantido como alias pra nao quebrar nada.
 *
 * Dedup em products(): max 1 produto por external_id normalizado (fuzzy 60 chars do titulo).
 */
class TiktokInsightsController extends Controller
{
    // -------------------------------------------------------------------------
    // products() — Produtos TT Shop first, Kalodata fallback
    // -------------------------------------------------------------------------

    public function products(Request $request)
    {
        // SEL-500 (09/08 Ruan): endpoint ignorava limit/page e sempre devolvia
        // ~150 (o volume de 1 unico snapshot_date). Agora respeita limit/page
        // de verdade e o pool de origem agrega uma JANELA de dias (nao so o
        // dia mais recente) — o scraper paid_list.js so consegue ~150
        // produtos/dia mesmo pedindo 500 (limite do lado Kalodata, nao nosso;
        // ver nota SEL-500 no fim do metodo), entao agregar dias year o unico
        // jeito real de subir volume sem inventar produto.
        $limit  = max(1, min((int) $request->get('limit', 100), 1000));
        $page   = max(1, (int) $request->get('page', 1));
        $date   = $request->get('date');
        // staleDays: produto que sumiu do scrape ha mais dias que isso cai
        // fora — resolve o "parece fixo, nao existe mais" (item 3 do briefing).
        $staleDays = max(1, min((int) $request->get('stale_days', 21), 90));
        $photoOnly = $request->boolean('photo_only', false);

        // SEL-266 Ruan 14:07: KALODATA PRIMEIRO (ranking mais preciso por GMV),
        // TT Shop complementa/enriquece foto quando bater match por titulo.

        // 1) Kalodata primeiro (ranking oficial por GMV)
        $kaDate = DB::table('kalodata_raw')
            ->where('type', 'products')
            ->when($date, fn ($q) => $q->where('snapshot_date', $date))
            ->max('snapshot_date');

        $kaRows = [];
        if ($kaDate) {
            if ($date) {
                // data explicita pedida: comportamento antigo, 1 dia so
                $rawRows = DB::table('kalodata_raw')
                    ->where('type', 'products')
                    ->where('snapshot_date', $kaDate)
                    ->orderBy('id')
                    ->limit(2000)
                    ->get();
            } else {
                // SEL-500b (09/08 Ruan cobrou): a ordem tem que bater com o
                // Kalodata REAL — o snapshot de HOJE, ordenado por `id` ASC, e
                // EXATAMENTE a ordem/ranking que o Kalodata devolve (confirmado:
                // `revenue` decresce monotonicamente id a id dentro de 1 snapshot).
                // Misturar dias antigos no mesmo sort por revenue_numeric quebrava
                // a ordem (produto de 3 dias atras com revenue velho entrava no
                // meio do ranking de hoje). Fix: HOJE primeiro, na ordem nativa do
                // Kalodata; produtos extras (vistos em dias anteriores, sumidos
                // hoje) entram DEPOIS, ordenados por revenue proprio — plainly
                // "cauda longa" pra aumentar volume sem bagunçar o ranking de hoje.
                $todayRows = DB::table('kalodata_raw')
                    ->where('type', 'products')
                    ->where('snapshot_date', $kaDate)
                    ->orderBy('id')
                    ->limit(2000)
                    ->get();

                $windowStart = \Carbon\Carbon::parse($kaDate)->subDays($staleDays)->format('Y-m-d');
                $extraPool = DB::table('kalodata_raw')
                    ->where('type', 'products')
                    ->where('snapshot_date', '>=', $windowStart)
                    ->where('snapshot_date', '<', $kaDate)
                    ->orderByDesc('snapshot_date')
                    ->orderByDesc('id')
                    ->limit(5000)
                    ->get()
                    ->unique('external_id')
                    ->values();

                $todayIds = $todayRows->pluck('external_id')->filter()->flip();
                $extraRows = $extraPool
                    ->reject(fn ($r) => $todayIds->has($r->external_id))
                    ->sortByDesc(function ($r) {
                        $p = json_decode($r->payload, true);
                        return $this->parseKaloRevenue((string) ($p['revenue'] ?? ''));
                    })
                    ->values();

                $rawRows = $todayRows->concat($extraRows)->values();
            }

            // SEL-329: Kalodata NAO devolve image_url no payload.
            // Batch fetch das capas ja scraped em tiktok_product_images por product_key.
            // Guarda TODAS as fotos por product_key (pra gerar image_gallery no output).
            $productKeys = $rawRows->pluck('external_id')->filter()->unique()->values()->all();
            $imageIndex = [];
            $galleryIndex = []; // SEL-329: multiplas imagens por product_key
            $reviewPhotoIndex = []; // SEL-355: fotos de avaliação por product_key
            if (!empty($productKeys)) {
                $tpi = DB::table('tiktok_product_images')
                    ->whereIn('product_key', $productKeys)
                    ->orderByRaw('CASE WHEN url_local IS NOT NULL AND url_local != "" THEN 0 ELSE 1 END, source = "kalodata" DESC, quality_score DESC, id DESC')
                    ->get(['product_key', 'url_local', 'url_original', 'source']);
                foreach ($tpi as $img) {
                    $url = $img->url_local ?: $img->url_original;
                    if (!$url) continue;
                    if (!isset($imageIndex[$img->product_key])) $imageIndex[$img->product_key] = $url;
                    $galleryIndex[$img->product_key] ??= [];
                    if (count($galleryIndex[$img->product_key]) < 5 && !in_array($url, $galleryIndex[$img->product_key], true)) {
                        $galleryIndex[$img->product_key][] = $url;
                    }
                    // SEL-355: separar fotos de avaliação
                    if ($img->source === 'review') {
                        $reviewPhotoIndex[$img->product_key] ??= [];
                        if (count($reviewPhotoIndex[$img->product_key]) < 8) {
                            $reviewPhotoIndex[$img->product_key][] = $url;
                        }
                    }
                }
            }

            // SEL-355: pre-carrega videos virais para match PHP-side (evita N queries)
            // Traz os top 200 por viral_score e faz keyword match no PHP
            $viralVideosAll = DB::table('tiktok_viral_videos')
                ->orderByDesc('viral_score')
                ->limit(200)
                ->get(['external_video_id', 'video_url', 'cover_url', 'play_url_hd',
                        'creator_handle', 'creator_name', 'views', 'likes', 'viral_score', 'caption'])
                ->map(fn ($v) => [
                    'video_id'     => $v->external_video_id,
                    'video_url'    => $v->video_url,
                    'cover_url'    => $v->cover_url,
                    'play_url'     => $v->play_url_hd,
                    'creator'      => $v->creator_handle,
                    'creator_name' => $v->creator_name,
                    'views'        => $v->views,
                    'likes'        => $v->likes,
                    'viral_score'  => $v->viral_score,
                    '_caption_lc'  => mb_strtolower($v->caption ?? ''), // usada só no match
                ])
                ->all();

            $kaRows = $rawRows->map(function ($r) use ($imageIndex, $galleryIndex, $reviewPhotoIndex, $viralVideosAll) {
                $payload = json_decode($r->payload, true);
                if (empty($payload['image_url']) && !empty($r->external_id) && isset($imageIndex[$r->external_id])) {
                    $payload['image_url'] = $imageIndex[$r->external_id];
                }
                $parsed = $this->parseKalodataProduct($payload);
                // SEL-329: expõe image_gallery com todas as fotos (front usa galeria no modal)
                // SEL paid: galeria skuInfo (~29, vem do parse via _images) tem prioridade; senao usa fotos localizadas
                $parsed['image_gallery'] = !empty($parsed['image_gallery']) ? $parsed['image_gallery'] : ($galleryIndex[$r->external_id] ?? []);
                // SEL-355: fotos de avaliação (source=review) — provavelmente vazio hoje
                $parsed['review_photos'] = $reviewPhotoIndex[$r->external_id] ?? [];
                // SEL-355: top_videos — match PHP-side por palavras-chave do título (evita N queries)
                $titleWords = array_filter(
                    explode(' ', mb_strtolower($parsed['title'] ?? '')),
                    fn ($w) => mb_strlen($w) > 4
                );
                $topVideos = [];
                if (!empty($titleWords)) {
                    $keyword = mb_substr(implode(' ', array_slice($titleWords, 0, 3)), 0, 40);
                    foreach ($viralVideosAll as $vv) {
                        if (mb_strpos($vv['_caption_lc'], $keyword) !== false) {
                            $out = $vv;
                            unset($out['_caption_lc']);
                            $topVideos[] = $out;
                            if (count($topVideos) >= 5) break;
                        }
                    }
                }
                $parsed['top_videos'] = $topVideos;
                return $parsed;
            })->values()->toArray();
        }

        // 2) TT Shop puxa mapa titulo→(image_url, shop_logo, shop_name) pra ENRIQUECER Kalodata
        $ttDate = DB::table('tt_shop_raw')
            ->where('type', 'product')
            ->when($date, fn ($q) => $q->where('snapshot_date', $date))
            ->max('snapshot_date');

        $ttEnrichByTitle = [];
        $ttRows = [];
        if ($ttDate) {
            $rows = DB::table('tt_shop_raw')
                ->where('type', 'product')
                ->where('snapshot_date', $ttDate)
                ->orderBy('id')
                ->limit(500)
                ->get();
            foreach ($rows as $r) {
                $parsed = $this->parseTtShopProduct(json_decode($r->payload, true));
                $ttRows[] = $parsed;
                $key = mb_strtolower(mb_substr(preg_replace('/\s+/', '', $parsed['title'] ?? ''), 0, 30));
                if ($key && !isset($ttEnrichByTitle[$key])) {
                    $ttEnrichByTitle[$key] = [
                        'image_url' => $parsed['image_url'] ?? null,
                        'shop_logo' => $this->cleanShopLogo($parsed['shop_logo'] ?? null),
                        'shop_name' => $parsed['shop_name'] ?? null,
                        'tiktok_url' => $parsed['tiktok_url'] ?? null,
                    ];
                }
            }
        }

        // 3) Enriquece Kalodata com fotos TT Shop quando bate match por titulo
        foreach ($kaRows as &$k) {
            $key = mb_strtolower(mb_substr(preg_replace('/\s+/', '', $k['title'] ?? ''), 0, 30));
            if ($key && isset($ttEnrichByTitle[$key])) {
                $enrich = $ttEnrichByTitle[$key];
                if ($this->isImageUrlStale($k['image_url']) && $enrich['image_url']) $k['image_url'] = $enrich['image_url'];
                if (empty($k['shop_logo']) && $enrich['shop_logo']) $k['shop_logo'] = $enrich['shop_logo'];
                if (empty($k['shop_name']) && $enrich['shop_name']) $k['shop_name'] = $enrich['shop_name'];
                if (empty($k['tiktok_url']) && $enrich['tiktok_url']) $k['tiktok_url'] = $enrich['tiktok_url'];
            }
        }
        unset($k);

        // 4) Merge KALODATA PRIMEIRO + TT Shop no final complementando
        $merged = array_merge($kaRows, $ttRows);

        // 5) Dedup por external_id + title fuzzy (60 chars)
        $seen     = [];
        $deduped  = [];
        foreach ($merged as $item) {
            $idKey    = (string) ($item['external_id'] ?? '');
            $titleKey = substr(mb_strtolower(preg_replace('/\s+/', '', $item['title'] ?? '')), 0, 60);
            $dedupKey = $idKey ?: $titleKey;
            if ($dedupKey && isset($seen[$dedupKey])) {
                continue;
            }
            if ($dedupKey) {
                $seen[$dedupKey] = true;
            }
            $deduped[] = $item;
        }

        // SEL (07/08, Ruan): remove produtos-lixo (sem foto E sem faturamento) —
        // ex: "Produto Smoke" (smoke test do tt_shop) que aparecia sem imagem.
        $deduped = array_values(array_filter($deduped, function ($it) {
            $hasImg = !empty($it['image_url']) || !empty($it['image_gallery']);
            $hasRev = (float) ($it['revenue_numeric'] ?? 0) > 0;
            return $hasImg || $hasRev;
        }));

        // SEL-500 (09/08 Ruan): regra dura "nunca mostrar produto sem foto real" —
        // marca has_valid_photo em cada item (foto ja local == confiavel; foto
        // externa passa por HEAD check com cache, pra nao confiar em hotlink
        // quebrado tipo ibyteimg.com). Front pode filtrar por esse campo, ou
        // pedir ?photo_only=1 pra API ja devolver so quem tem foto validada.
        foreach ($deduped as &$it) {
            $it['has_valid_photo'] = $this->hasValidPhoto($it['image_url'] ?? null, $it['image_gallery'] ?? []);
        }
        unset($it);

        if ($photoOnly) {
            $deduped = array_values(array_filter($deduped, fn ($it) => $it['has_valid_photo']));
        }

        // SEL-500b (09/08 Ruan cobrou): NAO reordenar por foto/faturamento aqui —
        // a ordem que chega neste ponto JA E a ordem real do Kalodata (snapshot de
        // hoje, id ASC = ranking oficial) seguida dos extras de dias anteriores
        // (esses sim ordenados por revenue proprio, la na busca). Reordenar de
        // novo por revenue_numeric recalculado bagunçava a posicao (produto podia
        // "pular" de lugar por causa de arredondamento/cambio USD-BRL do momento).
        // Ordem antiga por revenue_numeric ficava abaixo comentada soh de historico:
        // usort($deduped, fn ($a, $b) => ($b['revenue_numeric'] ?? 0) <=> ($a['revenue_numeric'] ?? 0));

        // Rank (sobre o pool INTEIRO, antes da paginacao — rank e posicao no ranking geral)
        foreach ($deduped as $i => &$item) {
            $item['rank'] = $i + 1;
        }
        unset($item);

        // SEL-500: paginacao de verdade. Antes o endpoint ignorava limit/page e
        // devolvia o pool inteiro sempre (~150). Agora fatia por page/limit.
        $totalPool = count($deduped);
        $offset    = ($page - 1) * $limit;
        $pageData  = array_slice($deduped, $offset, $limit);

        return response()->json([
            // SEL-329: retornar a data MAIS RECENTE dos dois (era $ttDate ?? $kaDate, causava snapshot velho)
            'snapshot_date'    => ($kaDate && $ttDate) ? max($kaDate, $ttDate) : ($kaDate ?? $ttDate),
            'tt_shop_date'     => $ttDate,
            'kalodata_date'    => $kaDate,
            // SEL-500: metadados de paginacao/pool — front usa pra "carregar mais"/paginar de verdade
            'page'             => $page,
            'limit'            => $limit,
            'total'            => $totalPool,
            'total_pages'      => (int) ceil($totalPool / max($limit, 1)),
            'stale_days'       => $staleDays,
            'photo_only'       => $photoOnly,
            'with_valid_photo' => count(array_filter($deduped, fn ($it) => $it['has_valid_photo'])),
            'count'            => count($pageData),
            'data'             => array_values($pageData),
        ]);
    }

    /**
     * SEL-500 (09/08 Ruan): valida se a foto do produto carrega de verdade.
     * Foto ja persistida no nosso storage (api.seller.global/storage) e
     * confiavel por definicao (ja foi baixada). Foto externa (hotlink
     * TikTok/ibyteimg etc — normalmente vem do enrich tt_shop) leva HEAD
     * request com timeout curto; resultado cacheado 6h pra nao bater a
     * mesma URL toda hora. Falha de rede = foto invalida (nao assume OK).
     */
    private function hasValidPhoto(?string $url, array $gallery = []): bool
    {
        $candidates = array_values(array_filter(array_merge([$url], $gallery)));
        if (empty($candidates)) return false;

        foreach ($candidates as $u) {
            if (!is_string($u) || $u === '') continue;
            if (str_contains($u, 'api.seller.global/storage/')) return true;
        }

        // nenhuma local — tenta validar a primeira externa via HEAD (cacheado)
        $first = $candidates[0];
        $cacheKey = 'sel500_photo_ok_' . md5($first);
        return (bool) Cache::remember($cacheKey, 21600, function () use ($first) {
            try {
                $resp = Http::timeout(3)->withOptions(['allow_redirects' => true])->head($first);
                return $resp->successful();
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    // -------------------------------------------------------------------------
    // productDetail() — GET /v1/insights/tiktok/products/{id}
    // SEL-319: detalhe completo + videos virais relacionados + criadores do nicho
    // -------------------------------------------------------------------------

    public function productDetail(Request $request, string $id)
    {
        // Busca no kalodata_raw por external_id
        $kaDate = DB::table('kalodata_raw')->where('type', 'products')->max('snapshot_date');
        $kaRow  = null;
        if ($kaDate) {
            $kaRow = DB::table('kalodata_raw')
                ->where('type', 'products')
                ->where('snapshot_date', $kaDate)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.id')) = ?", [$id])
                ->first();
        }

        if (!$kaRow) {
            // Fallback: busca no tt_shop_raw
            $ttDate = DB::table('tt_shop_raw')->where('type', 'product')->max('snapshot_date');
            $ttRow  = $ttDate ? DB::table('tt_shop_raw')
                ->where('type', 'product')
                ->where('snapshot_date', $ttDate)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.product_id')) = ?", [$id])
                ->first() : null;
            if (!$ttRow) {
                return response()->json(['error' => 'Produto nao encontrado.'], 404);
            }
            $product = $this->parseTtShopProduct(json_decode($ttRow->payload, true));
        } else {
            $payload = json_decode($kaRow->payload, true);
            $product = $this->parseKalodataProduct($payload);
        }

        // Videos virais relacionados (match por detected_product_title/search_term)
        $title = $product['title'] ?? '';
        $relatedVideos = [];
        if ($title) {
            $words = array_filter(explode(' ', $title), fn ($w) => mb_strlen($w) > 3);
            $searchTerm = implode(' ', array_slice($words, 0, 4));
            if ($searchTerm) {
                $relatedVideos = DB::table('tiktok_viral_videos')
                    ->where(function ($q) use ($searchTerm, $title) {
                        $q->where('caption', 'LIKE', '%' . substr($searchTerm, 0, 40) . '%')
                          ->orWhere('detected_product_title', 'LIKE', '%' . substr($title, 0, 40) . '%');
                    })
                    ->orderByDesc('viral_score')
                    ->limit(10)
                    ->get(['id', 'external_video_id', 'video_url', 'cover_url', 'play_url_hd',
                            'creator_handle', 'creator_name', 'creator_avatar_url',
                            'views', 'likes', 'viral_score', 'published_at'])
                    ->map(function ($v) {
                        return [
                            'video_id'      => $v->external_video_id,
                            'video_url'     => $v->video_url,
                            'cover_url'     => $v->cover_url,
                            'play_url'      => $v->play_url_hd,
                            'creator'       => $v->creator_handle,
                            'creator_name'  => $v->creator_name,
                            'creator_avatar'=> $v->creator_avatar_url,
                            'views'         => $v->views,
                            'likes'         => $v->likes,
                            'viral_score'   => $v->viral_score,
                            'published_at'  => $v->published_at,
                        ];
                    })
                    ->values()
                    ->toArray();
            }
        }

        // Criadores do nicho (kalodata creators, mesma main_category)
        $nicheCreators = [];
        if (!empty($payload['pri_cate_id']) || !empty($payload['sec_cate_id'])) {
            $cateId = $payload['pri_cate_id'] ?? $payload['sec_cate_id'] ?? null;
            if ($cateId) {
                $crRows = DB::table('kalodata_raw')
                    ->where('type', 'creators')
                    ->where('snapshot_date', $kaDate)
                    ->where('payload', 'LIKE', '%' . $cateId . '%')
                    ->limit(10)
                    ->get();
                foreach ($crRows as $cr) {
                    $cp = json_decode($cr->payload, true);
                    $nicheCreators[] = $this->parseKalodataCreator($cp);
                }
            }
        }
        // Fallback: top 5 criadores gerais se nao achou por categoria
        if (empty($nicheCreators)) {
            $crRows = DB::table('kalodata_raw')
                ->where('type', 'creators')
                ->where('snapshot_date', $kaDate)
                ->orderBy('id')
                ->limit(5)
                ->get();
            foreach ($crRows as $cr) {
                $nicheCreators[] = $this->parseKalodataCreator(json_decode($cr->payload, true));
            }
        }

        return response()->json([
            'snapshot_date'   => $kaDate,
            'product'         => $product,
            'related_videos'  => $relatedVideos,
            'niche_creators'  => $nicheCreators,
        ]);
    }

    // -------------------------------------------------------------------------
    // videoRefresh() — GET /v1/insights/tiktok/video-refresh/{id}
    // SEL-319: re-resolve URL fresca via tikwm e devolve (e tenta persistir local)
    // -------------------------------------------------------------------------

    public function videoRefresh(Request $request, string $id)
    {
        $row = DB::table('tiktok_viral_videos')
            ->where('external_video_id', $id)
            ->first();

        if (!$row) {
            return response()->json(['error' => 'Video nao encontrado.'], 404);
        }

        // Verifica se ja tem arquivo local
        $safeId  = preg_replace('/[^A-Za-z0-9_-]/', '', $id);
        $dir     = storage_path('app/public/tt-media');
        $mp4File = "{$dir}/viralvid_ext_{$safeId}.mp4";
        $localUrl = rtrim(config('app.url'), '/') . "/storage/tt-media/viralvid_ext_{$safeId}.mp4";

        if (is_file($mp4File) && filesize($mp4File) > 10000) {
            DB::table('tiktok_viral_videos')
                ->where('external_video_id', $id)
                ->update(['play_url_hd' => $localUrl, 'updated_at' => now()]);
            return response()->json([
                'video_id'  => $id,
                'play_url'  => $localUrl,
                'source'    => 'local',
                'cover_url' => $row->cover_url,
            ]);
        }

        // Busca URL fresca no tikwm
        $tiktokUrl = $row->video_url;
        $freshUrl  = null;
        try {
            $res = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; HubAI-SEL319/1.0)',
                    'Referer'    => 'https://www.tikwm.com/',
                ])
                ->get('https://www.tikwm.com/api/', [
                    'url' => $tiktokUrl,
                    'hd'  => 1,
                ]);
            if ($res->successful() && ($res->json('code') ?? -1) === 0) {
                $data     = $res->json('data') ?? [];
                $freshUrl = $data['hdplay'] ?? $data['play'] ?? null;
            }
        } catch (\Throwable $e) {
            Log::warning('[SEL-319 videoRefresh] tikwm error', ['id' => $id, 'err' => $e->getMessage()]);
        }

        if (!$freshUrl) {
            return response()->json([
                'video_id'   => $id,
                'play_url'   => $row->play_url_hd,
                'source'     => 'cached_remote',
                'cover_url'  => $row->cover_url,
                'refreshed'  => false,
            ]);
        }

        // Tenta persistir local (best-effort)
        $persisted = false;
        try {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $videoRes = Http::timeout(60)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; HubAI-SEL319/1.0)',
                'Referer'    => 'https://www.tikwm.com/',
            ])->get($freshUrl);
            if ($videoRes->successful() && strlen($videoRes->body()) > 10000) {
                file_put_contents($mp4File, $videoRes->body());
                $freshUrl  = $localUrl;
                $persisted = true;
            }
        } catch (\Throwable $e) {
            Log::warning('[SEL-319 videoRefresh] persist error', ['id' => $id, 'err' => $e->getMessage()]);
        }

        // Atualiza o banco com a URL fresca
        DB::table('tiktok_viral_videos')
            ->where('external_video_id', $id)
            ->update(['play_url_hd' => $freshUrl, 'updated_at' => now()]);

        return response()->json([
            'video_id'   => $id,
            'play_url'   => $freshUrl,
            'source'     => $persisted ? 'local' : 'remote_fresh',
            'cover_url'  => $row->cover_url,
            'refreshed'  => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // shops() — Lojas TT Shop first, Kalodata fallback
    // -------------------------------------------------------------------------

    public function shops(Request $request)
    {
        $limit = max(1, min((int) $request->get('limit', 50), 500));
        $staleDays = max(1, min((int) $request->get('stale_days', 21), 90));

        // SEL-266 Ruan 14:07: KALODATA PRIMEIRO em lojas (ranking mais preciso),
        // TT Shop enriquece com shop_logo real quando bate match por shop_name.

        // 1) Kalodata primeiro
        // SEL-500b (09/08 Ruan cobrou): agrega janela de dias (dia isolado tinha
        // so ~12) mas preserva a ordem nativa de HOJE (id ASC = ranking real do
        // Kalodata) e so anexa dias antigos DEPOIS, por revenue proprio — mesmo
        // padrao de products()/fetchKalodata(), pra ordem nao bagunçar.
        $kaDate = DB::table('kalodata_raw')->where('type', 'shops')->max('snapshot_date');
        $kaShops = [];
        if ($kaDate) {
            $todayRows = DB::table('kalodata_raw')
                ->where('type', 'shops')
                ->where('snapshot_date', $kaDate)
                ->orderBy('id')
                ->limit(2000)
                ->get();

            $windowStart = \Carbon\Carbon::parse($kaDate)->subDays($staleDays)->format('Y-m-d');
            $extraPool = DB::table('kalodata_raw')
                ->where('type', 'shops')
                ->where('snapshot_date', '>=', $windowStart)
                ->where('snapshot_date', '<', $kaDate)
                ->orderByDesc('snapshot_date')
                ->orderByDesc('id')
                ->limit(5000)
                ->get()
                ->unique('external_id')
                ->values();
            $todayIds = $todayRows->pluck('external_id')->filter()->flip();
            $extraRows = $extraPool
                ->reject(fn ($r) => $todayIds->has($r->external_id))
                ->sortByDesc(function ($r) {
                    $p = json_decode($r->payload, true);
                    return $this->parseKaloRevenue((string) ($p['revenue'] ?? $p['gmv'] ?? ''));
                })
                ->values();
            $rows = $todayRows->concat($extraRows)->values();
            // SEL-274 Ruan 20:06: snapshot traz a MESMA loja repetida (Emana
            // Luz/Quantum 5x no top do ranking). Dedup por nome ANTES do merge.
            $kaSeen = [];
            foreach ($rows as $r) {
                $parsed = $this->parseKalodataShop(json_decode($r->payload, true));
                $key = mb_strtolower(trim($parsed['shop_name'] ?? ''));
                if ($key === '' || isset($kaSeen[$key])) continue;
                $kaSeen[$key] = true;
                $kaShops[] = $parsed;
                if (count($kaShops) >= $limit) break;
            }
        }

        // 2) TT Shop mapa shop_name → shop_logo (enrichment)
        $ttDate = DB::table('tt_shop_raw')->where('type', 'product')->max('snapshot_date');
        $ttEnrichByName = [];
        $ttShops = [];
        if ($ttDate) {
            $rows = DB::table('tt_shop_raw')
                ->where('type', 'product')
                ->where('snapshot_date', $ttDate)
                ->orderBy('id')
                ->limit(500)
                ->get();
            $shopMap = [];
            foreach ($rows as $r) {
                $p = json_decode($r->payload, true);
                $sid = $p['seller_info']['seller_id'] ?? null;
                $sname = $p['seller_info']['shop_name'] ?? '';
                if (!$sid || isset($shopMap[$sid])) continue;
                $shopMap[$sid] = [
                    'external_id'  => $sid,
                    'shop_name'    => $sname,
                    'shop_logo'    => $this->resolveMedia($this->cleanShopLogo($p['seller_info']['shop_logo']['url_list'][0] ?? null)),
                    'source'       => 'tt_shop',
                    'revenue_label' => null,
                    'sales'        => null,
                    'unit_price'   => null,
                ];
                $nameKey = mb_strtolower(trim($sname));
                if ($nameKey && !isset($ttEnrichByName[$nameKey])) {
                    $ttEnrichByName[$nameKey] = $shopMap[$sid]['shop_logo'];
                }
            }
            $ttShops = array_values($shopMap);
        }

        // 3) Enriquece Kalodata com shop_logo TT Shop
        foreach ($kaShops as &$k) {
            $nameKey = mb_strtolower(trim($k['shop_name'] ?? ''));
            if ($nameKey && empty($k['shop_logo']) && isset($ttEnrichByName[$nameKey])) {
                $k['shop_logo'] = $this->cleanShopLogo($ttEnrichByName[$nameKey]);
            }
        }
        unset($k);

        // 4) Merge Kalodata primeiro + TT Shop complementando (dedup por shop_name)
        $seenNames = [];
        foreach ($kaShops as $k) {
            $seenNames[mb_strtolower(trim($k['shop_name'] ?? ''))] = true;
        }
        $ttShopsFiltered = array_values(array_filter($ttShops, function ($s) use ($seenNames) {
            $key = mb_strtolower(trim($s['shop_name'] ?? ''));
            return !isset($seenNames[$key]);
        }));

        $merged = array_merge($kaShops, $ttShopsFiltered);

        // Rank
        foreach ($merged as $i => &$item) {
            $item['rank'] = $i + 1;
        }
        unset($item);

        return response()->json([
            'snapshot_date' => $ttDate ?? $kaDate,
            'count'         => count($merged),
            'data'          => array_values($merged),
        ]);
    }

    // -------------------------------------------------------------------------
    // creators(), videos(), lives(), snapshot() — Kalodata apenas (TT Shop nao tem)
    // -------------------------------------------------------------------------

    public function creators(Request $request)
    {
        $limit = max(1, min((int) $request->get('limit', 50), 500));
        $page  = max(1, (int) $request->get('page', 1));
        $staleDays = max(1, min((int) $request->get('stale_days', 21), 90));
        return $this->fetchKalodata('creators', $limit, fn ($c) => $this->parseKalodataCreator($c), $page, $staleDays);
    }

    public function videos(Request $request)
    {
        $limit = max(1, min((int) $request->get('limit', 50), 500));
        $page  = max(1, (int) $request->get('page', 1));
        $staleDays = max(1, min((int) $request->get('stale_days', 21), 90));
        return $this->fetchKalodata('videos', $limit, fn ($v) => [
            // SEL-273: preserva rank original Kalodata
            'rank_kalodata'   => isset($v['rank']) ? (int) $v['rank'] : (isset($v['sort']) ? (int) $v['sort'] : null),
            'external_id'     => $v['id'] ?? null,
            'handle'          => $v['handle'] ?? '',
            'description'     => $v['description'] ?? '',
            // SEL-326: USD → BRL
            'revenue_label'   => ($rv = $this->parseKaloRevenue((string) ($v['revenue'] ?? '')) * $this->usdBrlRate()) > 0 ? $this->fmtBrlCompact($rv) : ($v['revenue'] ?? ''),
            'revenue_numeric' => round($rv, 2),
            'sales'           => $this->parseKaloNumber((string) ($v['sale'] ?? '0')),
            'duration'        => $v['duration'] ?? '',
            'views'           => $this->parseKaloNumber((string) ($v['views'] ?? '0')),
            'is_ad'           => (bool) ($v['ad'] ?? false),
            'ad_cpa'          => round((float) ($v['ad_cpa'] ?? 0) * $this->usdBrlRate(), 2),
            'gpm'             => round((float) ($v['gpm'] ?? 0) * $this->usdBrlRate(), 2),
            'is_ai_video'     => (bool) ($v['ai_video'] ?? false),
            'ad_view_ratio'   => (string) ($v['ad_view_ratio'] ?? ''),
            'content_type'    => $v['content_type'] ?? null,
            'revenue_trend'   => $v['revenue_trend'] ?? [],
            'views_trend'     => $v['views_trend'] ?? [],
            'publish_date'    => $v['publish_date'] ?? null,
            // SEL-329: fallback pra cover — Kalodata nem sempre traz
            // SEL-329 (23/07): unavatar com ?fallback= pra quando handle não tem avatar customizado.
            // Antes retornava ícone user cinza; agora usa API DiceBear (avatar SVG neutro por seed=handle).
            'cover'           => $this->resolveMedia($v['cover'] ?? null) ?? (($v['handle'] ?? '') ? $this->unavatarWithFallback($v['handle']) : null),
            'play_url'        => $v['play_url'] ?? null,
            'author_avatar'   => $this->resolveMedia($v['author_avatar'] ?? null) ?? (($v['handle'] ?? '') ? $this->unavatarWithFallback($v['handle']) : null),
            'tiktok_url'      => $v['tiktok_url'] ?? ((($v['handle'] ?? '') !== '') && !empty($v['id']) ? 'https://www.tiktok.com/@' . $v['handle'] . '/video/' . $v['id'] : null),
        ], $page, $staleDays);
    }

    public function lives(Request $request)
    {
        $limit = max(1, min((int) $request->get('limit', 30), 300));
        $page  = max(1, (int) $request->get('page', 1));
        $staleDays = max(1, min((int) $request->get('stale_days', 21), 90));
        return $this->fetchKalodata('lives', $limit, fn ($l) => [
            'handle'          => $l['handle'] ?? '',
            'title'           => $l['title'] ?? '',
            'uid'             => $l['uid'] ?? null,
            // SEL-326: USD → BRL
            'revenue_label'   => ($rv = $this->parseKaloRevenue((string) ($l['revenue'] ?? '')) * $this->usdBrlRate()) > 0 ? $this->fmtBrlCompact($rv) : ($l['revenue'] ?? ''),
            'revenue_numeric' => round($rv, 2),
            'sales'           => $this->parseKaloNumber((string) ($l['sale'] ?? '0')),
            'unit_price'      => $this->kaloUsdToBrl($l['unit_price'] ?? null),
            'duration'        => $l['duration'] ?? '',
            'views'           => $this->parseKaloNumber((string) ($l['views'] ?? '0')),
            'create_time'     => $l['create_time'] ?? null,
            'finish_time'     => $l['finish_time'] ?? null,
            'record_type'     => $l['record_type'] ?? null,
            'gpm'             => round((float) ($l['gpm'] ?? 0) * $this->usdBrlRate(), 2),
            'main_category'   => $l['main_category'] ?? null,
            // SEL-329 (23/07): unavatar com fallback DiceBear pra quando handle não tem avatar
            'host_avatar'     => $this->resolveMedia($l['host_avatar'] ?? null) ?? (($l['handle'] ?? '') ? $this->unavatarWithFallback($l['handle']) : null),
            'cover'           => $this->resolveMedia($l['cover'] ?? null) ?? (($l['handle'] ?? '') ? $this->unavatarWithFallback($l['handle']) : null),
            'tiktok_url'      => $l['tiktok_url'] ?? (($l['handle'] ?? '') ? 'https://www.tiktok.com/@' . $l['handle'] . '/live' : null),
        ], $page, $staleDays);
    }

    public function snapshot()
    {
        $kaDate = DB::table('kalodata_raw')->max('snapshot_date');
        $ttDate = DB::table('tt_shop_raw')->where('type', 'product')->max('snapshot_date');

        $counts = [];
        if ($kaDate) {
            $counts['kalodata'] = DB::table('kalodata_raw')
                ->where('snapshot_date', $kaDate)
                ->selectRaw('type, count(*) as n')
                ->groupBy('type')
                ->pluck('n', 'type');
        }
        if ($ttDate) {
            $counts['tt_shop_products'] = DB::table('tt_shop_raw')
                ->where('type', 'product')
                ->where('snapshot_date', $ttDate)
                ->count();
        }

        // SEL-319: videos virais locais
        $counts['viral_videos_total']     = DB::table('tiktok_viral_videos')->count();
        $counts['viral_videos_local_mp4'] = DB::table('tiktok_viral_videos')
            ->where('play_url_hd', 'LIKE', '%/storage/tt-media%mp4%')
            ->count();
        $counts['viral_covers_local']     = DB::table('tiktok_viral_videos')
            ->where('cover_url', 'LIKE', '%/storage/tt-media%')
            ->count();

        return response()->json([
            'kalodata_date'    => $kaDate,
            'tt_shop_date'     => $ttDate,
            'counts'           => $counts,
        ]);
    }

    // -------------------------------------------------------------------------
    // Parse helpers
    // -------------------------------------------------------------------------

    private function parseTtShopProduct(array $p): array
    {
        return [
            'external_id'    => $p['product_id'] ?? null,
            'title'          => $p['title'] ?? '',
            'image_url'      => $this->isImageUrlStale($p['image']['url_list'][0] ?? null) ? null : ($p['image']['url_list'][0] ?? null),
            'shop_logo'      => $p['seller_info']['shop_logo']['url_list'][0] ?? null,
            'shop_name'      => $p['seller_info']['shop_name'] ?? null,
            'shop_id'        => $p['seller_info']['seller_id'] ?? null,
            'price'          => $p['product_price_info']['sale_price_decimal'] ?? null,
            'origin_price'   => $p['product_price_info']['origin_price_decimal'] ?? null,
            'currency'       => 'BRL',
            'sales'          => (int) ($p['sold_info']['sold_count'] ?? 0),
            'rating'         => (float) ($p['rate_info']['score'] ?? 0),
            'review_count'   => $p['rate_info']['review_count'] ?? null,
            'tiktok_url'     => $p['seo_url']['canonical_url'] ?? null,
            'labels'         => $this->extractLabels($p['product_marketing_info']['placement_labels'] ?? []),
            'source'         => 'tt_shop',
            // campos Kalodata equivalentes (null — TT Shop nao fornece)
            'revenue_label'  => null,
            'revenue_numeric'=> null,
            'gmv'            => null,
            'creators_count' => null,
            'video_url'      => null,
            'video_id'       => null,
            'shop_url'       => null,
            // campos ricos SEL-319
            'commission_rate'          => null,
            'product_rating'           => (float) ($p['rate_info']['score'] ?? 0),
            'unit_price'               => $p['product_price_info']['sale_price_decimal'] ?? null,
            'min_real_price'           => null,
            'max_real_price'           => null,
            'shipping_fee'             => null,
            'launch_date'              => null,
            'revenue_trend'            => [],
            'video_revenue'            => null,
            'live_revenue'             => null,
            'showcase_revenue'         => null,
            'creator_conversion_ratio' => null,
            'delivery_type'            => null,
        ];
    }

    /**
     * SEL-321/D7: video de produto — payload kalodata traz URL tiktokcdn
     * assinada que expira em horas; resolver pra mp4 local quando existir.
     */
    private function resolveProductVideoUrl($videoId, ?string $rawUrl): ?string
    {
        if (!$videoId) {
            return $rawUrl;
        }
        $local = DB::table('tiktok_viral_videos')
            ->where('external_video_id', (string) $videoId)
            ->where('play_url_hd', 'like', '%/storage/tt-media%')
            ->value('play_url_hd');
        if ($local) {
            return $local;
        }
        $safeId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $videoId);
        $file   = storage_path("app/public/tt-media/kalovid_{$safeId}.mp4");
        if (is_file($file) && filesize($file) > 10000) {
            return rtrim(config('app.url'), '/') . "/storage/tt-media/kalovid_{$safeId}.mp4";
        }
        return $rawUrl;
    }

    /**
     * SEL-319: parse completo do produto Kalodata com TODOS os campos ricos.
     */
    private function parseKalodataProduct(array $p): array
    {
        // SEL-326: Kalodata exporta USD ("$26,34") — converter tudo pra BRL.
        $unitBrl = $this->kaloUsdToBrl($p['unit_price'] ?? null);
        $minBrl  = $this->kaloUsdToBrl($p['min_real_price'] ?? null);
        $maxBrl  = $this->kaloUsdToBrl($p['max_real_price'] ?? null);
        $revBrl  = $this->parseKaloRevenue((string) ($p['revenue'] ?? '')) * $this->usdBrlRate();
        $commPct = (float) str_replace(['%', ','], ['', '.'], (string) ($p['commission_rate'] ?? 0));
        // preco de tabela real (min_real_price); ticket medio como fallback
        $priceRef = ($minBrl && $minBrl > 0) ? $minBrl : $unitBrl;

        return [
            'external_id'    => $p['id'] ?? null,
            'title'          => $p['product_title'] ?? '',
            'image_url'      => $this->isImageUrlStale($p['image_url'] ?? null) ? null : ($p['image_url'] ?? null),
            // SEL-274: payload varia por scrape — tentar todas as chaves conhecidas
            'shop_logo'      => $p['shop_logo'] ?? ($p['shop_avatar'] ?? ($p['seller_avatar'] ?? null)),
            'shop_name'      => $p['shop_name'] ?? ($p['seller_name'] ?? ($p['shop'] ?? ($p['shop_title'] ?? null))),
            'shop_id'        => $p['shop_id'] ?? null,
            'price'          => $priceRef,
            'origin_price'   => null,
            'currency'       => 'BRL',
            'sales'          => $this->parseKaloNumber((string) ($p['sale'] ?? '0')),
            'rating'         => (float) ($p['product_rating'] ?? 0),
            'review_count'   => null,
            'tiktok_url'     => $p['tiktok_url'] ?? null,
            'labels'         => [],
            'source'         => 'kalodata',
            // campos extras Kalodata — basicos
            'revenue_label'  => $revBrl > 0 ? $this->fmtBrlCompact($revBrl) : ($p['revenue'] ?? ''),
            'revenue_numeric'=> round($revBrl, 2),
            'gmv'            => (float) ($p['gmv_A'] ?? 0),
            'gmv_b'          => (float) ($p['gmv_B'] ?? 0),
            'creators_count' => (int) ($p['creator_num'] ?? 0),
            'video_url'      => $this->resolveProductVideoUrl($p['video_id'] ?? null, $p['video_url'] ?? null),
            'video_id'       => $p['video_id'] ?? null,
            'shop_url'       => $p['shop_url'] ?? null,
            // SEL-319: campos ricos que antes nao eram expostos
            'commission_rate'           => $p['commission_rate'] ?? null,
            // SEL-326: quanto o afiliado ganha por venda (preco real x comissao)
            'earn_per_sale'             => ($priceRef && $commPct > 0) ? round($priceRef * $commPct / 100, 2) : null,
            'product_rating'            => (float) ($p['product_rating'] ?? 0),
            'unit_price'                => $unitBrl,
            'min_real_price'            => $minBrl,
            'max_real_price'            => $maxBrl,
            'shipping_fee'              => $this->kaloUsdToBrl($p['shipping_fee'] ?? null),
            'launch_date'               => $p['launch_date'] ?? null,
            'revenue_trend'             => $p['revenue_trend'] ?? [],
            'video_revenue'             => $this->kaloUsdToBrl($p['video_revenue'] ?? null),
            'live_revenue'              => $this->kaloUsdToBrl($p['live_revenue'] ?? null),
            'showcase_revenue'          => $this->kaloUsdToBrl($p['showcase_revenue'] ?? null),
            'creator_conversion_ratio'  => $p['creator_conversion_ratio'] ?? null,
            'delivery_type'             => $p['delivery_type'] ?? null,
            'is_full_service'           => (bool) ($p['is_full_service'] ?? false),
            // SEL-355: resumo de avaliações (best-effort, payload Kalodata nem sempre inclui)
            'reviews_summary'           => $p['review_summary'] ?? ($p['review_desc'] ?? ($p['product_desc'] ?? null)),
            // SEL paid (08/08, Ruan): detalhe profundo do Kalodata pago repassado do raw_payload
            // (populado pelo worker kalodata_paid_list.js). Front usa no modal de detalhe.
            'image_gallery'             => (!empty($p['_images']) && is_array($p['_images'])) ? array_values($p['_images']) : [],
            'promoting_creators'        => (!empty($p['_creators']) && is_array($p['_creators'])) ? array_values($p['_creators']) : [],
            'ad_videos'                 => (!empty($p['_videos']) && is_array($p['_videos'])) ? array_values($p['_videos']) : [],
            'deep_detail'               => (!empty($p['_detail']) && is_array($p['_detail'])) ? $p['_detail'] : null,
        ];
    }

    /**
     * SEL-319: parse completo do creator Kalodata com TODOS os campos ricos.
     * Fix: followers/sales/views sao strings BR como "226,1 mil" — usar parseKaloNumber.
     */
    private function parseKalodataCreator(array $c): array
    {
        // SEL-319 fix: views era "(int) $c['views']" → retornava 2 pra "2,68 M"
        $views    = $this->parseKaloNumber((string) ($c['views'] ?? '0'));
        $followers= $this->parseKaloNumber((string) ($c['followers'] ?? '0'));
        $newFollowers = $this->parseKaloNumber((string) ($c['new_followers'] ?? '0'));
        $sales    = $this->parseKaloNumber((string) ($c['sale'] ?? '0'));

        // Audiencia media = media de views_trend (array de 30 dias)
        $viewsTrend = $c['views_trend'] ?? [];
        $audienceAvg = (is_array($viewsTrend) && count($viewsTrend) > 0)
            ? (int) round(array_sum($viewsTrend) / count($viewsTrend))
            : $views;

        // SEL-326: USD → BRL
        $revBrl = $this->parseKaloRevenue((string) ($c['revenue'] ?? '')) * $this->usdBrlRate();

        return [
            // SEL-273: preserva rank original Kalodata (frontend ordena por ele)
            'rank_kalodata'   => isset($c['rank']) ? (int) $c['rank'] : (isset($c['sort']) ? (int) $c['sort'] : null),
            'handle'          => $c['handle'] ?? '',
            'nickname'        => $c['nickname'] ?? ($c['handle'] ?? ''),
            'signature'       => $c['signature'] ?? '',
            'followers'       => $followers,
            'new_followers'   => $newFollowers,
            'revenue_label'   => $revBrl > 0 ? $this->fmtBrlCompact($revBrl) : ($c['revenue'] ?? ''),
            'revenue_numeric' => round($revBrl, 2),
            'sales'           => $sales,
            'unit_price'      => $this->kaloUsdToBrl($c['unit_price'] ?? null),
            'engagement_rate' => $this->parseKaloNumber((string) ($c['video_engagement_rate'] ?? '0')),
            'debut'           => $c['creatorDebut'] ?? null,
            'views'           => $views,
            'views_avg'       => $audienceAvg,  // SEL-319 fix: antes retornava 2
            'revenue_trend'   => $c['revenue_trend'] ?? [],
            'views_trend'     => $viewsTrend,
            'main_category'   => $c['main_category'] ?? null,
            'avatar'          => $this->resolveMedia($c['avatar'] ?? null) ?? (($c['handle'] ?? '') ? $this->unavatarWithFallback($c['handle']) : null),
            'tiktok_url'      => $c['tiktok_url'] ?? (($c['handle'] ?? '') ? 'https://www.tiktok.com/@' . $c['handle'] : null),
        ];
    }

    private function parseKalodataShop(array $s): array
    {
        // SEL-326: USD → BRL
        $revBrl = $this->parseKaloRevenue((string) ($s['revenue'] ?? '')) * $this->usdBrlRate();

        return [
            'external_id'            => $s['shop_id'] ?? ($s['id'] ?? null),
            'shop_name'              => $s['shop_name'] ?? ($s['name'] ?? ($s['shop_nickname_tt'] ?? '')),
            // SEL-329 (23/07): filtra placeholder default do TikTok Shop (hash a049...) — front usa fallback categórico
            'shop_logo'              => $this->resolveMedia($this->cleanShopLogo($s['shop_logo'] ?? ($s['avatar'] ?? ($s['logo'] ?? ($s['image_url'] ?? null))))),
            'shop_handle'            => $s['shop_handle'] ?? null,
            'source'                 => 'kalodata',
            'revenue_label'          => $revBrl > 0 ? $this->fmtBrlCompact($revBrl) : ($s['revenue'] ?? ''),
            'revenue_numeric'        => round($revBrl, 2),
            'sales'                  => $this->parseKaloNumber((string) ($s['sale'] ?? '0')),
            'unit_price'             => $this->kaloUsdToBrl($s['unit_price'] ?? null),
            // SEL-319: campos ricos de receita por canal
            'revenue_trend'          => $s['revenue_trend'] ?? [],
            'video_revenue'          => $this->kaloUsdToBrl($s['video_revenue'] ?? null),
            'live_revenue'           => $this->kaloUsdToBrl($s['live_revenue'] ?? null),
            'showcase_revenue'       => $this->kaloUsdToBrl($s['showcase_revenue'] ?? null),
            'self_promotion_revenue' => $this->kaloUsdToBrl($s['self_promotion_revenue'] ?? null),
            'affiliate_revenue'      => $this->kaloUsdToBrl($s['affiliate_revenue'] ?? null),
            'shopping_mall_revenue'  => $this->kaloUsdToBrl($s['shopping_mall_revenue'] ?? null),
            'seller_type'            => $s['seller_type'] ?? null,
            'is_full_service'        => (bool) ($s['is_full_service'] ?? false),
            'region'                 => $s['region'] ?? null,
            'main_category'          => $s['main_category'] ?? null,
        ];
    }

    /**
     * SEL-381/Kalodata-media — baixa 1x pro CDN próprio (tt-media) e retorna URL local estável.
     * URLs do TikTok CDN (tiktokcdn/ibyteimg) expiram/têm hotlink protection — persistir localmente
     * evita img-proxy quebrado + placeholders. Idempotente por hash da URL (arquivo já existe → retorna direto).
     * Fallback silencioso: se download falhar, devolve URL original (front vê imagem quebrada, não erro 500).
     */
    private function resolveMedia(?string $url): ?string
    {
        if ($url === null || $url === '' || str_starts_with($url, '/')) return $url;
        // Já é URL local → retorna
        if (str_contains($url, 'api.seller.global/storage/')) return $url;
        // Unavatar/dicebear são estáveis, não precisam persistir
        if (str_contains($url, 'unavatar.io') || str_contains($url, 'dicebear')) return $url;
        try {
            $local = app(\App\Services\TikTokMediaService::class)->ensureLocal($url);
            return $local ?: $url;
        } catch (\Throwable $e) {
            return $url;
        }
    }

    /**
     * SEL-300 — detecta URL de imagem assinada expirada (TT CDN / ibyteimg).
     * Retorna true quando a URL nao pode mais ser exibida (null, vazia, ou
     * `x-expires=<epoch>` no passado). Chamado no enrichment pra permitir
     * substituir por foto viva do tt_shop_raw.
     */
    private function isImageUrlStale(?string $url): bool
    {
        if ($url === null || $url === '') return true;
        if (preg_match('/[?&]x-expires=(\d+)/i', $url, $m)) {
            return ((int) $m[1]) < time();
        }
        return false;
    }

    /**
     * SEL-329 (23/07): shop_logo placeholder padrão do TikTok Shop (loja sem logo).
     * Identificado por hash `a0499006116a47deb9f66d838557359d` — 9 lojas retornaram
     * essa mesma URL na auditoria visual. Retorna null pra que o front use fallback.
     */
    private function cleanShopLogo(?string $url): ?string
    {
        if ($url === null || $url === '') return null;
        if (str_contains($url, 'a0499006116a47deb9f66d838557359d')) return null;
        return $url;
    }

    /**
     * SEL-329 (23/07): unavatar.io/tiktok/{handle}?fallback=DiceBear.
     * Antes: quando perfil TikTok não tinha avatar customizado, unavatar retornava
     * ícone user cinza padrão. Agora: usa DiceBear (avatar SVG neutro personalizado
     * por handle) como fallback → nunca mostra ícone cinza morto.
     */
    private function unavatarWithFallback(string $handle): string
    {
        $seed = urlencode($handle);
        $fallback = urlencode("https://api.dicebear.com/7.x/personas/png?seed={$seed}&backgroundColor=b6e3f4,c0aede,d1d4f9");
        return "https://unavatar.io/tiktok/{$handle}?fallback={$fallback}";
    }

    private function extractLabels(array $placements): array
    {
        $labels = [];
        foreach ($placements as $group) {
            if (!is_array($group)) continue;
            foreach ($group as $label) {
                if (isset($label['text'])) {
                    $labels[] = $label['text'];
                }
            }
        }
        return array_values(array_unique($labels));
    }

    // -------------------------------------------------------------------------
    // Generic Kalodata fetcher (creators, videos, lives)
    // -------------------------------------------------------------------------

    private function fetchKalodata(string $type, int $limit, callable $parse, int $page = 1, int $staleDays = 21)
    {
        $lastDate = DB::table('kalodata_raw')->where('type', $type)->max('snapshot_date');
        if (!$lastDate) {
            return response()->json(['snapshot_date' => null, 'data' => []]);
        }
        // SEL-500b (09/08 Ruan cobrou): 1 unico snapshot_date so tinha ~9-12 linhas
        // (scrape raso do dia). Agrega os ultimos $staleDays dias por external_id
        // (payload mais recente de cada) pra nao ficar preso ao volume de 1 dia —
        // quem sumiu do scrape ha mais de $staleDays dias cai fora ("parece fixo,
        // nao existe mais"). MAS a ordem tem que bater com o Kalodata: dentro do
        // snapshot de HOJE a ordem nativa e id ASC (mesmo padrao verificado em
        // products()); dias antigos NAO podem se misturar no meio do ranking de
        // hoje, entao entram DEPOIS, ordenados pelo proprio revenue.
        $todayRows = DB::table('kalodata_raw')
            ->where('type', $type)
            ->where('snapshot_date', $lastDate)
            ->orderBy('id')
            ->limit(2000)
            ->get();

        $windowStart = \Carbon\Carbon::parse($lastDate)->subDays($staleDays)->format('Y-m-d');
        $extraPool = DB::table('kalodata_raw')
            ->where('type', $type)
            ->where('snapshot_date', '>=', $windowStart)
            ->where('snapshot_date', '<', $lastDate)
            ->orderByDesc('snapshot_date')
            ->orderByDesc('id')
            ->limit(5000)
            ->get()
            ->unique('external_id')
            ->values();

        $todayIds = $todayRows->pluck('external_id')->filter()->flip();
        $extraRows = $extraPool
            ->reject(fn ($r) => $todayIds->has($r->external_id))
            ->sortByDesc(function ($r) {
                $p = json_decode($r->payload, true);
                $rev = $p['revenue'] ?? $p['gmv'] ?? '';
                return $this->parseKaloRevenue((string) $rev);
            })
            ->values();

        $rows = $todayRows->concat($extraRows)->values();

        // SEL-329: Kalodata NAO devolve imagem no payload. Injeta:
        //  - products: url_local/url_original de tiktok_product_images por product_key
        //  - creators: avatar_url de ai_creators por handle
        $imageIndex = [];
        if ($type === 'products') {
            $productKeys = $rows->pluck('external_id')->filter()->unique()->values()->all();
            if (!empty($productKeys)) {
                $tpi = DB::table('tiktok_product_images')
                    ->whereIn('product_key', $productKeys)
                    ->orderByRaw('CASE WHEN url_local IS NOT NULL AND url_local != "" THEN 0 ELSE 1 END, source = "kalodata" DESC, id DESC')
                    ->get(['product_key', 'url_local', 'url_original']);
                foreach ($tpi as $img) {
                    // 1 imagem por product_key — a melhor (url_local, senao url_original)
                    if (isset($imageIndex[$img->product_key])) continue;
                    $imageIndex[$img->product_key] = $img->url_local ?: $img->url_original;
                }
            }
        }
        $avatarIndex = [];
        if ($type === 'creators') {
            $handles = $rows->map(function ($r) {
                $p = json_decode($r->payload, true);
                return $p['handle'] ?? null;
            })->filter()->unique()->values()->all();
            if (!empty($handles)) {
                $avatars = DB::table('ai_creators')
                    ->whereIn('handle', $handles)
                    ->whereNotNull('avatar_url')
                    ->where('avatar_url', '!=', '')
                    ->get(['handle', 'avatar_url']);
                foreach ($avatars as $a) {
                    $avatarIndex[$a->handle] = $a->avatar_url;
                }
            }
        }

        $parsed = $rows->map(function ($r) use ($parse, $type, $imageIndex, $avatarIndex) {
            $payload = json_decode($r->payload, true);
            // SEL-329: injeta capa antes do parse
            if ($type === 'products' && empty($payload['image_url']) && !empty($r->external_id) && isset($imageIndex[$r->external_id])) {
                $payload['image_url'] = $imageIndex[$r->external_id];
            }
            if ($type === 'creators' && empty($payload['avatar']) && !empty($payload['handle']) && isset($avatarIndex[$payload['handle']])) {
                $payload['avatar'] = $avatarIndex[$payload['handle']];
            }
            $item = $parse($payload);
            // preserva revenue numerico pro sort
            $rawRev = $payload['revenue'] ?? $payload['gmv'] ?? '';
            $item['_sort_revenue'] = $this->parseKaloRevenue((string) $rawRev);
            return $item;
        })->values();

        // Dedup por handle/uid — evita mostrar 2 vezes o mesmo criador quando snapshot foi importado varias vezes
        $seen = [];
        $dedup = $parsed->filter(function ($it) use (&$seen) {
            $k = $it['handle'] ?? $it['uid'] ?? $it['external_id'] ?? spl_object_hash((object) $it);
            if (isset($seen[$k])) return false;
            $seen[$k] = true;
            return true;
        })->values();

        // SEL-500b: NAO reordenar por _sort_revenue aqui — a ordem que chega ja e
        // a correta (hoje em ordem nativa Kalodata + extras de dias antigos
        // ordenados por revenue proprio, feito la na busca). So respeita
        // rank_kalodata explicito quando o payload tiver (nenhum tipo atual tem,
        // mas mantido pra nao quebrar se algum dia vier preenchido).
        $withRank    = $dedup->filter(fn ($it) => !empty($it['rank_kalodata']))->sortBy('rank_kalodata')->values();
        $withoutRank = $dedup->filter(fn ($it) => empty($it['rank_kalodata']))->values();
        $orderedAll  = $withRank->merge($withoutRank)->values();

        // SEL-500: rank sobre o pool inteiro (antes de paginar)
        $orderedAll = $orderedAll->map(function ($item, $idx) {
            $item['rank'] = $item['rank_kalodata'] ?? ($idx + 1);
            unset($item['_sort_revenue']);
            return $item;
        });

        $totalPool = $orderedAll->count();
        $page      = max(1, $page);
        $offset    = ($page - 1) * $limit;
        $data      = $orderedAll->slice($offset, $limit)->values();

        return response()->json([
            'snapshot_date' => $lastDate,
            'page'          => $page,
            'limit'         => $limit,
            'total'         => $totalPool,
            'total_pages'   => (int) ceil($totalPool / max($limit, 1)),
            'stale_days'    => $staleDays,
            'count'         => $data->count(),
            'data'          => $data,
        ]);
    }

    /**
     * SEL-273 helper: parseia "$399,58 mil" / "$1,2 M" / "$3.500" / "3500" pra float.
     * Kalodata usa virgula decimal (BR) e sufixos "mil"/"M"/"k"/"K".
     */
    private function parseKaloRevenue(string $label): float
    {
        if ($label === '') return 0.0;
        $s = str_replace(['$', ' '], '', $label);
        if (preg_match('/([\d,\.]+)\s*(mil|M|k|K|m)?/u', $s, $m)) {
            $num = str_replace(['.', ','], ['', '.'], $m[1]);
            $n = (float) $num;
            $suf = strtolower($m[2] ?? '');
            if ($suf === 'mil' || $suf === 'k') $n *= 1000;
            elseif ($suf === 'm') $n *= 1_000_000;
            return $n;
        }
        return 0.0;
    }

    /**
     * SEL-319: parseia numero BR como "226,1 mil" / "2,68 M" / "10.073.761" pra int.
     * Identico ao parseKaloRevenue mas retorna int (para followers, views, sales).
     */
    private function parseKaloNumber(string $label): int
    {
        return (int) $this->parseKaloRevenue($label);
    }

    /**
     * SEL-326: Kalodata exporta valores em USD ("$26,34" com locale pt-BR).
     * Cotacao USD→BRL cacheada 6h via AwesomeAPI; fallback .env KALODATA_USD_BRL.
     */
    private function usdBrlRate(): float
    {
        return (float) Cache::remember('kalodata_usd_brl_rate', 21600, function () {
            try {
                $bid = (float) (Http::timeout(6)->get('https://economia.awesomeapi.com.br/json/last/USD-BRL')->json()['USDBRL']['bid'] ?? 0);
                if ($bid > 3 && $bid < 10) {
                    return round($bid, 4);
                }
            } catch (\Throwable $e) {
                Log::warning('SEL-326 usdBrlRate fallback: ' . $e->getMessage());
            }
            return (float) env('KALODATA_USD_BRL', 5.40);
        });
    }

    /** SEL-326: "$26,34" | 28.23 → valor BRL arredondado (null se nao parseavel). */
    private function kaloUsdToBrl($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = is_numeric($v) ? (float) $v : $this->parseKaloRevenue((string) $v);
        if (!is_finite($n) || $n < 0) {
            return null;
        }
        return round($n * $this->usdBrlRate(), 2);
    }

    /** SEL-326: 715300.0 → "R$ 715,3 mil" (label compacto pt-BR). */
    private function fmtBrlCompact(float $n): string
    {
        if ($n >= 1_000_000) {
            return 'R$ ' . number_format($n / 1_000_000, 2, ',', '.') . ' M';
        }
        if ($n >= 1_000) {
            return 'R$ ' . number_format($n / 1_000, 1, ',', '.') . ' mil';
        }
        return 'R$ ' . number_format($n, 2, ',', '.');
    }

    // -------------------------------------------------------------------------
    // SEL-336: GET /api/v1/insights/tiktok/videos/{id}/analysis
    // Retorna transcricao + insight estruturado de um video viral Kalodata.
    // -------------------------------------------------------------------------
    public function videoAnalysis(\Illuminate\Http\Request $request, string $id)
    {
        $country = strtoupper($request->get('country', 'BR'));
        $row = DB::table('video_analysis')
            ->where('kalodata_video_id', $id)
            ->where('country', $country)
            ->first();

        if (!$row) {
            return response()->json([
                'video_id' => $id,
                'country'  => $country,
                'pending'  => true,
                'message'  => 'Analise ainda nao disponivel. Sera processada na proxima rodada diaria (07:00 BRT).',
                'data'     => null,
            ]);
        }

        return response()->json([
            'video_id'    => $id,
            'country'     => $country,
            'pending'     => false,
            'analyzed_at' => $row->analyzed_at,
            'data'        => [
                'transcript'       => $row->transcript,
                'hook_0_3s'        => $row->hook_0_3s,
                'problem'          => $row->problem,
                'solution'         => $row->solution,
                'cta'              => $row->cta,
                'vibe'             => $row->vibe,
                'duration_sec'     => $row->duration_sec,
                'video_url_cached' => $row->video_url_cached,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // SEL-354: GET /api/v1/insights/tiktok/brands?country=BR|US&limit=100
    // Marcas (brands) do Kalodata -- le da tabela dedicada kalodata_brands (Fase 2).
    // -------------------------------------------------------------------------
    public function brands(\Illuminate\Http\Request $request)
    {
        $limit     = max(1, min((int) $request->get('limit', 100), 500));
        $country   = strtoupper($request->get('country', 'BR'));
        $staleDays = max(1, min((int) $request->get('stale_days', 21), 90));

        $lastDate = DB::table('kalodata_brands')
            ->where('country', $country)
            ->max('snapshot_date');

        if (!$lastDate) {
            // Fallback pra tabela raw (compat)
            return $this->brandsFallbackFromCreators($country, $limit);
        }

        // SEL-500b (09/08 Ruan cobrou): 1 snapshot_date so tinha ~10 marcas. Agrega
        // janela de dias por brand_id, mas preserva a ordem NATIVA de hoje (mesmo
        // orderByRaw de sempre) e so anexa dias antigos DEPOIS, ordenados por
        // revenue proprio — nao deixa dia velho se misturar no ranking de hoje.
        $todayRows = DB::table('kalodata_brands')
            ->where('country', $country)
            ->where('snapshot_date', $lastDate)
            ->orderByRaw("CAST(REPLACE(REPLACE(REPLACE(revenue, '$', ''), ' mil', '000'), ' M', '000000') AS DECIMAL(20,2)) DESC")
            ->limit(2000)
            ->get();

        $windowStart = \Carbon\Carbon::parse($lastDate)->subDays($staleDays)->format('Y-m-d');
        $extraPool = DB::table('kalodata_brands')
            ->where('country', $country)
            ->where('snapshot_date', '>=', $windowStart)
            ->where('snapshot_date', '<', $lastDate)
            ->orderByDesc('snapshot_date')
            ->orderByDesc('id')
            ->limit(5000)
            ->get()
            ->unique('brand_id')
            ->values();
        $todayIds = $todayRows->pluck('brand_id')->filter()->flip();
        $extraRows = $extraPool
            ->reject(fn ($r) => $todayIds->has($r->brand_id))
            ->sortByDesc(fn ($r) => $this->parseKaloRevenue((string) ($r->revenue ?? '')))
            ->values();

        $rows = $todayRows->concat($extraRows)->values()->take($limit);

        $data = $rows->map(function ($r, $idx) use ($country) {
            $p = json_decode($r->payload, true) ?: [];
            $gmv = $this->parseKaloRevenue((string) ($r->revenue ?? ''));
            $gmvBrl = $this->kaloUsdToBrl($gmv);
            return [
                'rank'                 => $idx + 1,
                'brand_name'           => $r->brand_name ?? ($p['brand_name'] ?? ''),
                'logo'                 => $r->brand_logo ?? ($p['brand_logo'] ?? null),
                'gmv_monthly'          => $gmvBrl ?? $gmv,
                'gmv_label'            => $gmvBrl !== null ? $this->fmtBrlCompact($gmvBrl) : ($r->revenue ?? ''),
                'gmv_label_usd'        => $r->revenue ?? '',
                'revenue_growth_rate'  => is_numeric($r->revenue_growth_rate) ? (float) $r->revenue_growth_rate : null,
                'market_share'         => is_numeric($r->market_share) ? (float) $r->market_share : null,
                'ad_spend_label'       => $r->ad_spend ?? '',
                'avg_unit_price_label' => $r->avg_unit_price ?? '',
                'item_sold'            => $r->item_sold ?? '',
                'creators_count'       => (int) ($r->creator_count ?? 0),
                'videos_count'         => $r->video_count ?? '',
                'main_category'        => $r->main_category ?? '',
                'revenue_trend'        => $p['revenue_trend'] ?? [],
                'external_id'          => $r->brand_id,
                'country'              => $country,
            ];
        })->values();

        return response()->json([
            'snapshot_date' => $lastDate,
            'country'       => $country,
            'source'        => 'kalodata_brands',
            'count'         => $data->count(),
            'data'          => $data,
        ]);
    }

    private function brandsFallbackFromCreators(string $country, int $limit)
    {
        $lastCreatorDate = DB::table('kalodata_raw')
            ->where('type', 'creators')
            ->max('snapshot_date');

        if (!$lastCreatorDate) {
            return response()->json([
                'snapshot_date' => null, 'country' => $country,
                'pending_sync'  => true,
                'message'       => 'Dados de marcas serao disponibilizados na proxima sincronizacao.',
                'count' => 0, 'data' => [],
            ]);
        }

        $rows = DB::table('kalodata_raw')
            ->where('type', 'creators')
            ->where('snapshot_date', $lastCreatorDate)
            ->orderBy('id')
            ->limit(500)
            ->get(['payload']);

        $brandMap = [];
        foreach ($rows as $r) {
            $p = json_decode($r->payload, true);
            $brand = $p['brand_name'] ?? $p['brand'] ?? null;
            if (!$brand) continue;
            if (!isset($brandMap[$brand])) {
                $brandMap[$brand] = ['brand_name' => $brand, 'logo' => $p['avatar'] ?? null, 'gmv_total' => 0.0, 'creators_count' => 0];
            }
            $brandMap[$brand]['gmv_total']      += $this->parseKaloRevenue((string) ($p['revenue'] ?? ''));
            $brandMap[$brand]['creators_count'] += 1;
        }

        $sorted = collect(array_values($brandMap))
            ->sortByDesc('gmv_total')
            ->values()
            ->take($limit)
            ->map(function ($b, $idx) use ($country) {
                $b['rank']        = $idx + 1;
                $b['gmv_monthly'] = round($b['gmv_total']);
                $b['gmv_label']   = 'R$ ' . number_format($b['gmv_total'], 2, ',', '.');
                $b['country']     = $country;
                unset($b['gmv_total']);
                return $b;
            });

        return response()->json([
            'snapshot_date' => $lastCreatorDate, 'country' => $country,
            'source' => 'inferred_from_creators',
            'count'  => $sorted->count(), 'data' => $sorted->values(),
        ]);
    }

    // -------------------------------------------------------------------------
    // SEL-354: GET /api/v1/insights/tiktok/ads?country=BR|US&limit=100
    // ADS do Kalodata -- le da tabela dedicada kalodata_ads (Fase 2).
    // -------------------------------------------------------------------------
    public function ads(\Illuminate\Http\Request $request)
    {
        $limit     = max(1, min((int) $request->get('limit', 100), 500));
        $country   = strtoupper($request->get('country', 'BR'));
        $staleDays = max(1, min((int) $request->get('stale_days', 21), 90));

        $lastDate = DB::table('kalodata_ads')
            ->where('country', $country)
            ->max('snapshot_date');

        if (!$lastDate) {
            return $this->adsFallbackFromVideos($country, $limit);
        }

        // SEL-500b (09/08 Ruan cobrou): 1 snapshot_date so tinha ~9 ads. Agrega
        // janela de dias por video_id, mas preserva ordem NATIVA de hoje e anexa
        // dias antigos DEPOIS ordenados por revenue proprio.
        $todayRows = DB::table('kalodata_ads')
            ->where('country', $country)
            ->where('snapshot_date', $lastDate)
            ->orderByRaw("CAST(REPLACE(REPLACE(REPLACE(revenue_str, '$', ''), ' mil', '000'), ' M', '000000') AS DECIMAL(20,2)) DESC")
            ->limit(2000)
            ->get();

        $windowStart = \Carbon\Carbon::parse($lastDate)->subDays($staleDays)->format('Y-m-d');
        $extraPool = DB::table('kalodata_ads')
            ->where('country', $country)
            ->where('snapshot_date', '>=', $windowStart)
            ->where('snapshot_date', '<', $lastDate)
            ->orderByDesc('snapshot_date')
            ->orderByDesc('id')
            ->limit(5000)
            ->get()
            ->unique('video_id')
            ->values();
        $todayIds = $todayRows->pluck('video_id')->filter()->flip();
        $extraRows = $extraPool
            ->reject(fn ($r) => $todayIds->has($r->video_id))
            ->sortByDesc(fn ($r) => $this->parseKaloRevenue((string) ($r->revenue_str ?? '')))
            ->values();

        $rows = $todayRows->concat($extraRows)->values()->take($limit);

        // Enriquecer com avatar/cover/video_url a partir de kalodata_raw type=videos
        $videoIds = $rows->pluck('video_id')->filter()->all();
        $videoIndex = [];
        if (!empty($videoIds)) {
            $vRows = DB::table('kalodata_raw')
                ->where('type', 'videos')
                ->whereIn('external_id', $videoIds)
                ->get(['external_id', 'payload']);
            foreach ($vRows as $v) {
                $vp = json_decode($v->payload, true) ?: [];
                $videoIndex[$v->external_id] = [
                    'avatar'    => $vp['avatar'] ?? null,
                    'cover'     => $vp['cover'] ?? $vp['image_url'] ?? null,
                    'video_url' => $vp['video_url'] ?? $vp['url'] ?? null,
                    'name'      => $vp['name'] ?? $vp['nickname'] ?? null,
                    'title'     => $vp['title'] ?? $vp['description'] ?? null,
                ];
            }
        }

        $data = $rows->map(function ($r, $idx) use ($country, $videoIndex) {
            $p = json_decode($r->payload, true) ?: [];
            $enrich = $videoIndex[$r->video_id] ?? [];
            $gmvUsd = $this->parseKaloRevenue((string) ($r->revenue_str ?? ''));
            $gmvBrl = $this->kaloUsdToBrl($gmvUsd);
            $views = $this->parseKaloNumber((string) ($r->views_str ?? ''));
            return [
                'rank'           => $idx + 1,
                'creator_handle' => $r->creator_handle ?? '',
                'creator_name'   => $enrich['name'] ?? ($p['name'] ?? ''),
                'kalodata_rank'  => $r->kalodata_rank ?? null,
                'avatar'         => $r->avatar ?? $enrich['avatar'] ?? ($r->creator_handle ? $this->unavatarWithFallback($r->creator_handle) : null),
                'video_url'      => $enrich['video_url'] ?? null,
                'cover'          => $enrich['cover'] ?? ($r->creator_handle ? $this->unavatarWithFallback($r->creator_handle) : null),
                'title'          => $enrich['title'] ?? ($p['description'] ?? $p['title'] ?? ''),
                'gpm'            => is_numeric($r->gpm) ? (float) $r->gpm : null,
                'roas'           => is_numeric($r->ad_roas) ? (float) $r->ad_roas : null,
                'ad_cpa'         => is_numeric($r->ad_cpa) ? (float) $r->ad_cpa : null,
                'ad_view_ratio'  => is_numeric($r->ad_view_ratio) ? (float) $r->ad_view_ratio : null,
                'views'          => $views,
                'views_label'    => $r->views_str ?? '',
                'sales'          => (int) ($r->sale ?? 0),
                'gmv'            => $gmvBrl ?? $gmvUsd,
                'gmv_label_usd'  => $r->revenue_str ?? '',
                'is_ad'          => (int) ($r->ad ?? 0) === 1,
                'ai_video'       => (int) ($r->ai_video ?? 0) === 1,
                'publish_date'   => $r->publish_date ?? null,
                'external_id'    => $r->video_id,
                'country'        => $country,
            ];
        })->values();

        return response()->json([
            'snapshot_date' => $lastDate, 'country' => $country,
            'source'        => 'kalodata_ads',
            'count'         => $data->count(),
            'data'          => $data,
        ]);
    }

    private function adsFallbackFromVideos(string $country, int $limit)
    {
        $lastVideoDate = DB::table('kalodata_raw')
            ->where('type', 'videos')
            ->max('snapshot_date');

        if (!$lastVideoDate) {
            return response()->json([
                'snapshot_date' => null, 'country' => $country,
                'pending_sync'  => true,
                'message'       => 'Dados de ADS serao disponibilizados na proxima sincronizacao.',
                'count' => 0, 'data' => [],
            ]);
        }

        $rows = DB::table('kalodata_raw')
            ->where('type', 'videos')
            ->where('snapshot_date', $lastVideoDate)
            ->orderBy('id')
            ->limit(500)
            ->get(['external_id', 'payload']);

        $ads = $rows->filter(function ($r) {
            $p = json_decode($r->payload, true);
            return !empty($p['is_ad']) || !empty($p['gpm']) || !empty($p['roas']);
        });

        if ($ads->isEmpty()) {
            $ads = $rows->sortByDesc(function ($r) {
                $p = json_decode($r->payload, true);
                return $this->parseKaloNumber((string) ($p['sold_cnt'] ?? $p['sales'] ?? '0'));
            })->take($limit);
        }

        $data = $ads->take($limit)->values()->map(function ($r, $idx) use ($country) {
            $p = json_decode($r->payload, true);
            return [
                'rank'           => $idx + 1,
                'creator_handle' => $p['handle'] ?? $p['author'] ?? '',
                'creator_name'   => $p['name'] ?? $p['nickname'] ?? '',
                'avatar'         => $p['avatar'] ?? null,
                'video_url'      => $p['video_url'] ?? $p['url'] ?? null,
                'cover'          => $p['cover'] ?? null,
                'title'          => $p['title'] ?? '',
                'gpm'            => $p['gpm'] ?? null,
                'roas'           => $p['roas'] ?? null,
                'views'          => $this->parseKaloNumber((string) ($p['play_cnt'] ?? '0')),
                'sales'          => $this->parseKaloNumber((string) ($p['sold_cnt'] ?? '0')),
                'gmv'            => $this->parseKaloRevenue((string) ($p['revenue'] ?? $p['gmv'] ?? '')),
                'is_ad'          => !empty($p['is_ad']),
                'external_id'    => $r->external_id,
                'country'        => $country,
            ];
        });

        return response()->json([
            'snapshot_date' => $lastVideoDate, 'country' => $country,
            'source' => 'inferred_from_videos',
            'count'  => $data->count(), 'data' => $data->values(),
        ]);
    }

    // -------------------------------------------------------------------------
    // SEL-354: GET /api/v1/insights/tiktok/lives-ranking?country=BR|US&limit=100
    // Ranking de lives por GMV -- le da tabela dedicada kalodata_lives_ranking (Fase 2).
    // -------------------------------------------------------------------------
    public function livesRanking(\Illuminate\Http\Request $request)
    {
        $limit     = max(1, min((int) $request->get('limit', 100), 500));
        $country   = strtoupper($request->get('country', 'BR'));
        $staleDays = max(1, min((int) $request->get('stale_days', 21), 90));

        $lastDate = DB::table('kalodata_lives_ranking')
            ->where('country', $country)
            ->max('snapshot_date');

        if (!$lastDate) {
            // Fallback: reusa lives()
            $request->merge(['limit' => $limit, 'country' => $country]);
            return $this->lives($request);
        }

        // SEL-500b (09/08 Ruan cobrou): 1 snapshot_date so tinha ~10 lives. Agrega
        // janela de dias por live_id, mas preserva ordem NATIVA de hoje e anexa
        // dias antigos DEPOIS ordenados por receita propria.
        $todayRows = DB::table('kalodata_lives_ranking')
            ->where('country', $country)
            ->where('snapshot_date', $lastDate)
            ->orderByRaw("CAST(REPLACE(REPLACE(REPLACE(revenue, '$', ''), ' mil', '000'), ' M', '000000') AS DECIMAL(20,2)) DESC")
            ->limit(2000)
            ->get();

        $windowStart = \Carbon\Carbon::parse($lastDate)->subDays($staleDays)->format('Y-m-d');
        $extraPool = DB::table('kalodata_lives_ranking')
            ->where('country', $country)
            ->where('snapshot_date', '>=', $windowStart)
            ->where('snapshot_date', '<', $lastDate)
            ->orderByDesc('snapshot_date')
            ->orderByDesc('id')
            ->limit(5000)
            ->get()
            ->unique('live_id')
            ->values();
        $todayIds = $todayRows->pluck('live_id')->filter()->flip();
        $extraRows = $extraPool
            ->reject(fn ($r) => $todayIds->has($r->live_id))
            ->sortByDesc(fn ($r) => $this->parseKaloRevenue((string) ($r->revenue ?? '')))
            ->values();

        $rows = $todayRows->concat($extraRows)->values()->take($limit);

        // Enriquece com avatar (procura creator no kalodata_raw type=creators)
        $handles = $rows->pluck('creator_handle')->filter()->unique()->all();
        $avatarIdx = [];
        if (!empty($handles)) {
            $cRows = DB::table('kalodata_raw')
                ->where('type', 'creators')
                ->whereIn('external_id', $handles)
                ->get(['external_id', 'payload']);
            foreach ($cRows as $c) {
                $cp = json_decode($c->payload, true) ?: [];
                $avatarIdx[$c->external_id] = $cp['avatar'] ?? null;
            }
        }

        $data = $rows->map(function ($r, $idx) use ($country, $avatarIdx) {
            $p = json_decode($r->payload, true) ?: [];
            $gmvUsd = $this->parseKaloRevenue((string) ($r->revenue ?? ''));
            $gmvBrl = $this->kaloUsdToBrl($gmvUsd);
            return [
                'rank'           => $idx + 1,
                'live_id'        => $r->live_id,
                'creator_handle' => $r->creator_handle ?? '',
                'kalodata_rank'  => $r->kalodata_rank ?? null,
                'avatar'         => $r->avatar ?? $avatarIdx[$r->creator_handle] ?? null,
                'title'          => $r->title ?? '',
                'main_category'  => $r->main_category ?? '',
                'duration'       => $r->duration ?? '',
                'create_time'    => $r->create_time ?? null,
                'finish_time'    => $r->finish_time ?? null,
                'gmv'            => $gmvBrl ?? $gmvUsd,
                'gmv_label_usd'  => $r->revenue ?? '',
                'sales'          => (int) ($r->sale ?? 0),
                'views'          => (int) ($r->views ?? 0),
                'gpm'            => is_numeric($r->gpm) ? (float) $r->gpm : null,
                'unit_price'     => is_numeric($r->unit_price) ? (float) $r->unit_price : null,
                'external_id'    => $r->live_id,
                'country'        => $country,
            ];
        })->values();

        return response()->json([
            'snapshot_date' => $lastDate, 'country' => $country,
            'source'        => 'kalodata_lives_ranking',
            'count'         => $data->count(),
            'data'          => $data,
        ]);
    }


    // -------------------------------------------------------------------------
    // SEL-356 P0: GET /api/v1/insights/tiktok/categories?country=BR|US
    // Ranking de categorias agregado a partir de kalodata_brands (tem texto PT-BR).
    // -------------------------------------------------------------------------
    public function categories(\Illuminate\Http\Request $request)
    {
        $country   = strtoupper($request->get('country', 'BR'));
        $limit     = max(1, min((int) $request->get('limit', 30), 200));
        $staleDays = max(1, min((int) $request->get('stale_days', 21), 90));

        $lastDate = DB::table('kalodata_brands')
            ->where('country', $country)
            ->max('snapshot_date');

        if (!$lastDate) {
            return response()->json([
                'snapshot_date' => null, 'country' => $country,
                'pending_sync'  => true,
                'message'       => 'Dados de categorias serao disponibilizados na proxima sincronizacao.',
                'count' => 0, 'data' => [],
            ]);
        }

        // SEL-500 (09/08 Ruan): 1 snapshot_date so tinha ~10 marcas -> so 4
        // categorias apareciam. Agrega janela de dias por brand_id (dedup) antes
        // de agregar por categoria.
        $windowStart = \Carbon\Carbon::parse($lastDate)->subDays($staleDays)->format('Y-m-d');
        $rows = DB::table('kalodata_brands')
            ->where('country', $country)
            ->where('snapshot_date', '>=', $windowStart)
            ->whereNotNull('main_category')
            ->where('main_category', '!=', '')
            ->orderByDesc('snapshot_date')
            ->orderByDesc('id')
            ->get(['brand_id', 'main_category', 'revenue', 'item_sold', 'creator_count', 'brand_name', 'brand_logo'])
            ->unique('brand_id')
            ->values();

        $catMap = [];
        foreach ($rows as $r) {
            $cat = trim($r->main_category);
            if (!$cat) continue;
            if (!isset($catMap[$cat])) {
                $catMap[$cat] = [
                    'category'       => $cat,
                    'gmv_usd'        => 0.0,
                    'brands_count'   => 0,
                    'brands'         => [],
                    'item_sold'      => 0,
                    'creators_total' => 0,
                ];
            }
            $catMap[$cat]['gmv_usd']        += $this->parseKaloRevenue((string) ($r->revenue ?? ''));
            $catMap[$cat]['brands_count']   += 1;
            $catMap[$cat]['item_sold']      += $this->parseKaloNumber((string) ($r->item_sold ?? '0'));
            $catMap[$cat]['creators_total'] += $this->parseKaloNumber((string) ($r->creator_count ?? '0'));
            if (count($catMap[$cat]['brands']) < 3 && $r->brand_name) {
                $catMap[$cat]['brands'][] = [
                    'name' => $r->brand_name,
                    'logo' => $r->brand_logo ?? null,
                ];
            }
        }

        $rate = $this->usdBrlRate();
        $data = collect(array_values($catMap))
            ->sortByDesc('gmv_usd')
            ->values()
            ->take($limit)
            ->map(function ($cat, $idx) use ($rate) {
                $gmvBrl = $cat['gmv_usd'] * $rate;
                return [
                    'rank'           => $idx + 1,
                    'category'       => $cat['category'],
                    'gmv'            => round($gmvBrl, 2),
                    'gmv_label'      => $this->fmtBrlCompact($gmvBrl),
                    'brands_count'   => $cat['brands_count'],
                    'brands_sample'  => $cat['brands'],
                    'item_sold'      => $cat['item_sold'],
                    'creators_total' => $cat['creators_total'],
                ];
            });

        return response()->json([
            'snapshot_date' => $lastDate,
            'country'       => $country,
            'count'         => $data->count(),
            'data'          => $data->values(),
        ]);
    }

    // -------------------------------------------------------------------------
    // SEL-356 P0: GET /api/v1/insights/tiktok/brands/{external_id}/detail
    // Drill-down de marca: tenta tabela kalodata_brand_detail (populada pelo script).
    // Fallback: agrega dados da tabela kalodata_brands + criadores do nicho.
    // -------------------------------------------------------------------------
    public function brandDetail(\Illuminate\Http\Request $request, string $externalId)
    {
        $country = strtoupper($request->get('country', 'BR'));

        // Tenta tabela dedicada (populada pelo scrape kalodata_brand_detail.py)
        $detailRow = DB::table('kalodata_brand_detail')
            ->where('brand_external_id', $externalId)
            ->where('country', $country)
            ->orderByDesc('snapshot_date')
            ->first();

        // Busca dados basicos da marca
        $brandRow = DB::table('kalodata_brands')
            ->where('brand_id', $externalId)
            ->where('country', $country)
            ->orderByDesc('snapshot_date')
            ->first();

        if (!$brandRow) {
            return response()->json(['error' => 'Marca nao encontrada.'], 404);
        }

        $bp = json_decode($brandRow->payload, true) ?: [];
        $gmvUsd = $this->parseKaloRevenue((string) ($brandRow->revenue ?? ''));
        $gmvBrl = $this->kaloUsdToBrl($gmvUsd);

        $brand = [
            'external_id'          => $externalId,
            'brand_name'           => $brandRow->brand_name ?? '',
            'logo'                 => $brandRow->brand_logo ?? null,
            'gmv_monthly'          => $gmvBrl ?? $gmvUsd,
            'gmv_label'            => $gmvBrl !== null ? $this->fmtBrlCompact($gmvBrl) : ($brandRow->revenue ?? ''),
            'gmv_label_usd'        => $brandRow->revenue ?? '',
            'revenue_growth_rate'  => is_numeric($brandRow->revenue_growth_rate) ? (float) $brandRow->revenue_growth_rate : null,
            'market_share'         => is_numeric($brandRow->market_share) ? (float) $brandRow->market_share : null,
            'ad_spend_label'       => $brandRow->ad_spend ?? '',
            'avg_unit_price_label' => $brandRow->avg_unit_price ?? '',
            'item_sold'            => $brandRow->item_sold ?? '',
            'creators_count'       => (int) ($brandRow->creator_count ?? 0),
            'videos_count'         => $brandRow->video_count ?? '',
            'main_category'        => $brandRow->main_category ?? '',
            'revenue_trend'        => $bp['revenue_trend'] ?? [],
            'country'              => $country,
            'snapshot_date'        => $brandRow->snapshot_date,
        ];

        $topProducts = $detailRow ? (json_decode($detailRow->top_products, true) ?: []) : [];
        $topCreators = $detailRow ? (json_decode($detailRow->top_creators, true) ?: []) : [];

        // Fallback: busca criadores do mesmo nicho se nao tem scrape
        if (empty($topCreators) && !empty($brandRow->main_category)) {
            $crRows = DB::table('kalodata_raw')
                ->where('type', 'creators')
                ->whereRaw("JSON_EXTRACT(payload, '$.main_category') LIKE ?", ['%' . substr($brandRow->main_category, 0, 30) . '%'])
                ->orderBy('id')
                ->limit(5)
                ->get(['payload']);
            foreach ($crRows as $cr) {
                $cp = json_decode($cr->payload, true) ?: [];
                $topCreators[] = $this->parseKalodataCreator($cp);
            }
        }

        return response()->json([
            'brand'        => $brand,
            'top_products' => $topProducts,
            'top_creators' => $topCreators,
            'has_detail'   => $detailRow !== null,
        ]);
    }

    // -------------------------------------------------------------------------
    // SEL-356 P1: GET /api/v1/insights/tiktok/shops/{external_id}/detail
    // Drill-down de loja.
    // -------------------------------------------------------------------------
    public function shopDetail(\Illuminate\Http\Request $request, string $externalId)
    {
        $country = strtoupper($request->get('country', 'BR'));

        // Tenta tabela dedicada
        $detailRow = DB::table('kalodata_shop_detail')
            ->where('shop_external_id', $externalId)
            ->where('country', $country)
            ->orderByDesc('snapshot_date')
            ->first();

        // Busca dados basicos da loja (kalodata_raw type=shops)
        $lastDate = DB::table('kalodata_raw')->where('type', 'shops')->max('snapshot_date');
        $shopRow  = null;
        if ($lastDate) {
            $shopRow = DB::table('kalodata_raw')
                ->where('type', 'shops')
                ->where('snapshot_date', $lastDate)
                ->where('external_id', $externalId)
                ->first();
        }

        if (!$shopRow) {
            return response()->json(['error' => 'Loja nao encontrada.'], 404);
        }

        $sp = json_decode($shopRow->payload, true) ?: [];
        $shop = $this->parseKalodataShop($sp);

        $topProducts = $detailRow ? (json_decode($detailRow->top_products, true) ?: []) : [];
        $topCreators = $detailRow ? (json_decode($detailRow->top_creators, true) ?: []) : [];

        return response()->json([
            'shop'         => $shop,
            'top_products' => $topProducts,
            'top_creators' => $topCreators,
            'has_detail'   => $detailRow !== null,
        ]);
    }


}
