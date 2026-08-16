<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiCreator;
use Illuminate\Http\Request;

/**
 * SEL-199 — Endpoints Criadores IA
 *
 * Público:
 *   GET /api/v1/ai-creators
 *       ?order_by=rank|revenue|followers
 *       &min_revenue=X
 *       &per_page=N   (max 100, default 60)
 *
 * Admin (middleware CheckIsAdmin):
 *   GET    /api/v1/admin/ai-creators
 *   POST   /api/v1/admin/ai-creators
 *   PATCH  /api/v1/admin/ai-creators/{aiCreator}
 *   DELETE /api/v1/admin/ai-creators/{aiCreator}
 *   POST   /api/v1/admin/ai-creators/{aiCreator}/regen-avatar
 *   POST   /api/v1/admin/ai-creators/recompute-ranks   SEL-217
 */
class AiCreatorController extends Controller
{
    // ─── Público ────────────────────────────────────────────────

    /**
     * Lista criadores visíveis, aprovados.
     * SEL-217: default per_page=60, retorna rank_position + commission completo.
     * rank_position é atribuído pelo SyncTokfyCreatorsJob (1..N ordenado por revenue).
     */
    public function index(Request $request)
    {
        $q = AiCreator::public();

        if ($request->filled('min_revenue')) {
            $q->where('estimated_revenue', '>=', (float) $request->input('min_revenue'));
        }

        $orderBy = $request->input('order_by', 'rank');
        if ($orderBy === 'revenue') {
            $q->orderByDesc('estimated_revenue');
        } elseif ($orderBy === 'followers') {
            $q->orderByDesc('followers');
        } else {
            // rank default: rank_position ASC (nulls last) → estimated_revenue DESC
            $q->ranked();
        }

        $perPage = min((int) $request->input('per_page', 60), 100);
        $data    = $q->paginate($perPage);

        // Garante que rank_position reflete a posição real na lista retornada
        // (caso algum creator não tenha rank_position preenchido ainda)
        $items = collect($data->items())->map(function ($creator, $index) {
            if ($creator->rank_position === null && $creator->estimated_revenue > 0) {
                // fallback: atribui posição baseada na lista atual
                $creator->rank_position = $index + 1;
            }
            return $creator;
        })->values()->all();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $data->currentPage(),
                'last_page'    => $data->lastPage(),
                'per_page'     => $data->perPage(),
                'total'        => $data->total(),
            ],
        ]);
    }

    /**
     * SEL-217: recalcula rank_position de todos os creators (admin manual trigger).
     * POST /api/v1/admin/ai-creators/recompute-ranks
     */
    public function recomputeRanks()
    {
        // Zero todos os ranks
        AiCreator::where('is_visible', true)
            ->where('is_approved', true)
            ->update(['rank_position' => null]);

        // Atribui 1..N por revenue DESC
        $creators = AiCreator::where('is_visible', true)
            ->where('is_approved', true)
            ->where('estimated_revenue', '>', 0)
            ->orderByDesc('estimated_revenue')
            ->orderByDesc('followers')
            ->get(['id']);

        foreach ($creators as $index => $creator) {
            \DB::table('ai_creators')
                ->where('id', $creator->id)
                ->update(['rank_position' => $index + 1]);
        }

        return response()->json([
            'message' => 'Ranks recalculados',
            'ranked'  => $creators->count(),
        ]);
    }

    // ─── Admin ──────────────────────────────────────────────────

    public function adminIndex(Request $request)
    {
        $q = AiCreator::query();

        if ($request->filled('source')) {
            $q->where('source', $request->input('source'));
        }
        if ($request->filled('visible')) {
            $q->where('is_visible', (bool)(int) $request->input('visible'));
        }
        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $q->where(fn ($s) => $s->where('handle', 'like', $term)->orWhere('name', 'like', $term));
        }

        $q->ranked();
        $perPage = min((int) $request->input('per_page', 25), 100);
        $data = $q->paginate($perPage);

        return response()->json([
            'data' => $data->items(),
            'meta' => [
                'current_page' => $data->currentPage(),
                'last_page'    => $data->lastPage(),
                'per_page'     => $data->perPage(),
                'total'        => $data->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'handle'            => 'required|string|unique:ai_creators,handle',
            'name'              => 'required|string',
            'avatar_url'        => 'nullable|string',
            'bio'               => 'nullable|string',
            'followers'         => 'integer|min:0',
            'videos_count'      => 'integer|min:0',
            'estimated_revenue' => 'numeric|min:0',
            'rank_position'     => 'nullable|integer|min:1',
            'source'            => 'in:tokfy,scrape,manual',
            'is_visible'        => 'boolean',
            'is_approved'       => 'boolean',
            'admin_notes'       => 'nullable|string',
        ]);

        $creator = AiCreator::create($validated);

        return response()->json(['data' => $creator], 201);
    }

    public function update(Request $request, AiCreator $aiCreator)
    {
        $validated = $request->validate([
            'handle'            => 'sometimes|string|unique:ai_creators,handle,' . $aiCreator->id,
            'name'              => 'sometimes|string',
            'avatar_url'        => 'nullable|string',
            'bio'               => 'nullable|string',
            'followers'         => 'sometimes|integer|min:0',
            'videos_count'      => 'sometimes|integer|min:0',
            'estimated_revenue' => 'sometimes|numeric|min:0',
            'rank_position'     => 'nullable|integer|min:1',
            'source'            => 'sometimes|in:tokfy,scrape,manual',
            'is_visible'        => 'sometimes|boolean',
            'is_approved'       => 'sometimes|boolean',
            'admin_notes'       => 'nullable|string',
        ]);

        $aiCreator->update($validated);

        return response()->json(['data' => $aiCreator]);
    }

    public function destroy(AiCreator $aiCreator)
    {
        $aiCreator->delete();

        return response()->json(['message' => 'deleted']);
    }

    /**
     * Regenerar avatar via unavatar.io (cadeia CDN→unavatar→gradiente no frontend).
     * Síncrono: ação individual do admin, não precisa de queue.
     */
    public function regenAvatar(AiCreator $aiCreator)
    {
        $url = 'https://unavatar.io/tiktok/' . $aiCreator->handle;
        $aiCreator->update(['avatar_url' => $url]);

        return response()->json(['data' => $aiCreator, 'avatar_url' => $url]);
    }

    /**
     * SEL-237 — produtos TT Shop que este criador vende (scraped via anchors).
     * Populado por ai-creators:fetch-products (dailyAt 07:30 BRT).
     */
    public function products(AiCreator $aiCreator)
    {
        $rows = \DB::table('ai_creator_products')
            ->where('ai_creator_id', $aiCreator->id)
            ->orderByDesc('sold_count')
            ->orderByDesc('scraped_at')
            ->limit(60)
            ->get([
                'id', 'product_id', 'shop_id', 'title', 'image_url', 'price',
                'currency', 'rating', 'sold_count', 'product_url', 'shop_name',
            ]);

        return response()->json([
            'data' => $rows,
            'creator' => [
                'id' => $aiCreator->id,
                'handle' => $aiCreator->handle,
                'name' => $aiCreator->name,
            ],
        ]);
    }
}
