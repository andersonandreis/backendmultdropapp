<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEL-505 (Ruan ao vivo, 08/08) — Feedback pos-video: estrelas 1-5 + campo de
 * sugestao ("o que voce quer que a gente crie pra voce vender mais?"). O
 * campo suggestion e o valor real disso tudo: e onde o cliente conta pro
 * Ruan que ideia/modelo de negocio ele quer, sem precisar de nenhum SAC.
 *
 * Tabela dedicada `video_feedbacks` (distinta da `video_feedback` do SEL-361,
 * que so guarda rating great/ok/bad de qualidade do video). Aqui o objetivo
 * nao e QA do video — e minerar ideia de produto/negocio do cliente.
 */
class VideoFeedbackController extends Controller
{
    public function store(Request $request)
    {
        $v = $request->validate([
            'stars'       => 'required|integer|min:1|max:5',
            'suggestion'  => 'nullable|string|max:2000',
            'pipeline_id' => 'nullable|integer',
            'video_url'   => 'nullable|string|max:2000',
        ]);

        DB::table('video_feedbacks')->insert([
            'user_id'     => $request->user()->id,
            'pipeline_id' => $v['pipeline_id'] ?? null,
            'video_url'   => $v['video_url'] ?? null,
            'stars'       => $v['stars'],
            'suggestion'  => $v['suggestion'] ?? null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Admin: lista os feedbacks (mais recentes primeiro) + resumo de
     * estrelas. E aqui que o Ruan le as sugestoes de negocio.
     */
    public function adminIndex(Request $request)
    {
        $perPage = (int) $request->query('per_page', 30);
        $perPage = $perPage > 0 && $perPage <= 100 ? $perPage : 30;

        $query = DB::table('video_feedbacks as vf')
            ->leftJoin('users as u', 'u.id', '=', 'vf.user_id')
            ->select([
                'vf.id',
                'vf.user_id',
                'u.name as user_name',
                'u.email as user_email',
                'vf.pipeline_id',
                'vf.video_url',
                'vf.stars',
                'vf.suggestion',
                'vf.created_at',
            ])
            ->orderByDesc('vf.created_at');

        $paginated = $query->paginate($perPage);

        $total = DB::table('video_feedbacks')->count();
        $avgStars = DB::table('video_feedbacks')->avg('stars');
        $porNota = DB::table('video_feedbacks')
            ->select('stars', DB::raw('count(*) as total'))
            ->groupBy('stars')
            ->orderBy('stars')
            ->get()
            ->pluck('total', 'stars');

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
            'summary' => [
                'total'        => $total,
                'avg_stars'    => $avgStars !== null ? round((float) $avgStars, 2) : null,
                'por_nota'     => [
                    '1' => (int) ($porNota[1] ?? 0),
                    '2' => (int) ($porNota[2] ?? 0),
                    '3' => (int) ($porNota[3] ?? 0),
                    '4' => (int) ($porNota[4] ?? 0),
                    '5' => (int) ($porNota[5] ?? 0),
                ],
            ],
        ]);
    }
}
