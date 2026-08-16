<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEL-044: cache de tendencias raspadas do TikTok Shop Seller Center.
 * - POST /api/v1/admin/trends/tiktok-shop/ingest (admin) recebe payload
 *   do coletor Playwright e substitui os registros do dia.
 * - GET  /api/v1/trends/tiktok-shop lista dados publicos (auth ou nao,
 *   configurado nas rotas).
 *
 * SEL-148: ingest aceita avg_rating, review_count, commission_rate opcionais.
 *          GET retorna esses campos para produtos.
 */
class TiktokShopTrendsController extends Controller
{
    /**
     * Ingesta em massa dos leads TikTok Shop.
     * Body esperado:
     *   { "kind": "product|creator|live",
     *     "items": [ { lead_id?, lead_name, pic_url[], level1..3_cate_name,
     *                  search_volume, online_products, l30d_sales_volume,
     *                  gmv_l30d, potential_score, order, gmv,
     *                  avg_rating?, review_count?, commission_rate? }, ... ] }
     *
     * SEL-148: avg_rating, review_count, commission_rate sao opcionais.
     * Quando presentes no payload (vindos do coletor Playwright ou de outro
     * processo), sao salvos nas colunas correspondentes.
     */
    public function ingest(Request $r)
    {
        $v = $r->validate([
            'kind' => 'required|in:product,creator,live',
            'items' => 'required|array|min:1|max:2000',
            'items.*' => 'array',
        ]);
        $kind = $v['kind'];
        $now = now();
        // remove todos os registros antigos do mesmo kind (guarda apenas o snapshot mais recente)
        DB::table('tiktok_shop_trends')->where('kind', $kind)->delete();

        $rows = [];
        foreach ($v['items'] as $it) {
            // SEL-148: normaliza commission_rate — aceita fracao (0.15) ou percentual (15.0)
            $commissionRate = null;
            if (isset($it['commission_rate'])) {
                $raw = (float) $it['commission_rate'];
                $commissionRate = ($raw > 0 && $raw <= 1.0) ? round($raw * 100, 2) : round($raw, 2);
            }

            // SEL-175BE: aceita `handle` explicito no payload (Playwright manda
            // pra kind=creator). Se ausente, tenta unique_id/handle no raw ou
            // usa external_id como fallback (assumindo que ja e o handle).
            $handleField = null;
            if ($kind === 'creator') {
                $handleField = (string) (
                    $it['handle']
                    ?? $it['unique_id']
                    ?? $it['creator_handle']
                    ?? $it['lead_id']
                    ?? $it['id']
                    ?? $it['external_id']
                    ?? ''
                );
                $handleField = ltrim(trim($handleField), '@');
                if ($handleField === '') $handleField = null;
                else $handleField = mb_substr($handleField, 0, 80);
            }

            $rows[] = [
                'kind'            => $kind,
                'external_id'     => (string) ($it['lead_id'] ?? $it['id'] ?? $it['external_id'] ?? ''),
                'handle'          => $handleField,
                'title'           => mb_substr((string) ($it['lead_name'] ?? $it['name'] ?? $it['title'] ?? '(sem titulo)'), 0, 240),
                'images'          => json_encode(is_array($it['pic_url'] ?? null) ? $it['pic_url'] : (array) ($it['images'] ?? [])),
                'category_l1'     => mb_substr((string) ($it['level1_cate_name'] ?? ''), 0, 118),
                'category_l2'     => mb_substr((string) ($it['level2_cate_name'] ?? ''), 0, 118),
                'category_l3'     => mb_substr((string) ($it['level3_cate_name'] ?? ''), 0, 118),
                'search_volume'   => (string) ($it['search_volume'] ?? ''),
                'online_products' => (string) ($it['online_products'] ?? ''),
                'sales_l30d'      => (string) ($it['l30d_sales_volume'] ?? ''),
                'gmv_l30d'        => mb_substr((string) ($it['gmv_l30d'] ?? ''), 0, 38),
                'potential_score' => (string) ($it['potential_score'] ?? ''),
                'orders'          => (string) ($it['order'] ?? ''),
                'gmv'             => mb_substr((string) ($it['gmv'] ?? ''), 0, 38),
                // SEL-148: campos de produto (opcionais, vindos do coletor ou enrich)
                'avg_rating'      => isset($it['avg_rating'])    ? (float) $it['avg_rating']    : null,
                'review_count'    => isset($it['review_count'])  ? (int)   $it['review_count']  : null,
                'commission_rate' => $commissionRate,
                'raw'             => json_encode($it),
                'captured_at'     => $now,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }
        // insere em blocos de 100 pra respeitar limite MySQL
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('tiktok_shop_trends')->insert($chunk);
        }
        return response()->json(['ok' => true, 'kind' => $kind, 'inserted' => count($rows)]);
    }

    /**
     * GET publico (paginado). Filtros: kind, category (busca em l1/l2/l3), q (title).
     * SEL-148: retorna avg_rating, review_count, commission_rate para produtos.
     */
    public function index(Request $r)
    {
        $cols = [
            'id','kind','external_id',
            // SEL-175BE: retorna handle indexado (pra kind=creator).
            'handle',
            'title','images',
            'category_l1','category_l2','category_l3',
            'search_volume','online_products','sales_l30d','gmv_l30d',
            'potential_score','orders','gmv',
            'source','source_url',
            'matched_supplier_id','matched_supplier_score',
            // SEL-148: novos campos de produto
            'avg_rating','review_count','commission_rate',
            'captured_at',
        ];
        // SEL-049: criadores/lives precisam do payload completo (avatar, top videos, produtos)
        if ($r->boolean('with_raw')) $cols[] = 'raw';
        $q = DB::table('tiktok_shop_trends')
            ->select($cols)
            // SEL-142 Ruan 01:10: pra source=all mistura as 3 fontes pelo volume de busca
            // (nao pega captured_at desc que so mostra Shopee primeiro).
            ->orderByRaw("CAST(REPLACE(REPLACE(search_volume,'.',''),',','') AS UNSIGNED) DESC")
            ->orderByDesc('captured_at');
        if ($kind = $r->query('kind')) $q->where('kind', $kind);
        // SEL-126 Ruan 15/07: filtro por fonte (source=all pega tudo, ausente = tiktok_shop por backcompat).
        $src = $r->query('source');
        if ($src && $src !== 'all') $q->where('source', $src);
        if (!$src) $q->where('source', 'tiktok_shop');
        // SEL-123 Ruan PDF: admin controla exibicao. Nao-admins veem so is_visible=1.
        // Cliente demo/free veem so is_approved=1 (Ruan curadoria).
        $user = $r->user();
        $role = $user?->role ?? null;
        $isAdmin = in_array($role, ['admin', 'super_admin'], true);
        if (!$isAdmin) {
            $q->where('is_visible', true);
        }
        if ($cat = $r->query('category')) {
            $q->where(function ($w) use ($cat) {
                $w->where('category_l1', 'like', "%{$cat}%")
                  ->orWhere('category_l2', 'like', "%{$cat}%")
                  ->orWhere('category_l3', 'like', "%{$cat}%");
            });
        }
        if ($t = $r->query('q')) $q->where('title', 'like', "%{$t}%");
        $per = min((int) $r->query('per_page', 40), 1000);
        return response()->json($q->paginate($per));
    }
}