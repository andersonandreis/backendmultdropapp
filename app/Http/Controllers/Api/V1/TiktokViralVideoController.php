<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TiktokViralVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SEL-218: Endpoint público de vídeos virais do TikTok BR.
 *
 * GET /api/v1/tiktok-viral-videos
 *   Ordenado por viral_score DESC.
 *   RestrictFreeAccess já cobre api/v1/tiktok* — free tier acessa.
 *   Retorna os top 60 por padrão (param: per_page, max 100).
 *
 * Fonte: ScrapeTiktokViralVideosJob (diário 04:00 BRT via tikwm.com).
 */
class TiktokViralVideoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 60), 100);

        $videos = TiktokViralVideo::query()
            ->orderByDesc('viral_score')
            ->limit($perPage)
            ->get([
                'id',
                'external_video_id',
                'video_url',
                'cover_url',
                'creator_handle',
                'creator_name',
                'creator_avatar_url',
                'caption',
                'hashtags',
                'views',
                'comments',
                'likes',
                'shares',
                'viral_score',
                'detected_product_title',
                'detected_product_url',
                'search_term',
                'published_at',
                'scraped_at',
            ]);

        return response()->json([
            'data'       => $videos,
            'total'      => $videos->count(),
            'scraped_at' => $videos->max('scraped_at'),
        ]);
    }
}
