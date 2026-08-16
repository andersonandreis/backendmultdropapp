<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SEL-437 — Metadados REAIS do produto para os selos da Galeria.
 *
 * Problema (Ruan 30/07, ao vivo): "nome do produto na hora de baixar nao esta
 * pegando o titulo". Confirmado no banco:
 *   - ai_generations.wizard_payload.product_name  => NULL em 100% das linhas
 *     (AiVideoPipelineJob grava $payloads['product_name'], que nunca existe)
 *   - ai_video_pipelines                          => a galeria usa product_key
 *     como se fosse nome; product_key e chave interna
 *     ("3140da76-d75e-...", "a4dcafeb...32hex", "SEL418_validacao_1846")
 * Ou seja: o titulo NUNCA foi persistido junto da geracao.
 *
 * Onde o titulo real EXISTE de verdade: na conversa do Studio.
 *   studio_messages.content = 'Quero criar um video do produto "Afiador
 *   Eletrico De Facas E Tesouras Amolador Profissional" (R$ 28.00)'
 * e em studio_conversations.context.product_name / .product_info.analysis.
 *
 * Este endpoint faz SO leitura e devolve o que conseguir provar. Quando nao
 * acha, devolve null — o front mantem o modelo bloqueado com cadeado.
 * NUNCA inventa nome, preco, nota, avaliacao, venda ou estoque.
 *
 * GET /api/v1/studio/gallery-meta?ids=pipe-258,gen-126
 */
class StudioGalleryMetaController extends Controller
{
    /** Chaves internas que NAO sao nome de produto. */
    private function humanTitle($raw): ?string
    {
        if (!is_string($raw)) return null;
        $s = trim(preg_replace('/\s+/u', ' ', $raw));
        if ($s === '' || mb_strlen($s) < 2 || mb_strlen($s) > 140) return null;

        // UUID / hash hex / id numerico puro
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s)) return null;
        if (preg_match('/^[0-9a-f]{16,}$/i', $s)) return null;
        if (preg_match('/^\d+$/', $s)) return null;

        // Precisa ter pelo menos uma letra
        if (!preg_match('/\p{L}/u', $s)) return null;

        $temEspaco = str_contains($s, ' ');

        // Slug/chave interna: sem espaco e com separador tecnico
        if (!$temEspaco && preg_match('/[_\-]/', $s)) return null;
        // Marcadores internos conhecidos
        if (preg_match('/^(sel\d|custom_prompt|upload|manual|produto|teste|test)\b/i', $s) && !$temEspaco) return null;
        if (preg_match('/^(produto|product)$/i', $s)) return null;

        return $s;
    }

    private function money($raw): ?string
    {
        if ($raw === null || $raw === '' || is_array($raw)) return null;
        $n = is_numeric($raw) ? (float) $raw : (float) str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', (string) $raw));
        if (!is_finite($n) || $n <= 0) return null;
        return 'R$ ' . number_format($n, 2, ',', '.');
    }

    private function jsonArr($raw): array
    {
        if (is_array($raw)) return $raw;
        if (!is_string($raw) || $raw === '') return [];
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    /** Extrai o JSON de dentro de uma resposta de LLM (com ou sem cerca ```json). */
    private function jsonFromLlm(?string $raw): array
    {
        if (!is_string($raw) || $raw === '') return [];
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $d = json_decode($m[0], true);
            if (is_array($d)) return $d;
        }
        return [];
    }

    public function show(Request $r)
    {
        $userId = $r->user()?->id;
        if (!$userId) return response()->json(['data' => (object) []]);

        $ids = array_slice(array_filter(array_map('trim', explode(',', (string) $r->query('ids', '')))), 0, 60);
        if (empty($ids)) return response()->json(['data' => (object) []]);

        // Mapa pipeline -> conversa, montado UMA vez por request (e cacheado).
        // Antes isso era um LIKE por item em studio_messages.ui_widget (longtext):
        // levava mais de 2 min. Agora e uma consulta so, presa as conversas DESTE
        // usuario (indice em studio_conversations.user_id).
        $this->pipeToConv = $this->pipelineConversationMap((int) $userId);

        $out = [];
        foreach ($ids as $id) {
            try {
                $out[$id] = $this->resolve($id, (int) $userId);
            } catch (Throwable $e) {
                $out[$id] = $this->emptyMeta();
            }
        }

        return response()->json(['data' => $out ?: (object) []]);
    }

    /** @var array<int,int> pipeline_id => conversation_id */
    private array $pipeToConv = [];

    private function pipelineConversationMap(int $userId): array
    {
        return Cache::remember("sel437_pipe_conv_{$userId}", 300, function () use ($userId) {
            $map = [];
            $convIds = DB::table('studio_conversations')->where('user_id', $userId)
                ->orderByDesc('id')->limit(300)->pluck('id')->all();
            if (empty($convIds)) return $map;
            DB::table('studio_messages')
                ->whereIn('conversation_id', $convIds)
                ->where('ui_widget', 'like', '%generation_started%')
                ->orderBy('id')
                ->select(['conversation_id', 'ui_widget'])
                ->chunk(500, function ($rows) use (&$map) {
                    foreach ($rows as $row) {
                        if (preg_match_all('/"pipeline_id"\s*:\s*(\d+)/', (string) $row->ui_widget, $m)) {
                            foreach ($m[1] as $pid) $map[(int) $pid] = (int) $row->conversation_id;
                        }
                    }
                });
            return $map;
        });
    }

    private function emptyMeta(): array
    {
        return [
            'product_name' => null,
            'price'        => null,
            'discount'     => null,
            'rating'       => null,
            'reviews'      => null,
            'sales'        => null,
            // SEL-458: `stock` fica null de proposito. Foi procurado em tt_shop_raw,
            // kalodata_raw, tiktok_shop_trends, client_products e imported_products:
            // nao existe quantidade em estoque em lugar nenhum (0 ocorrencia de
            // stock/inventory/quantity nos payloads). O que `products` tem
            // (virtual_stock_qty) e estoque do FORNECEDOR do dropshipper, nao do
            // anuncio do TikTok — numero diferente, e ninguem consegue liga-lo ao
            // video. Preencher aqui seria inventar "ultimas 7 unidades".
            'stock'        => null,
        ];
    }

    private function resolve(string $id, int $userId): array
    {
        $meta = $this->emptyMeta();

        $pipelineId = null;
        $productKey = null;
        $payloads   = [];

        if (str_starts_with($id, 'gen-')) {
            $g = DB::table('ai_generations')
                ->where('id', (int) substr($id, 4))
                ->where('user_id', $userId)
                ->first(['wizard_payload']);
            if (!$g) return $meta;
            $wp = $this->jsonArr($g->wizard_payload);
            $meta['product_name'] = $this->humanTitle($wp['product_name'] ?? null);
            $meta['price']        = $this->money($wp['price'] ?? null);
            $meta['discount']     = $this->pctFrom($wp['discount'] ?? null);
            $pipelineId = isset($wp['_pipeline_id']) ? (int) $wp['_pipeline_id'] : null;
        } elseif (str_starts_with($id, 'pipe-')) {
            $pipelineId = (int) substr($id, 5);
        } else {
            return $meta;
        }

        if ($pipelineId) {
            $p = DB::table('ai_video_pipelines')
                ->where('id', $pipelineId)
                ->where('user_id', $userId)
                ->first(['product_key', 'payloads']);
            if ($p) {
                $productKey = $p->product_key;
                $payloads   = $this->jsonArr($p->payloads);
                $meta['product_name'] = $meta['product_name']
                    ?: $this->humanTitle($payloads['product_name'] ?? ($payloads['product'] ?? null));
                $meta['price'] = $meta['price'] ?: $this->money($payloads['price'] ?? null);
            }
        }

        // (1) conversa do Studio — onde o titulo real de fato existe
        $convId = isset($payloads['conv_id']) ? (int) $payloads['conv_id'] : null;
        if (!$convId && $pipelineId) {
            $convId = $this->pipeToConv[$pipelineId] ?? null;
        }
        if (!$meta['product_name'] || !$meta['price']) {
            if ($convId) {
                $conv = DB::table('studio_conversations')
                    ->where('id', $convId)->where('user_id', $userId)
                    ->first(['context']);
                if ($conv) {
                    $ctx = $this->jsonArr($conv->context);
                    $meta['product_name'] = $meta['product_name'] ?: $this->humanTitle($ctx['product_name'] ?? null);
                    if (!$meta['product_name']) {
                        $an = $this->jsonFromLlm($ctx['product_info']['analysis'] ?? null);
                        $meta['product_name'] = $this->humanTitle($an['product_name'] ?? null);
                    }
                    $meta['price'] = $meta['price'] ?: $this->money($ctx['price'] ?? null);
                }

                if (!$meta['product_name'] || !$meta['price']) {
                    $msgs = DB::table('studio_messages')
                        ->where('conversation_id', $convId)->where('role', 'user')
                        ->orderBy('id')->limit(12)->pluck('content');
                    foreach ($msgs as $c) {
                        $c = (string) $c;
                        if (!$meta['product_name']) {
                            if (preg_match('/produto[:\s]*"([^"]{2,140})"/u', $c, $m)) {
                                $meta['product_name'] = $this->humanTitle($m[1]);
                            } elseif (preg_match('/produto[:\s]*\*\*(.{2,140}?)\*\*/u', $c, $m)) {
                                $meta['product_name'] = $this->humanTitle($m[1]);
                            }
                        }
                        if (!$meta['price'] && preg_match('/\(\s*R\$\s*([\d.,]+)\s*\)/u', $c, $m)) {
                            $meta['price'] = $this->money(str_replace(',', '.', $m[1]));
                        }
                        if ($meta['product_name'] && $meta['price']) break;
                    }
                }
            }
        }

        // (2) preco do catalogo do proprio cliente, quando a conversa guardou o
        //     ID do client_product. Ligacao por ID — nunca por titulo.
        if (!$meta['price']) {
            $cpId = isset($payloads['product_id']) ? (int) $payloads['product_id'] : null;
            if (!$cpId && $convId) {
                $ctxRow = DB::table('studio_conversations')
                    ->where('id', $convId)->where('user_id', $userId)->first(['context']);
                $cpId = $ctxRow ? (int) ($this->jsonArr($ctxRow->context)['product_id'] ?? 0) : null;
            }
            if ($cpId) {
                $cp = DB::table('client_products')
                    ->leftJoin('products', 'products.id', '=', 'client_products.product_id')
                    ->where('client_products.id', $cpId)
                    ->first([
                        DB::raw('COALESCE(client_products.custom_title, products.name) as name'),
                        DB::raw('COALESCE(client_products.custom_price, products.price) as price'),
                    ]);
                if ($cp) {
                    $meta['product_name'] = $meta['product_name'] ?: $this->humanTitle($cp->name);
                    $meta['price']        = $this->money($cp->price);
                }
            }
        }

        // (3) SEL-458 — fatos REAIS do produto do TikTok Shop, pelo ID do produto.
        //
        // O que estava errado: a busca era `tiktok_shop_trends.external_id = product_key`.
        // Essa coluna guarda TERMO DE BUSCA ("squishy", "iphone 13", "cama box casal")
        // e hash de vitrine ("ttmall_480a391f..."), enquanto product_key guarda o ID do
        // produto ("1734450525627975659"). Naturezas diferentes: 0 de 82 casaram, e por
        // isso nota/vendas/preco vinham vazios em 100% dos videos.
        //
        // Onde o dado de verdade mora, com o MESMO ID como chave:
        //   tt_shop_raw   type='product'  -> title, product_price_info (preco e "de/por"
        //                                    em BRL declarado), rate_info, sold_info
        //   kalodata_raw  type='products' -> product_title, sale, product_rating
        //
        // Preco/desconto so saem de tt_shop_raw, que declara a moeda (currency_name BRL,
        // R$). O kalodata mostra dinheiro em dolar ("unit_price":"$17,44", "revenue":
        // "$78,96 mil") — dali sai venda e nota, nunca preco. Bloqueado e melhor que errado.
        $ttId = $this->tiktokProductId($productKey, $payloads);
        if ($ttId) {
            $f = $this->productFacts($ttId);
            $meta['product_name'] = $meta['product_name'] ?: $this->humanTitle($f['name'] ?? null);
            $meta['rating']  = $meta['rating']  ?? $this->ratingOf($f['rating'] ?? null);
            $meta['reviews'] = $meta['reviews'] ?? $this->intOf($f['reviews'] ?? null);
            $meta['sales']   = $meta['sales']   ?? $this->intOf($f['sales'] ?? null);

            // Preco e desconto andam JUNTOS ou nao andam. O selo "de / por" calcula o
            // preco antigo a partir do par (preco, desconto): misturar o preco que o
            // cliente digitou com o desconto do TikTok daria um "de" que nunca existiu.
            $ttPrice = $this->money($f['price'] ?? null);
            $ttDisc  = $this->pctFrom($f['discount'] ?? null);
            if ($ttPrice && (!$meta['price'] || $meta['price'] === $ttPrice)) {
                $meta['price']    = $ttPrice;
                $meta['discount'] = $meta['discount'] ?: $ttDisc;
            }
        }

        // (4) ultimo recurso: a propria chave, SO se parecer titulo humano
        if ($productKey) {
            $meta['product_name'] = $meta['product_name'] ?: $this->humanTitle($productKey);
        }

        return $meta;
    }

    /**
     * SEL-458 — descobre o ID do produto no TikTok Shop, so por caminho que PROVA
     * a identidade. Nada de casar por titulo: em SEL-439 o match por palavra fez
     * "Jogo de Panelas 10 Pecas" virar "Jogo De Panela Monaco" — produto diferente,
     * foto diferente, video errado. Sem prova, devolve null e o selo fica bloqueado.
     *
     *  (a) product_key ja E o ID do produto (fluxo TikTok Shopping -> wizard)
     *  (b) product_key dentro do payload, mesmo formato
     *  (c) a foto usada no video E um arquivo nosso de um produto conhecido
     *      (tiktok_product_images) — mesmo arquivo, mesmo anuncio, mesmo produto
     *  (d) product_key = md5 dessa mesma URL — o Studio guarda md5(image_url)
     *      como chave, entao o hash exato da URL exata devolve o produto
     */
    private function tiktokProductId(?string $productKey, array $payloads): ?string
    {
        $isId = fn ($v) => is_string($v) && preg_match('/^\d{15,25}$/', $v) ? $v : null;

        if ($id = $isId($productKey)) return $id;
        if ($id = $isId($payloads['product_key'] ?? null)) return $id;

        $idx = $this->productImageIndex();

        $urls = array_merge(
            [$payloads['image_url'] ?? null, $payloads['image'] ?? null],
            array_values((array) ($payloads['image_refs'] ?? []))
        );
        foreach ($urls as $u) {
            if (!is_string($u) || $u === '') continue;
            $u = preg_replace('/\?.*$/', '', $u);
            if (isset($idx['url'][$u])) return $idx['url'][$u];
        }

        if (is_string($productKey) && preg_match('/^[0-9a-f]{32}$/i', $productKey)
            && isset($idx['md5'][strtolower($productKey)])) {
            return $idx['md5'][strtolower($productKey)];
        }

        return null;
    }

    /** Arquivo de foto -> ID do produto. Tabela pequena; cache de 10 min. */
    private function productImageIndex(): array
    {
        return Cache::remember('sel458_img_pid', 600, function () {
            $out = ['url' => [], 'md5' => []];
            DB::table('tiktok_product_images')
                ->whereRaw("product_key REGEXP '^[0-9]{15,25}$'")
                ->select(['id', 'product_key', 'url_local', 'url_original'])
                ->orderBy('id')   // chunk() sem orderBy estoura
                ->chunk(500, function ($rows) use (&$out) {
                    foreach ($rows as $r) {
                        foreach ([$r->url_local, $r->url_original] as $u) {
                            if (!is_string($u) || !str_starts_with($u, 'http')) continue;
                            $u = preg_replace('/\?.*$/', '', $u);
                            $out['url'][$u]        = (string) $r->product_key;
                            $out['md5'][md5($u)]   = (string) $r->product_key;
                        }
                    }
                });
            return $out;
        });
    }

    /**
     * SEL-458 — o que se sabe de verdade sobre um produto do TikTok Shop.
     * Devolve so o que esta gravado; campo ausente volta null e o modelo que
     * depende dele continua bloqueado.
     */
    private function productFacts(string $ttId): array
    {
        return Cache::remember("sel458_facts_{$ttId}", 900, function () use ($ttId) {
            $f = ['name' => null, 'price' => null, 'discount' => null,
                  'rating' => null, 'reviews' => null, 'sales' => null];

            // tt_shop_raw: unica fonte de preco (declara BRL / R$).
            $row = DB::table('tt_shop_raw')->where('type', 'product')
                ->where('external_id', $ttId)->orderByDesc('snapshot_date')->first(['payload']);
            if ($row) {
                $p  = $this->jsonArr($row->payload);
                $pi = is_array($p['product_price_info'] ?? null) ? $p['product_price_info'] : [];
                $f['name']    = $p['title'] ?? null;
                $f['rating']  = $p['rate_info']['score'] ?? null;
                $f['reviews'] = $p['rate_info']['review_count'] ?? null;
                $f['sales']   = $p['sold_info']['sold_count'] ?? null;
                if (($pi['currency_name'] ?? 'BRL') === 'BRL') {
                    $f['price']    = $pi['sale_price_decimal'] ?? null;
                    $f['discount'] = $pi['discount_format'] ?? null;
                }
            }

            // kalodata_raw: venda e nota. Dinheiro dele e em dolar — nao vira preco.
            $k = DB::table('kalodata_raw')->where('type', 'products')
                ->where('external_id', $ttId)->orderByDesc('snapshot_date')->orderByDesc('id')
                ->first(['payload']);
            if ($k) {
                $d = $this->jsonArr($k->payload);
                $f['name']   = $f['name']   ?: ($d['product_title'] ?? null);
                $f['sales']  = $f['sales']  ?? ($d['sale'] ?? null);
                $f['rating'] = $f['rating'] ?? ($d['product_rating'] ?? null);
            }

            // tiktok_shop_trends: so casa quando external_id for mesmo o ID do
            // produto. Hoje nunca casa (guarda termo de busca), fica de reserva.
            if (!$f['name'] || !$f['sales'] || !$f['rating']) {
                $t = DB::table('tiktok_shop_trends')->where('external_id', $ttId)
                    ->first(['title', 'sales_l30d', 'orders', 'avg_rating', 'review_count']);
                if ($t) {
                    $f['name']    = $f['name']    ?: $t->title;
                    $f['sales']   = $f['sales']   ?? ($t->sales_l30d ?: $t->orders);
                    $f['rating']  = $f['rating']  ?? $t->avg_rating;
                    $f['reviews'] = $f['reviews'] ?? $t->review_count;
                }
            }

            return $f;
        });
    }

    private function pctFrom($raw): ?string
    {
        if ($raw === null || $raw === '' || is_array($raw)) return null;
        $n = (float) str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', (string) $raw));
        if (!is_finite($n) || $n <= 0 || $n >= 100) return null;
        // SEL-458: era `rtrim(rtrim(number_format(...), '0'), ',')`, herdado de um
        // formatador de decimal. Em numero inteiro ele comia o zero final: 80%
        // virava "-8%" e 10% virava "-1%". Desconto e numero que o cliente le na
        // tela — arredonda e pronto.
        return '-' . (int) round($n) . '%';
    }

    private function ratingOf($raw): ?float
    {
        if ($raw === null || $raw === '' || is_array($raw)) return null;
        $n = (float) $raw;
        if (!is_finite($n) || $n <= 0 || $n > 5) return null;
        return round($n, 1);
    }

    private function intOf($raw): ?int
    {
        if ($raw === null || $raw === '' || is_array($raw)) return null;
        $s = strtolower(trim((string) $raw));
        $mult = 1;
        if (preg_match('/^([\d.,]+)\s*(k|mil)$/u', $s, $m)) { $s = $m[1]; $mult = 1000; }
        elseif (preg_match('/^([\d.,]+)\s*(m|mi)$/u', $s, $m)) { $s = $m[1]; $mult = 1000000; }
        $s = str_replace(',', '.', preg_replace('/[^\d,.]/', '', $s));
        if ($s === '') return null;
        $n = (float) $s * $mult;
        if (!is_finite($n) || $n <= 0) return null;
        return (int) round($n);
    }
}
