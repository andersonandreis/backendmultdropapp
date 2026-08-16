<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * SEL-feedback-video (12/08, Ruan ao vivo) — Ciclo de feedback guiado pós-vídeo.
 *
 * Fluxo: cliente marca 👎 → escolhe motivo pré-definido → responde UMA pergunta
 * de follow-up específica → vira registro roteado automaticamente pro
 * responsável certo (prompt/imagem/audio/duracao) → aparece pro admin
 * filtrado por responsável+status → quando admin marca "consertado" o cliente
 * é avisado (push, mesmo caminho do VideoReadyNotifier) com um botão que
 * dispara o "Refazer" que já existe (POST /studio-options/refazer).
 *
 * Tabela isolada `video_feedback_reports` — ver comentário na migration sobre
 * por que NÃO reusa `video_feedback` (SEL-361) nem `video_feedbacks` (SEL-505).
 */
class VideoFeedbackReportController extends Controller
{
    private const MOTIVOS = [
        'repetiu_frase', 'parou_meio', 'cortou_final',
        'produto_errado', 'audio_ruim', 'tempo_diferente', 'outro',
    ];

    /** Roteamento automático — o cliente nunca escolhe o responsável, o motivo decide. */
    private const RESPONSAVEL_POR_MOTIVO = [
        'repetiu_frase'   => 'prompt',
        'parou_meio'      => 'prompt',
        'cortou_final'    => 'prompt',
        'produto_errado'  => 'imagem',
        'audio_ruim'      => 'audio',
        'tempo_diferente' => 'duracao',
        'outro'           => 'outro',
    ];

    private const STATUSES = ['novo', 'em_conserto', 'consertado', 'avisado'];

    /**
     * POST /api/v1/video-feedback-reports
     * Cliente reporta o defeito depois de responder o motivo guiado.
     */
    public function store(Request $request)
    {
        $v = $request->validate([
            'pipeline_id'        => 'nullable|integer',
            'video_ref'          => 'nullable|string|max:64',
            'motivo'             => 'required|string|in:' . implode(',', self::MOTIVOS),
            'detalhe'            => 'nullable|string|max:500',
            'produto_confirmado' => 'nullable|boolean',
        ]);

        if (empty($v['pipeline_id']) && empty($v['video_ref'])) {
            return response()->json(['message' => 'Não consegui identificar o vídeo.'], 422);
        }

        $user = $request->user();
        $clientId = DB::table('clients')->where('user_id', $user->id)->orderByDesc('id')->value('id');
        $responsavel = self::RESPONSAVEL_POR_MOTIVO[$v['motivo']] ?? 'outro';

        $id = DB::table('video_feedback_reports')->insertGetId([
            'pipeline_id'        => $v['pipeline_id'] ?? null,
            'video_ref'          => $v['video_ref'] ?? null,
            'user_id'            => $user->id,
            'client_id'          => $clientId,
            'motivo'             => $v['motivo'],
            'detalhe'            => $v['detalhe'] ?? null,
            'produto_confirmado' => array_key_exists('produto_confirmado', $v) ? $v['produto_confirmado'] : null,
            'responsavel'        => $responsavel,
            'status'             => 'novo',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        return response()->json([
            'ok'      => true,
            'id'      => $id,
            'message' => 'Recebi. Vou ajustar e te aviso aqui pra você testar.',
        ]);
    }

    /**
     * GET /api/v1/video-feedback-reports/mine
     * Cliente autenticado — usado pela Galeria pra saber se algum vídeo que ele
     * reportou já foi consertado (mostra o banner "testa agora" + Refazer).
     */
    public function mine(Request $request)
    {
        $rows = DB::table('video_feedback_reports')
            ->where('user_id', $request->user()->id)
            ->select(['id', 'pipeline_id', 'video_ref', 'motivo', 'detalhe', 'status', 'responsavel', 'created_at', 'consertado_at', 'avisado_at'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $rows]);
    }

    /**
     * GET /api/v1/admin/video-feedback-reports
     * Admin — lista com filtro por responsavel + status.
     */
    public function adminIndex(Request $request)
    {
        $perPage = (int) $request->query('per_page', 30);
        $perPage = $perPage > 0 && $perPage <= 100 ? $perPage : 30;

        $query = DB::table('video_feedback_reports as vfr')
            ->leftJoin('users as u', 'u.id', '=', 'vfr.user_id')
            ->select([
                'vfr.id', 'vfr.pipeline_id', 'vfr.video_ref', 'vfr.user_id',
                'u.name as user_name', 'u.email as user_email',
                'vfr.motivo', 'vfr.detalhe', 'vfr.produto_confirmado',
                'vfr.responsavel', 'vfr.status', 'vfr.admin_notes',
                'vfr.created_at', 'vfr.consertado_at', 'vfr.avisado_at',
            ])
            ->orderByDesc('vfr.id');

        if ($request->filled('responsavel')) {
            $query->where('vfr.responsavel', $request->query('responsavel'));
        }
        if ($request->filled('status')) {
            $query->where('vfr.status', $request->query('status'));
        }

        $paginated = $query->paginate($perPage);

        $porStatus = DB::table('video_feedback_reports')
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')->pluck('total', 'status');
        $porResponsavel = DB::table('video_feedback_reports')
            ->select('responsavel', DB::raw('count(*) as total'))
            ->groupBy('responsavel')->pluck('total', 'responsavel');

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
            'summary' => [
                'por_status'     => $porStatus,
                'por_responsavel' => $porResponsavel,
            ],
        ]);
    }

    /**
     * PATCH /api/v1/admin/video-feedback-reports/{id}
     * Admin muda status/responsavel/nota. Transição novo|em_conserto -> consertado
     * dispara o aviso pro cliente (push, mesmo caminho do VideoReadyNotifier) e,
     * se o push foi entregue a pelo menos 1 assinatura, já sobe pra "avisado"
     * sozinho — senão fica em "consertado" esperando o admin confirmar por
     * outro canal (mesmo padrão de fallback que o push já tem no resto do app).
     */
    public function adminUpdate(Request $request, int $id)
    {
        $report = DB::table('video_feedback_reports')->where('id', $id)->first();
        if (!$report) {
            return response()->json(['message' => 'Não encontrado'], 404);
        }

        $v = $request->validate([
            'status'      => 'nullable|string|in:' . implode(',', self::STATUSES),
            'responsavel' => 'nullable|string|in:prompt,imagem,audio,duracao,outro',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        $payload = ['updated_at' => now()];
        if (array_key_exists('responsavel', $v) && $v['responsavel']) {
            $payload['responsavel'] = $v['responsavel'];
        }
        if (array_key_exists('admin_notes', $v)) {
            $payload['admin_notes'] = $v['admin_notes'];
        }

        $pushResult = null;
        $novoStatus = $v['status'] ?? null;

        if ($novoStatus && $novoStatus !== $report->status) {
            $payload['status'] = $novoStatus;

            if ($novoStatus === 'consertado' && $report->status !== 'consertado') {
                $payload['consertado_at'] = now();
                $payload['resolved_by'] = $request->user()->id;

                $pushResult = $this->avisarCliente((int) $report->user_id, (int) $report->pipeline_id ?: null, (string) $report->motivo);
                if (($pushResult['sent'] ?? 0) > 0) {
                    $payload['status'] = 'avisado';
                    $payload['avisado_at'] = now();
                }
            }

            if ($novoStatus === 'avisado' && empty($payload['avisado_at'])) {
                $payload['avisado_at'] = now();
            }
        }

        DB::table('video_feedback_reports')->where('id', $id)->update($payload);

        return response()->json([
            'ok'     => true,
            'status' => $payload['status'] ?? $report->status,
            'push'   => $pushResult,
        ]);
    }

    private const MOTIVO_LABEL = [
        'repetiu_frase'   => 'a frase repetida',
        'parou_meio'      => 'o vídeo que parava no meio',
        'cortou_final'    => 'o final cortado',
        'produto_errado'  => 'o produto errado',
        'audio_ruim'      => 'o áudio',
        'tempo_diferente' => 'a duração',
        'outro'           => 'o que você apontou',
    ];

    /** Mesmo caminho de push do VideoReadyNotifier (SEL-050 WebPush), sem tocar
     *  no arquivo compartilhado — só reaproveita o mesmo mecanismo. */
    private function avisarCliente(int $userId, ?int $pipelineId, string $motivo): array
    {
        try {
            if (!config('services.vapid.public') || !config('services.vapid.private')) {
                return ['sent' => 0, 'failed' => 0, 'reason' => 'vapid_missing'];
            }
            $subs = DB::table('push_subscriptions')->where('user_id', $userId)->get();
            if ($subs->isEmpty()) {
                return ['sent' => 0, 'failed' => 0, 'reason' => 'no_subscriptions'];
            }

            $wp = new WebPush([
                'VAPID' => [
                    'subject'    => config('services.vapid.subject'),
                    'publicKey'  => config('services.vapid.public'),
                    'privateKey' => config('services.vapid.private'),
                ],
            ], [], 10);
            $wp->setReuseVAPIDHeaders(true);

            $label = self::MOTIVO_LABEL[$motivo] ?? 'o que você apontou';
            $url = $pipelineId ? "/estudio?tab=galeria&video=pipe-{$pipelineId}" : '/estudio?tab=galeria';
            $payload = json_encode([
                'title' => '🔧 Corrigimos seu vídeo!',
                'body'  => "Arrumamos {$label} — testa agora.",
                'url'   => $url,
            ], JSON_UNESCAPED_UNICODE);

            foreach ($subs as $s) {
                $wp->queueNotification(
                    Subscription::create(['endpoint' => $s->endpoint, 'keys' => ['p256dh' => $s->p256dh, 'auth' => $s->auth]]),
                    $payload
                );
            }

            $sent = 0;
            $failed = 0;
            foreach ($wp->flush(25) as $report) {
                if ($report->isSuccess()) {
                    $sent++;
                    continue;
                }
                $failed++;
                $status = optional($report->getResponse())->getStatusCode();
                if (in_array($status, [403, 404, 410], true)) {
                    DB::table('push_subscriptions')->where('endpoint_hash', hash('sha256', $report->getEndpoint()))->delete();
                }
            }

            return ['sent' => $sent, 'failed' => $failed];
        } catch (\Throwable $e) {
            Log::warning('[VideoFeedbackReportController] push falhou (não-fatal)', ['user' => $userId, 'err' => $e->getMessage()]);
            return ['sent' => 0, 'failed' => 0, 'reason' => 'exception'];
        }
    }
}
