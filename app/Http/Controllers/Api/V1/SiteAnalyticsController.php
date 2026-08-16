<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SiteAnalyticsEvent;
use App\Models\SiteSessionRecording;
use App\Models\SiteVisitorCampaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * INF-030 (Ruan 12/08, ampliação) — coleta própria e leve do que o Matomo não
 * cobre: % assistido do VSL + play/pause, posição de clique e mouse (mapa de
 * calor por VISITANTE, não só global), pageview com tempo/scroll por página
 * (pro mapa da jornada), campanha completa no primeiro toque (utm/fbclid/
 * gclid/ttclid — o Matomo agrega mas não guarda o elo por visitante pra
 * cruzar com quem comprou) e gravação de sessão (rrweb).
 *
 * Rotas públicas, sem auth (mesmo padrão de /tracking/capi), rate-limitadas.
 * Fire-and-forget: nunca deve derrubar a página do visitante — qualquer erro
 * é engolido e logado, resposta sempre 204/202.
 */
class SiteAnalyticsController extends Controller
{
    private const EVENT_TYPES = [
        'click', 'pageview', 'pageview_end', 'video_progress', 'video_play', 'video_pause', 'mousemove_batch',
    ];

    // POST /api/track/event
    public function track(Request $request)
    {
        try {
            $data = $request->validate([
                'event_type'  => ['required', 'string', 'in:' . implode(',', self::EVENT_TYPES)],
                'page_url'    => 'required|string|max:500',
                'page_title'  => 'nullable|string|max:255',
                'visitor_uid' => 'nullable|string|max:64',
                'video_id'    => 'nullable|string|max:64',
                'video_pct'   => 'nullable|integer|min:0|max:100',
                'x_pct'       => 'nullable|numeric|min:0|max:100',
                'y_pct'       => 'nullable|numeric|min:0|max:100',
                'el_selector' => 'nullable|string|max:255',
                'el_text'     => 'nullable|string|max:120',
                // meta carrega os campos que não valem coluna própria:
                // pageview_end -> {duration_sec, scroll_max_pct, entered_at}
                // pageview     -> {referrer}
                // mousemove_batch -> {points: [{x,y}, ...]} (capado no client, ~60 pontos)
                'meta'        => 'nullable|array',
                'meta.points' => 'nullable|array|max:80',
            ]);

            // SEL-VISITANTES-2 (Ruan 12/08) — BUG ENCONTRADO E CORRIGIDO AQUI.
            // Ter a regra aninhada 'meta.points' faz o validate() do Laravel
            // devolver, dentro de 'meta', SOMENTE as subchaves declaradas. Ou
            // seja: duration_sec, scroll_max_pct e referrer eram jogados fora
            // silenciosamente e meta gravava NULL. Medido no banco em 12/08:
            // meta NULL em 1014/1014 clicks, 124/124 pageviews e 111/111
            // pageview_ends — e meta OK em 280/280 mousemove_batch (o unico
            // que usa 'points', a subchave declarada). Resultado pratico: o
            // mapa da jornada mostrava 0s em todo mundo e qualquer analise de
            // tempo/rolagem/quique dava 100% de saida em 3s, o que era numero
            // fabricado pelo bug, nao comportamento do visitante.
            // Correcao: reanexa o meta do request com whitelist de chaves
            // (nada de campo arbitrario entrando no banco) e teto nos pontos.
            $metaBruto = $request->input('meta');
            $meta = null;
            if (is_array($metaBruto)) {
                $meta = array_intersect_key($metaBruto, array_flip([
                    'duration_sec', 'scroll_max_pct', 'entered_at', 'referrer', 'points',
                ]));
                if (isset($meta['points']) && is_array($meta['points'])) {
                    $meta['points'] = array_slice($meta['points'], 0, 80);
                }
                $meta = $meta ?: null;
            }
            $data['meta'] = $meta;

            SiteAnalyticsEvent::create([
                ...$data,
                'ip'         => (string) $request->ip(),
                'user_agent' => (string) substr((string) $request->userAgent(), 0, 1000),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('single')->warning('site_analytics_events: track failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return response()->noContent();
    }

    // POST /api/track/campaign — 1x por visitante (first-touch), nunca sobrescreve.
    public function campaign(Request $request)
    {
        try {
            $data = $request->validate([
                'visitor_uid'  => 'required|string|max:64',
                'utm_source'   => 'nullable|string|max:100',
                'utm_medium'   => 'nullable|string|max:100',
                'utm_campaign' => 'nullable|string|max:150',
                'utm_content'  => 'nullable|string|max:150',
                'utm_term'     => 'nullable|string|max:150',
                'utm_id'       => 'nullable|string|max:100',
                'fbclid'       => 'nullable|string|max:255',
                'gclid'        => 'nullable|string|max:255',
                'ttclid'       => 'nullable|string|max:255',
                'referrer'     => 'nullable|string|max:500',
                'landing_page' => 'nullable|string|max:500',
            ]);

            $visitorUid = $data['visitor_uid'];
            unset($data['visitor_uid']);

            // firstOrCreate: se já existe (mesmo visitante voltou com outro
            // clique de ads), NÃO sobrescreve — first-touch attribution é o
            // padrão de mercado e é o que o Ruan pediu implicitamente ("qual
            // campanha trouxe esse visitante", não "qual foi a última").
            SiteVisitorCampaign::firstOrCreate(
                ['visitor_uid' => $visitorUid],
                [...$data, 'ip' => (string) $request->ip(), 'created_at' => now()],
            );
        } catch (\Throwable $e) {
            Log::channel('single')->warning('site_visitor_campaigns: capture failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return response()->noContent();
    }

    // POST /api/track/recording — 1 chunk de gravação rrweb (ver SiteSessionRecording).
    public function recording(Request $request)
    {
        try {
            $data = $request->validate([
                'session_id'    => 'required|string|max:64',
                'visitor_uid'   => 'nullable|string|max:64',
                'seq'           => 'required|integer|min:0',
                'page_url'      => 'nullable|string|max:500',
                'is_gzip'       => 'required|boolean',
                'events_count'  => 'required|integer|min:0|max:5000',
                // base64 de até ~3MB comprimido por chunk — guarda-corpo pra
                // não deixar um client malicioso/quebrado encher o disco
                // numa tacada só (servidor já está com disco em 75%).
                'payload_b64'   => 'required|string|max:4000000',
            ]);

            SiteSessionRecording::firstOrCreate(
                ['session_id' => $data['session_id'], 'seq' => $data['seq']],
                [
                    'visitor_uid'  => $data['visitor_uid'] ?? null,
                    'page_url'     => $data['page_url'] ?? null,
                    'is_gzip'      => $data['is_gzip'],
                    'events_count' => $data['events_count'],
                    'raw_bytes'    => strlen($data['payload_b64']),
                    'payload_b64'  => $data['payload_b64'],
                    'created_at'   => now(),
                ],
            );
        } catch (\Throwable $e) {
            Log::channel('single')->warning('site_session_recordings: chunk failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return response()->noContent();
    }
}
