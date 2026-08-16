<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEL-galeria-excluir (09/08, Ruan: "coloca TODOS os vídeos na minha galeria,
 * que eu vou poder SELECIONAR e EXCLUIR o que eu quero").
 *
 * A galeria do Studio (GET /api/v1/ai/generations, AIController::history) já
 * mostra TODOS os vídeos do usuário (gen-* de ai_generations + pipe-* de
 * ai_video_pipelines). Faltava "excluir".
 *
 * NÃO reusa o DELETE /api/v1/affiliate/videos/{id} (AffiliateVideoController) —
 * aquele é outra feature: feed GLOBAL de vídeos de TODOS os usuários pro painel
 * de afiliado, sem checar user_id (qualquer afiliado pode ocultar QUALQUER id da
 * PRÓPRIA visão dele, sem isso ser um bug ali). Reusar aqui deixaria um usuário
 * "excluir" um vídeo que não é dele sem nenhuma validação de dono — errado pra
 * uma galeria pessoal.
 *
 * Mesmo padrão seguro do AffiliateVideoController/VideoFeedController (SEL-405):
 * soft-hide por usuário na tabela `settings`, SEM migration (repo compartilhado
 * por 7 backends), 100% reversível (POST .../restore), nunca apaga o registro
 * nem o arquivo .mp4 (que pode ser referenciado em outro lugar). Diferença
 * chave: aqui a gente CONFIRMA que o item pertence ao usuário antes de ocultar.
 */
class StudioGalleryController extends Controller
{
    /** DELETE /api/v1/studio/gallery/{id} — oculta da galeria do próprio dono. */
    public function hide(Request $request, string $id)
    {
        $userId = (int) ($request->user()?->id ?? 0);
        $chave  = trim($id);

        if ($userId <= 0 || ! self::belongsToUser($chave, $userId)) {
            return response()->json(['message' => 'Vídeo não encontrado na sua galeria.'], 404);
        }

        $ocultos = self::idsOcultos($userId);
        if (! in_array($chave, $ocultos, true)) {
            $ocultos[] = $chave;
            self::salvaOcultos($userId, $ocultos);
        }

        return response()->json(['ok' => true, 'oculto' => $chave, 'total_ocultos' => count($ocultos)]);
    }

    /** POST /api/v1/studio/gallery/{id}/restore — devolve o vídeo pra galeria (reversível). */
    public function restore(Request $request, string $id)
    {
        $userId = (int) ($request->user()?->id ?? 0);
        $chave  = trim($id);
        $ocultos = array_values(array_filter(self::idsOcultos($userId), fn ($x) => $x !== $chave));
        self::salvaOcultos($userId, $ocultos);

        return response()->json(['ok' => true, 'restaurado' => $chave, 'total_ocultos' => count($ocultos)]);
    }

    /** Confere que "gen-123"/"pipe-483" pertence MESMO a este usuário antes de ocultar. */
    public static function belongsToUser(string $chave, int $userId): bool
    {
        if (str_starts_with($chave, 'gen-')) {
            $id = (int) substr($chave, 4);
            return $id > 0 && DB::table('ai_generations')->where('id', $id)->where('user_id', $userId)->exists();
        }
        if (str_starts_with($chave, 'pipe-')) {
            $id = (int) substr($chave, 5);
            return $id > 0 && DB::table('ai_video_pipelines')->where('id', $id)->where('user_id', $userId)->exists();
        }
        return false;
    }

    // ── storage dos ocultos por usuario (settings, sem migration) ──────────────
    private static function chave(int $userId): string
    {
        return 'studio_gallery_hidden:' . $userId;
    }

    /** Usado tambem pelo AIController::history() pra filtrar o que o dono ocultou. */
    public static function idsOcultos(int $userId): array
    {
        try {
            $v = DB::table('settings')->where('key', self::chave($userId))->value('value');
            $d = $v ? json_decode($v, true) : [];
            return is_array($d) ? $d : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function salvaOcultos(int $userId, array $ids): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => self::chave($userId)],
            ['group' => 'studio_gallery', 'value' => json_encode(array_values(array_unique($ids))), 'updated_at' => now()]
        );
    }
}
