<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TiktokViralVideo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SEL-387 — Viral Suggestions para o Studio Chat estilo Kaloclip.
 *
 * POST /api/v1/studio/viral-suggestions
 * Retorna 4 videos virais da tabela tiktok_viral_videos com thumbnails
 * sempre no nosso CDN (formato api.seller.global/storage/tt-media/viralvid_N.jpg).
 *
 * Ordena por viral_score DESC. Se product_name enviado, busca com palavras-chave.
 */
class StudioViralSuggestionsController extends Controller
{
    public function suggestions(Request $request)
    {
        $v = $request->validate([
            'product_id'   => 'nullable|integer',
            'product_key'  => 'nullable|string|max:200',
            'product_name' => 'nullable|string|max:300',
            'limit'        => 'nullable|integer|min:1|max:12',
        ]);

        $limit = (int) ($v['limit'] ?? 4);

        // Tenta filtrar por palavras-chave do produto
        $keywords = [];
        if (!empty($v['product_name'])) {
            $name = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $v['product_name']);
            $words = array_filter(preg_split('/\s+/', trim($name)), fn($w) => mb_strlen($w) >= 4);
            $keywords = array_slice(array_values($words), 0, 3);
        }

        $query = TiktokViralVideo::query()
            ->whereNotNull('cover_url')
            ->where('cover_url', '!=', '')
            ->orderByDesc('viral_score');

        if (!empty($keywords)) {
            $filteredQuery = (clone $query)->where(function ($q) use ($keywords) {
                foreach ($keywords as $kw) {
                    $q->orWhere('caption', 'like', '%' . $kw . '%')
                      ->orWhere('search_term', 'like', '%' . $kw . '%')
                      ->orWhere('detected_product_title', 'like', '%' . $kw . '%');
                }
            });
            // Se filtro traz menos que o limite, usa top geral
            if ($filteredQuery->count() >= $limit) {
                $query = $filteredQuery;
            }
        }

        $videos = $query->limit($limit)->get([
            'id',
            'caption',
            'cover_url',
            'views',
            'likes',
            'shares',
            'viral_score',
            'creator_handle',
            'creator_name',
            'detected_product_title',
            'search_term',
            'published_at',
        ]);

        $appUrl = rtrim(config('app.url', 'https://api.seller.global'), '/');

        $data = $videos->map(function ($item) use ($appUrl) {
            // Garante thumbnail sempre no nosso CDN
            $thumb = $item->cover_url;
            if (empty($thumb) || !str_contains($thumb, 'api.seller.global')) {
                $thumb = "{$appUrl}/storage/tt-media/viralvid_{$item->id}.jpg";
            }

            // Hook extraido dos primeiros 80 chars da caption
            $caption = $item->caption ?? '';
            $hook = mb_strlen($caption) > 80 ? mb_substr($caption, 0, 80) . '...' : $caption;
            if (empty(trim($hook))) {
                $hook = $item->detected_product_title ?? 'Video viral TikTok Shop';
            }

            // Views formatado
            $views = (int) $item->views;
            $viewsFmt = $views >= 1_000_000
                ? number_format($views / 1_000_000, 1) . 'M'
                : ($views >= 1_000 ? number_format($views / 1_000, 0) . 'K' : (string) $views);

            return [
                'id'        => $item->id,
                'title'     => $item->detected_product_title ?? ($item->search_term ?? 'Video viral TikTok Shop'),
                'thumbnail' => $thumb,
                'hook'      => $hook,
                'views'     => $views,
                'views_fmt' => $viewsFmt,
                'likes'     => (int) $item->likes,
                'shop'      => $item->creator_name ?? $item->creator_handle ?? 'TikTok Shop',
                'duration'  => 12,
            ];
        });

        Log::info('[StudioViral] suggestions returned', [
            'product_id'      => $v['product_id'] ?? null,
            'keywords'        => $keywords,
            'count_returned'  => $data->count(),
        ]);

        return response()->json(['data' => $data]);
    }
}
