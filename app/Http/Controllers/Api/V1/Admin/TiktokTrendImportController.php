<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-122 backend Ruan 16/07: import automatico de perfis de criadores TikTok.
 *
 * Complementa o SEL-161 (front chama /api/user/info da tikwm pra preview).
 * Este endpoint faz o upsert em tiktok_shop_trends (kind='creator') pra que o
 * criador aparece na listagem publica sem depender do coletor Playwright.
 *
 * Endpoint: POST /api/v1/admin/tiktok-shop-trends/import-creator-profile
 * Body:     { "handle": "@algumcriador" ou "algumcriador" }
 * Auth:     auth:sanctum + role:admin,super_admin
 *
 * Fluxo:
 *  1. Normaliza handle (remove @ prefix)
 *  2. Chama https://tikwm.com/api/user/info?unique_id=@{handle}
 *  3. Extrai nickname, avatar, follower_cnt, categorias (best-effort do raw)
 *  4. UPSERT em tiktok_shop_trends (source='tiktok_shop', kind='creator',
 *     external_id=handle, title=nickname, raw=JSON completo)
 *  5. Retorna o registro criado/updated + payload bruto pra debug
 */
class TiktokTrendImportController extends Controller
{
    private const TIKWM_URL = 'https://tikwm.com/api/user/info';

    public function importCreatorProfile(Request $r)
    {
        $v = $r->validate([
            'handle' => 'required|string|max:120',
        ]);

        $handle = ltrim(trim($v['handle']), '@');
        if ($handle === '') {
            return response()->json(['ok' => false, 'message' => 'handle vazio'], 422);
        }

        try {
            $resp = Http::timeout(15)
                ->acceptJson()
                ->get(self::TIKWM_URL, ['unique_id' => '@' . $handle]);
        } catch (\Throwable $e) {
            Log::warning('[SEL-122BE ImportCreator] tikwm exception', [
                'handle'  => $handle,
                'error'   => $e->getMessage(),
            ]);
            return response()->json([
                'ok'      => false,
                'message' => 'Falha ao consultar tikwm: ' . $e->getMessage(),
            ], 502);
        }

        if (!$resp->ok()) {
            return response()->json([
                'ok'      => false,
                'message' => 'tikwm HTTP ' . $resp->status(),
                'status'  => $resp->status(),
            ], 502);
        }

        $json = $resp->json();
        // tikwm envelopa em { code:0, data: { user: {...}, stats: {...} } }
        $user  = data_get($json, 'data.user');
        $stats = data_get($json, 'data.stats');
        if (!is_array($user)) {
            // Alguns retornos jogam direto em data.
            $user  = data_get($json, 'data') ?? null;
            $stats = data_get($json, 'data.stats') ?? [];
        }

        if (!is_array($user) || empty($user)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Criador nao encontrado ou tikwm sem payload',
                'raw'     => $json,
            ], 404);
        }

        $nickname   = (string) ($user['nickname'] ?? $user['uniqueId'] ?? $handle);
        $avatar     = $user['avatarLarger'] ?? $user['avatarMedium'] ?? $user['avatarThumb'] ?? null;
        $followerCnt = (int) (data_get($stats, 'followerCount', 0)
                        ?: data_get($user, 'followerCount', 0));
        $heartCnt   = (int) (data_get($stats, 'heartCount', 0)
                        ?: data_get($user, 'heartCount', 0));
        $videoCnt   = (int) (data_get($stats, 'videoCount', 0)
                        ?: data_get($user, 'videoCount', 0));

        // Categorias: tikwm nao entrega categorias diretas — melhor esforco
        // pega os video hashtags ou signature.
        $signature = (string) ($user['signature'] ?? '');
        $categoryL1 = null;
        // Extrai a primeira hashtag da signature como pista de categoria
        if (preg_match('/#(\w+)/u', $signature, $tag)) {
            $categoryL1 = mb_substr($tag[1], 0, 118);
        }

        $rawPayload = [
            'handle'         => $handle,
            'nickname'       => $nickname,
            'avatar_url'     => $avatar,
            'follower_count' => $followerCnt,
            'heart_count'    => $heartCnt,
            'video_count'    => $videoCnt,
            'signature'      => $signature,
            'imported_at'    => now()->toIso8601String(),
            'tikwm_user'     => $user,
            'tikwm_stats'    => $stats,
        ];

        $now = now();
        $data = [
            'kind'           => 'creator',
            'source'         => 'tiktok_shop',
            'source_url'     => 'https://www.tiktok.com/@' . $handle,
            'external_id'    => $handle,
            // SEL-175BE: coluna dedicada `handle` (indexada) — evita percorrer
            // JSON raw pra fazer lookup por handle. Backfill via migration
            // 2026_07_16_150000_sel175be_add_handle_to_tiktok_trends.
            'handle'         => mb_substr($handle, 0, 80),
            'title'          => mb_substr($nickname, 0, 240),
            'images'         => $avatar ? json_encode([$avatar]) : null,
            'category_l1'    => $categoryL1,
            'search_volume'  => (string) $followerCnt,
            'raw'            => json_encode($rawPayload, JSON_UNESCAPED_UNICODE),
            'captured_at'    => $now,
            'updated_at'     => $now,
        ];

        $existing = DB::table('tiktok_shop_trends')
            ->where('source', 'tiktok_shop')
            ->where('kind', 'creator')
            ->where('external_id', $handle)
            ->first();

        if ($existing) {
            DB::table('tiktok_shop_trends')->where('id', $existing->id)->update($data);
            $id = $existing->id;
            $action = 'updated';
        } else {
            $data['created_at'] = $now;
            $id = DB::table('tiktok_shop_trends')->insertGetId($data);
            $action = 'created';
        }

        $row = DB::table('tiktok_shop_trends')->where('id', $id)->first();

        Log::info('[SEL-122BE ImportCreator] creator sync', [
            'handle'   => $handle,
            'action'   => $action,
            'trend_id' => $id,
            'follower' => $followerCnt,
        ]);

        return response()->json([
            'ok'      => true,
            'action'  => $action,
            'trend'   => $row,
            'source'  => 'tikwm',
        ]);
    }
}
