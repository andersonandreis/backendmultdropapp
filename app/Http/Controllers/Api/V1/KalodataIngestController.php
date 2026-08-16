<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * SEL-462 Fase 1: Endpoints POST para ingest diario do worker Kalodata (VM DICloak).
 * Cada metodo recebe payload do scraper Python e faz upsert nas tabelas correspondentes.
 * Autenticado via middleware auth.kalodata_token (X-Kalodata-Token header).
 */
class KalodataIngestController extends Controller
{
    /**
     * POST /api/v1/insights/tiktok/ingest/creators
     *
     * Body JSON: {
     *   snapshot_date: "YYYY-MM-DD",
     *   items: [{ rank_kalodata, handle, nickname, bio_full, avatar_url_original,
     *             followers, following, likes_total, videos_count, gender,
     *             main_category, sub_categories, gmv_30d, sales_30d,
     *             unit_price_avg, engagement_rate, views_30d, top_videos,
     *             promoted_products, live_summary, revenue_trend_90d,
     *             views_trend_90d, raw_payload, scraped_at }, ...]
     * }
     */
    public function creators(Request $r)
    {
        $r->validate([
            'snapshot_date'         => 'required|date_format:Y-m-d',
            'items'                 => 'required|array|min:1',
            'items.*.rank_kalodata' => 'required|integer|min:1',
            'items.*.handle'        => 'required|string|max:100',
        ]);

        $date     = $r->input('snapshot_date');
        $items    = $r->input('items');
        $now      = now();
        $upserted = 0;

        foreach ($items as $item) {
            $handle = $item['handle'];

            // upsert kalodata_creator_detail (unique: snapshot_date + handle)
            DB::table('kalodata_creator_detail')->upsert(
                [[
                    'snapshot_date'       => $date,
                    'rank_kalodata'       => $item['rank_kalodata'],
                    'handle'              => $handle,
                    'nickname'            => $item['nickname'] ?? null,
                    'bio_full'            => $item['bio_full'] ?? null,
                    'avatar_url_local'    => $item['avatar_url_local'] ?? null,
                    'avatar_url_original' => $item['avatar_url_original'] ?? null,
                    'followers'           => $item['followers'] ?? null,
                    'following'           => $item['following'] ?? null,
                    'likes_total'         => $item['likes_total'] ?? null,
                    'videos_count'        => $item['videos_count'] ?? null,
                    'gender'              => $item['gender'] ?? null,
                    'main_category'       => $item['main_category'] ?? null,
                    'sub_categories'      => isset($item['sub_categories']) ? json_encode($item['sub_categories']) : null,
                    'gmv_30d'             => $item['gmv_30d'] ?? null,
                    'sales_30d'           => $item['sales_30d'] ?? null,
                    'unit_price_avg'      => $item['unit_price_avg'] ?? null,
                    'engagement_rate'     => $item['engagement_rate'] ?? null,
                    'views_30d'           => $item['views_30d'] ?? null,
                    'top_videos'          => isset($item['top_videos']) ? json_encode($item['top_videos']) : null,
                    'promoted_products'   => isset($item['promoted_products']) ? json_encode($item['promoted_products']) : null,
                    'live_summary'        => isset($item['live_summary']) ? json_encode($item['live_summary']) : null,
                    'revenue_trend_90d'   => isset($item['revenue_trend_90d']) ? json_encode($item['revenue_trend_90d']) : null,
                    'views_trend_90d'     => isset($item['views_trend_90d']) ? json_encode($item['views_trend_90d']) : null,
                    'raw_payload'         => isset($item['raw_payload']) ? json_encode($item['raw_payload']) : null,
                    'scraped_at'          => $item['scraped_at'] ?? $now,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]],
                ['snapshot_date', 'handle'],
                ['rank_kalodata', 'nickname', 'bio_full', 'avatar_url_local', 'avatar_url_original',
                 'followers', 'following', 'likes_total', 'videos_count', 'gender', 'main_category',
                 'sub_categories', 'gmv_30d', 'sales_30d', 'unit_price_avg', 'engagement_rate',
                 'views_30d', 'top_videos', 'promoted_products', 'live_summary',
                 'revenue_trend_90d', 'views_trend_90d', 'raw_payload', 'scraped_at', 'updated_at']
            );

            // espelha raw em kalodata_raw type=creators — compatibilidade com KalodataController
            DB::table('kalodata_raw')->upsert(
                [[
                    'type'          => 'creators',
                    'snapshot_date' => $date,
                    'window_range'  => '1d',
                    'external_id'   => $handle,
                    'payload'       => json_encode($item['raw_payload'] ?? $item),
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]],
                ['type', 'snapshot_date', 'external_id'],
                ['payload', 'updated_at']
            );

            $upserted++;
        }

        return response()->json([
            'ok'            => true,
            'snapshot_date' => $date,
            'upserted'      => $upserted,
        ]);
    }

    /**
     * POST /api/v1/insights/tiktok/ingest/products
     *
     * Body JSON: {
     *   snapshot_date: "YYYY-MM-DD",
     *   items: [{ rank_kalodata, external_id, shop_id (optional), raw_payload }, ...]
     * }
     */
    public function products(Request $r)
    {
        $r->validate([
            'snapshot_date'         => 'required|date_format:Y-m-d',
            'items'                 => 'required|array|min:1',
            'items.*.external_id'   => 'required|string|max:64',
            'items.*.rank_kalodata' => 'required|integer|min:1',
        ]);

        $date     = $r->input('snapshot_date');
        $items    = $r->input('items');
        $now      = now();
        $upserted = 0;

        // SEL paid (08/08): kalodata_raw NAO tem indice unico (type,snapshot,external_id);
        // ->upsert insere DUPLICADO a cada rodada e o painel (orderBy id) acaba pegando a
        // linha antiga/rasa (sem detalhe profundo). Deleta as linhas destes produtos neste
        // snapshot antes de reinserir — mesmo padrao do kalodata:sync. 1 linha por produto.
        $batchIds = array_values(array_filter(array_map(
            fn ($it) => (string) ($it['external_id'] ?? ''), $items
        )));
        if (!empty($batchIds)) {
            DB::table('kalodata_raw')
                ->where('type', 'products')
                ->where('snapshot_date', $date)
                ->whereIn('external_id', $batchIds)
                ->delete();
        }

        foreach ($items as $item) {
            $extId = $item['external_id'];

            DB::table('kalodata_raw')->upsert(
                [[
                    'type'          => 'products',
                    'snapshot_date' => $date,
                    'window_range'  => '1d',
                    'external_id'   => $extId,
                    'payload'       => json_encode($item['raw_payload'] ?? $item),
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]],
                ['type', 'snapshot_date', 'external_id'],
                ['payload', 'updated_at']
            );

            // enrich tt_shop_raw se vier shop_id
            if (!empty($item['shop_id'])) {
                DB::table('tt_shop_raw')->upsert(
                    [[
                        'type'          => 'product',
                        'external_id'   => $extId,
                        'snapshot_date' => $date,
                        'payload'       => json_encode($item['raw_payload'] ?? $item),
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]],
                    ['type', 'external_id', 'snapshot_date'],
                    ['payload', 'updated_at']
                );
            }

            $upserted++;
        }

        return response()->json(['ok' => true, 'snapshot_date' => $date, 'upserted' => $upserted]);
    }

    /**
     * POST /api/v1/insights/tiktok/ingest/ads
     *
     * Body JSON: {
     *   snapshot_date: "YYYY-MM-DD",
     *   country: "BR",
     *   items: [{ video_id, creator_handle, avatar, kalodata_rank, revenue_str,
     *             views_str, sale, gpm, ad_roas, ad_cpa, ad_view_ratio,
     *             publish_date, ai_video, raw_payload }, ...]
     * }
     */
    public function ads(Request $r)
    {
        $r->validate([
            'snapshot_date'         => 'required|date_format:Y-m-d',
            'items'                 => 'required|array|min:1',
            'items.*.video_id'      => 'required|string|max:64',
            'items.*.kalodata_rank' => 'required|integer|min:1',
        ]);

        $date     = $r->input('snapshot_date');
        $country  = $r->input('country', 'BR');
        $items    = $r->input('items');
        $now      = now();
        $upserted = 0;

        foreach ($items as $item) {
            DB::table('kalodata_ads')->upsert(
                [[
                    'video_id'       => $item['video_id'],
                    'snapshot_date'  => $date,
                    'window_range'   => $item['window_range'] ?? '7d',
                    'country'        => $country,
                    'source'         => 'kalodata_worker',
                    'creator_handle' => $item['creator_handle'] ?? null,
                    'avatar'         => $item['avatar'] ?? null,
                    'kalodata_rank'  => $item['kalodata_rank'],
                    'ad'             => isset($item['ad']) ? (int) $item['ad'] : 1,
                    'gpm'            => $item['gpm'] ?? null,
                    'ad_cpa'         => $item['ad_cpa'] ?? null,
                    'ad_view_ratio'  => $item['ad_view_ratio'] ?? null,
                    'ad_cost'        => $item['ad_cost'] ?? null,
                    'ad_roas'        => $item['ad_roas'] ?? null,
                    'ai_video'       => isset($item['ai_video']) ? (int) $item['ai_video'] : 0,
                    'views_str'      => $item['views_str'] ?? null,
                    'revenue_str'    => $item['revenue_str'] ?? null,
                    'sale'           => $item['sale'] ?? null,
                    'publish_date'   => $item['publish_date'] ?? null,
                    'payload'        => json_encode($item['raw_payload'] ?? $item),
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]],
                ['video_id', 'snapshot_date', 'country'],
                ['kalodata_rank', 'avatar', 'gpm', 'ad_cpa', 'ad_view_ratio', 'ad_roas',
                 'ai_video', 'views_str', 'revenue_str', 'sale', 'payload', 'updated_at']
            );
            $upserted++;
        }

        return response()->json(['ok' => true, 'snapshot_date' => $date, 'upserted' => $upserted]);
    }

    /**
     * POST /api/v1/insights/tiktok/ingest/lives
     *
     * Body JSON: {
     *   snapshot_date: "YYYY-MM-DD",
     *   country: "BR",
     *   items: [{ live_id, creator_handle, kalodata_rank, title, uid, revenue,
     *             sale, unit_price, duration, views, gpm, main_category,
     *             create_time, finish_time, avatar, raw_payload }, ...]
     * }
     */
    public function lives(Request $r)
    {
        $r->validate([
            'snapshot_date'         => 'required|date_format:Y-m-d',
            'items'                 => 'required|array|min:1',
            'items.*.live_id'       => 'required|string|max:64',
            'items.*.kalodata_rank' => 'required|integer|min:1',
        ]);

        $date     = $r->input('snapshot_date');
        $country  = $r->input('country', 'BR');
        $items    = $r->input('items');
        $now      = now();
        $upserted = 0;

        foreach ($items as $item) {
            DB::table('kalodata_lives_ranking')->upsert(
                [[
                    'live_id'        => $item['live_id'],
                    'snapshot_date'  => $date,
                    'window_range'   => $item['window_range'] ?? '7d',
                    'country'        => $country,
                    'source'         => 'kalodata_worker',
                    'uid'            => $item['uid'] ?? null,
                    'creator_handle' => $item['creator_handle'] ?? null,
                    'avatar'         => $item['avatar'] ?? null,
                    'kalodata_rank'  => $item['kalodata_rank'],
                    'title'          => $item['title'] ?? null,
                    'main_category'  => $item['main_category'] ?? null,
                    'create_time'    => $item['create_time'] ?? null,
                    'finish_time'    => $item['finish_time'] ?? null,
                    'duration'       => $item['duration'] ?? null,
                    'revenue'        => $item['revenue'] ?? null,
                    'sale'           => $item['sale'] ?? null,
                    'views'          => $item['views'] ?? null,
                    'gpm'            => $item['gpm'] ?? null,
                    'unit_price'     => $item['unit_price'] ?? null,
                    'payload'        => json_encode($item['raw_payload'] ?? $item),
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]],
                ['live_id', 'snapshot_date', 'country'],
                ['kalodata_rank', 'avatar', 'title', 'revenue', 'sale', 'views',
                 'gpm', 'duration', 'payload', 'updated_at']
            );

            // espelha em kalodata_raw type=lives
            DB::table('kalodata_raw')->upsert(
                [[
                    'type'          => 'lives',
                    'snapshot_date' => $date,
                    'window_range'  => '1d',
                    'external_id'   => $item['live_id'],
                    'payload'       => json_encode($item['raw_payload'] ?? $item),
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]],
                ['type', 'snapshot_date', 'external_id'],
                ['payload', 'updated_at']
            );

            $upserted++;
        }

        return response()->json(['ok' => true, 'snapshot_date' => $date, 'upserted' => $upserted]);
    }

    /**
     * POST /api/v1/insights/tiktok/ingest/brands
     *
     * Body JSON: {
     *   snapshot_date: "YYYY-MM-DD",
     *   country: "BR",
     *   items: [{ external_id, rank, brand_name, logo, revenue, market_share,
     *             ad_spend, avg_unit_price, item_sold, creator_count, video_count,
     *             main_category, top_products, top_creators, gmv_by_day, raw_payload }, ...]
     * }
     */
    public function brands(Request $r)
    {
        $r->validate([
            'snapshot_date'       => 'required|date_format:Y-m-d',
            'items'               => 'required|array|min:1',
            'items.*.external_id' => 'required|string|max:64',
            'items.*.rank'        => 'required|integer|min:1',
        ]);

        $date     = $r->input('snapshot_date');
        $country  = $r->input('country', 'BR');
        $items    = $r->input('items');
        $now      = now();
        $upserted = 0;

        foreach ($items as $item) {
            $extId = $item['external_id'];

            DB::table('kalodata_brands')->upsert(
                [[
                    'snapshot_date'       => $date,
                    'country'             => $country,
                    'brand_id'            => $extId,
                    'brand_name'          => $item['brand_name'] ?? null,
                    'brand_logo'          => $item['logo'] ?? null,
                    'revenue'             => $item['revenue'] ?? null,
                    'revenue_growth_rate' => $item['revenue_growth_rate'] ?? null,
                    'market_share'        => $item['market_share'] ?? null,
                    'ad_spend'            => $item['ad_spend'] ?? null,
                    'avg_unit_price'      => $item['avg_unit_price'] ?? null,
                    'item_sold'           => $item['item_sold'] ?? null,
                    'creator_count'       => $item['creator_count'] ?? null,
                    'video_count'         => $item['video_count'] ?? null,
                    'main_category'       => $item['main_category'] ?? null,
                    'payload'             => json_encode($item['raw_payload'] ?? $item),
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]],
                ['snapshot_date', 'country', 'brand_id'],
                ['brand_name', 'brand_logo', 'revenue', 'market_share', 'ad_spend',
                 'avg_unit_price', 'item_sold', 'creator_count', 'video_count',
                 'main_category', 'payload', 'updated_at']
            );

            // upsert kalodata_brand_detail se vier drill-down
            if (!empty($item['top_products']) || !empty($item['top_creators'])) {
                DB::table('kalodata_brand_detail')->upsert(
                    [[
                        'brand_external_id' => $extId,
                        'snapshot_date'     => $date,
                        'country'           => $country,
                        'top_products'      => isset($item['top_products']) ? json_encode($item['top_products']) : null,
                        'top_creators'      => isset($item['top_creators']) ? json_encode($item['top_creators']) : null,
                        'gmv_by_day'        => isset($item['gmv_by_day']) ? json_encode($item['gmv_by_day']) : null,
                        'detail_payload'    => json_encode($item['raw_payload'] ?? $item),
                        'created_at'        => $now,
                        'updated_at'        => $now,
                    ]],
                    ['brand_external_id', 'snapshot_date', 'country'],
                    ['top_products', 'top_creators', 'gmv_by_day', 'detail_payload', 'updated_at']
                );
            }

            $upserted++;
        }

        return response()->json(['ok' => true, 'snapshot_date' => $date, 'upserted' => $upserted]);
    }

    /**
     * POST /api/v1/insights/tiktok/ingest/shops
     *
     * Body JSON: {
     *   snapshot_date: "YYYY-MM-DD",
     *   country: "BR",
     *   items: [{ external_id, rank, shop_name, top_products, top_creators,
     *             gmv_by_day, raw_payload }, ...]
     * }
     */
    public function shops(Request $r)
    {
        $r->validate([
            'snapshot_date'       => 'required|date_format:Y-m-d',
            'items'               => 'required|array|min:1',
            'items.*.external_id' => 'required|string|max:64',
            'items.*.rank'        => 'required|integer|min:1',
        ]);

        $date     = $r->input('snapshot_date');
        $country  = $r->input('country', 'BR');
        $items    = $r->input('items');
        $now      = now();
        $upserted = 0;

        foreach ($items as $item) {
            $extId = $item['external_id'];

            DB::table('kalodata_raw')->upsert(
                [[
                    'type'          => 'shops',
                    'snapshot_date' => $date,
                    'window_range'  => '1d',
                    'external_id'   => $extId,
                    'payload'       => json_encode($item['raw_payload'] ?? $item),
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]],
                ['type', 'snapshot_date', 'external_id'],
                ['payload', 'updated_at']
            );

            // upsert kalodata_shop_detail se vier drill-down
            if (!empty($item['top_products']) || !empty($item['top_creators'])) {
                DB::table('kalodata_shop_detail')->upsert(
                    [[
                        'shop_external_id' => $extId,
                        'snapshot_date'    => $date,
                        'country'          => $country,
                        'top_products'     => isset($item['top_products']) ? json_encode($item['top_products']) : null,
                        'top_creators'     => isset($item['top_creators']) ? json_encode($item['top_creators']) : null,
                        'gmv_by_day'       => isset($item['gmv_by_day']) ? json_encode($item['gmv_by_day']) : null,
                        'detail_payload'   => json_encode($item['raw_payload'] ?? $item),
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ]],
                    ['shop_external_id', 'snapshot_date', 'country'],
                    ['top_products', 'top_creators', 'gmv_by_day', 'detail_payload', 'updated_at']
                );
            }

            $upserted++;
        }

        return response()->json(['ok' => true, 'snapshot_date' => $date, 'upserted' => $upserted]);
    }

    /**
     * POST /api/v1/insights/tiktok/ingest/media  (multipart/form-data)
     *
     * Fields:
     *   area          = creators|products|ads|lives|brands|shops
     *   snapshot_date = YYYY-MM-DD
     *   filename      = rank_external_id.ext
     *   file          = binary (UploadedFile)
     *
     * Salva em storage/app/public/tt-media/kalodata/{area}/{snapshot_date}/{filename}
     */
    public function media(Request $r)
    {
        $r->validate([
            'area'          => 'required|string|in:creators,products,ads,lives,brands,shops',
            'snapshot_date' => 'required|date_format:Y-m-d',
            'filename'      => 'required|string|max:200',
            'file'          => 'required|file|max:102400',
        ]);

        $area     = $r->input('area');
        $date     = $r->input('snapshot_date');
        $filename = basename($r->input('filename'));
        $path     = "tt-media/kalodata/{$area}/{$date}";

        $stored = $r->file('file')->storeAs($path, $filename, 'public');

        if (!$stored) {
            return response()->json(['message' => 'Storage failed.'], 500);
        }

        return response()->json([
            'ok'  => true,
            'url' => Storage::disk('public')->url($stored),
            'path' => $stored,
        ]);
    }
}
