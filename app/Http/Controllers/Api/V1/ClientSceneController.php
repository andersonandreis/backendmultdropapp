<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEL-MEUS-CENARIOS (14/08) — o acervo de cenários DO cliente.
 *
 * Pedido nascido na live: hoje o cliente escolhe de uma grade fixa ou escreve o
 * cenário na hora, e o que ele escreveu morre ali. Agora ele guarda: sobe a foto
 * do cenário dele (a loja, a bancada, o quarto real) ou salva a descrição que
 * funcionou, e reusa na próxima sem redigitar.
 *
 * Guarda-corpo: cada cliente só enxerga e só apaga o que é dele — o `user_id`
 * entra em TODA consulta, nunca vem do corpo da requisição.
 */
class ClientSceneController extends Controller
{
    public function index(Request $request)
    {
        $cenarios = DB::table('client_video_scenes')
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->limit(60)
            ->get(['id', 'image_url', 'prompt', 'label', 'source', 'created_at']);

        return response()->json(['scenes' => $cenarios]);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'image_url' => 'nullable|url|max:512',
            'prompt'    => 'nullable|string|max:600',
            'label'     => 'nullable|string|max:120',
            'source'    => 'nullable|in:upload,escrito,gerado',
        ]);

        if (empty($v['image_url']) && empty(trim((string) ($v['prompt'] ?? '')))) {
            return response()->json(['message' => 'Manda a foto do cenário ou a descrição dele.'], 422);
        }

        // não duplica o mesmo cenário do mesmo cliente
        $jaTem = DB::table('client_video_scenes')
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->when(! empty($v['image_url']), fn ($q) => $q->where('image_url', $v['image_url']))
            ->when(empty($v['image_url']), fn ($q) => $q->where('prompt', $v['prompt'] ?? ''))
            ->first(['id']);

        if ($jaTem) {
            return response()->json(['id' => $jaTem->id, 'ja_existia' => true]);
        }

        $rotulo = trim((string) ($v['label'] ?? '')) ?: (
            ! empty($v['prompt'])
                ? mb_substr(trim($v['prompt']), 0, 60)
                : 'Cenário meu'
        );

        $id = DB::table('client_video_scenes')->insertGetId([
            'user_id'    => $request->user()->id,
            'image_url'  => $v['image_url'] ?? null,
            'prompt'     => $v['prompt'] ?? null,
            'label'      => $rotulo,
            'source'     => $v['source'] ?? (! empty($v['image_url']) ? 'upload' : 'escrito'),
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $n = DB::table('client_video_scenes')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)   // dono confere
            ->update(['is_active' => false, 'updated_at' => now()]);

        return response()->json(['ok' => $n > 0]);
    }
}
