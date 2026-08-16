<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEL-260 — Admin VSL Manager.
 *
 * Endpoints públicos:
 *   GET /api/v1/vsl?menu={slug}     — retorna VSLs ativas do menu (aleatória ou por sort_order)
 *
 * Endpoints admin (super_admin):
 *   GET    /api/v1/admin/vsl        — lista todas (com soft-deleted)
 *   POST   /api/v1/admin/vsl        — create
 *   PATCH  /api/v1/admin/vsl/{id}   — update
 *   DELETE /api/v1/admin/vsl/{id}   — soft delete
 *
 * Não há upload direto — Ruan faz upload no dash Bunny.net e cola a URL aqui.
 */
class VslController extends Controller
{
    /**
     * GET /api/v1/vsl?menu={slug}
     * Público. Retorna todas as VSLs ativas do menu solicitado,
     * ordenadas por sort_order. Frontend rotaciona client-side.
     */
    public function index(Request $request)
    {
        $menu = $request->query('menu');
        $validMenus = ['tiktok_shopping', 'dropshipping', 'landing'];

        if ($menu && !in_array($menu, $validMenus, true)) {
            return response()->json(['message' => 'menu inválido. Use: ' . implode(', ', $validMenus)], 422);
        }

        $query = DB::table('vsl_configs')
            ->whereNull('deleted_at')
            ->where('active', 1)
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($menu) {
            $query->where('menu_slug', $menu);
        }

        $vsls = $query->get(['id', 'menu_slug', 'video_url', 'thumbnail_url', 'sort_order']);

        return response()->json([
            'menu'  => $menu,
            'count' => $vsls->count(),
            'data'  => $vsls,
        ]);
    }

    /**
     * GET /api/v1/admin/vsl — lista todas (incl. inativas e soft-deleted).
     * super_admin only.
     */
    public function adminIndex()
    {
        $vsls = DB::table('vsl_configs')
            ->orderBy('menu_slug')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['count' => $vsls->count(), 'data' => $vsls]);
    }

    /**
     * POST /api/v1/admin/vsl — cria VSL.
     * super_admin only.
     */
    public function adminCreate(Request $request)
    {
        $data = $request->validate([
            'menu_slug'     => 'required|in:tiktok_shopping,dropshipping,landing',
            'video_url'     => 'required|string|max:2000',
            'thumbnail_url' => 'nullable|string|max:2000',
            'active'        => 'nullable|boolean',
            'sort_order'    => 'nullable|integer|min:0|max:9999',
        ]);

        $id = DB::table('vsl_configs')->insertGetId([
            'menu_slug'     => $data['menu_slug'],
            'video_url'     => $data['video_url'],
            'thumbnail_url' => $data['thumbnail_url'] ?? null,
            'active'        => isset($data['active']) ? (int) $data['active'] : 1,
            'sort_order'    => $data['sort_order'] ?? 0,
            'uploaded_by'   => $request->user()?->id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /**
     * PATCH /api/v1/admin/vsl/{id} — atualiza VSL.
     * super_admin only.
     */
    public function adminUpdate(Request $request, int $id)
    {
        $data = $request->validate([
            'menu_slug'     => 'nullable|in:tiktok_shopping,dropshipping,landing',
            'video_url'     => 'nullable|string|max:2000',
            'thumbnail_url' => 'nullable|string|max:2000',
            'active'        => 'nullable|boolean',
            'sort_order'    => 'nullable|integer|min:0|max:9999',
        ]);

        $vsl = DB::table('vsl_configs')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$vsl) {
            return response()->json(['message' => 'VSL não encontrada'], 404);
        }

        $payload = ['updated_at' => now()];
        foreach (['menu_slug', 'video_url', 'thumbnail_url', 'sort_order'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }
        if (array_key_exists('active', $data)) {
            $payload['active'] = $data['active'] ? 1 : 0;
        }

        DB::table('vsl_configs')->where('id', $id)->update($payload);

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /api/v1/admin/vsl/{id} — soft delete.
     * super_admin only.
     */
    public function adminDelete(int $id)
    {
        $vsl = DB::table('vsl_configs')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$vsl) {
            return response()->json(['message' => 'VSL não encontrada'], 404);
        }

        DB::table('vsl_configs')->where('id', $id)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
