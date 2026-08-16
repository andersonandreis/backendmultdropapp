<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SiteAnalyticsEvent;
use App\Models\SiteSessionRecording;
use App\Models\SiteVisitorCampaign;
use DeviceDetector\DeviceDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * INF-030 (Ruan 12/08) — "quero tudo na minha tela ADM: ip, localizacao,
 * onde clicou, quanto tempo, se assistiu o video, dispositivo, etc — sem
 * precisar abrir nenhuma plataforma." Ampliado no mesmo dia: campanha com
 * número/ID do anúncio, mapa da jornada, mapa de calor POR visitante
 * (clique + movimento do mouse), gravação de sessão (rrweb).
 *
 * Fonte primaria: Matomo self-hosted (track.hubai.club, site_id=2, nosso
 * servidor, INF-080) via Reporting API — IP, geo, device/browser/OS, paginas
 * visitadas com tempo em cada uma, referrer/UTM, ultimo acesso. O Matomo NAO
 * tem (sem plugin pago, que o Ruan proibiu): % assistido de video, posicao de
 * clique/mouse, campanha por visitante correlável com quem comprou, e
 * gravação de tela — isso vem das tabelas próprias (site_analytics_events,
 * site_visitor_campaigns, site_session_recordings), correlacionadas pelo
 * visitorId nativo do Matomo quando disponível.
 *
 * Marca/modelo de aparelho: matomo/device-detector (composer, mesma engine
 * que o Matomo/Piwik usa por baixo) rodando em cima do user_agent que a
 * NOSSA coleta já grava — funciona mesmo quando o visitante não aparece no
 * Matomo (adblock) e dá brand+model que o Live API do Matomo não expõe.
 *
 * MAC address do dispositivo: IMPOSSIVEL de obter via navegador (bloqueado por
 * design em todos os browsers modernos, nenhum site consegue, nao e limitacao
 * nossa). Substituto entregue aqui: IP + device fingerprint (browser/OS/tela/
 * device type/brand/model), que e o maximo que da pra ter sem instalar app nativo.
 *
 * Mantido fora do AdminController (82KB) de proposito — mesmo padrao do
 * AdminBillingController.
 */
class AdminAnalyticsController extends Controller
{
    private function requireSuperAdmin(Request $request): void
    {
        if ($request->user()?->role !== 'super_admin') {
            abort(403, 'Acesso restrito a super_admin.');
        }
    }

    /** POST a Matomo Reporting API. Retorna null (nunca lanca) se o Matomo estiver fora. */
    private function matomoApi(string $method, array $params = []): ?array
    {
        $token = (string) config('services.matomo.token');
        if ($token === '') {
            return null;
        }

        try {
            $res = Http::asForm()->timeout(8)->post(rtrim((string) config('services.matomo.url'), '/') . '/index.php', array_merge([
                'module'     => 'API',
                'method'     => $method,
                'idSite'     => config('services.matomo.site_id'),
                'format'     => 'json',
                'token_auth' => $token,
            ], $params));

            if (! $res->ok()) {
                Log::channel('single')->warning('AdminAnalytics: matomo nao-ok', ['method' => $method, 'status' => $res->status()]);
                return null;
            }

            $json = $res->json();
            if (! is_array($json) || (isset($json['result']) && $json['result'] === 'error')) {
                Log::channel('single')->warning('AdminAnalytics: matomo error payload', ['method' => $method, 'body' => $json]);
                return null;
            }

            return $json;
        } catch (\Throwable $e) {
            Log::channel('single')->warning('AdminAnalytics: matomo exception', ['method' => $method, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** brand/model/device via matomo/device-detector a partir do user_agent que a NOSSA coleta gravou. */
    private function deviceDetails(?string $userAgent): array
    {
        if (! $userAgent) {
            return ['brand' => null, 'model' => null, 'device_type' => null, 'client' => null];
        }
        try {
            $dd = new DeviceDetector($userAgent);
            $dd->parse();

            return [
                'brand'       => $dd->getBrandName() ?: null,
                'model'       => $dd->getModel() ?: null,
                'device_type' => $dd->getDeviceName() ?: null, // smartphone|tablet|desktop|tv|...
                'client'      => $dd->getClient('name') ?: null,
            ];
        } catch (\Throwable $e) {
            return ['brand' => null, 'model' => null, 'device_type' => null, 'client' => null];
        }
    }

    /** Nome curto da origem pra escolher o logo, a partir do referrer_name do Matomo quando não há campanha própria. */
    private function sourceKeyFromMatomo(?string $referrerType, ?string $referrerName): ?string
    {
        $t = strtolower((string) $referrerType);
        $n = strtolower((string) $referrerName);
        if (str_contains($n, 'facebook')) return 'facebook';
        if (str_contains($n, 'instagram')) return 'instagram';
        if (str_contains($n, 'google')) return 'google';
        if (str_contains($n, 'tiktok')) return 'tiktok';
        if (str_contains($n, 'whatsapp')) return 'whatsapp';
        if (str_contains($n, 'youtube')) return 'youtube';
        if ($t === 'direct') return 'direct';
        return $referrerName ? 'other' : null;
    }

    // GET /api/v1/admin/analytics/overview
    public function overview(Request $request)
    {
        $this->requireSuperAdmin($request);

        return response()->json(Cache::remember('sg_admin_analytics_overview', 60, function () {
            $today = $this->matomoApi('VisitsSummary.get', ['period' => 'day', 'date' => 'today']);
            // SEL-analytics-periodo (12/08): 'range' devolvia 8 visitas com 27 SO hoje
            // (impossivel). period=day devolve a serie por dia — somamos abaixo.
            $serie7  = $this->matomoApi('VisitsSummary.get', ['period' => 'day', 'date' => 'last7']);
            $serie30 = $this->matomoApi('VisitsSummary.get', ['period' => 'day', 'date' => 'last30']);
            $somaSerie = function ($serie, string $metrica): int {
                if (! is_array($serie)) return 0;
                $t = 0;
                foreach ($serie as $dia) { if (is_array($dia)) $t += (int) ($dia[$metrica] ?? 0); }
                return $t;
            };
            $last7 = $this->matomoApi('VisitsSummary.get', ['period' => 'range', 'date' => 'last7']);
            $obrigado7 = $this->matomoApi('Actions.getPageUrl', ['pageUrl' => '/obrigado', 'period' => 'range', 'date' => 'last7']);

            $matomoOk = $today !== null;

            $since = now()->subDays(7);
            $watchedVisitors = SiteAnalyticsEvent::where('created_at', '>=', $since)
                ->where('event_type', 'video_progress')
                ->whereNotNull('visitor_uid')
                ->distinct('visitor_uid')
                ->count('visitor_uid');

            /**
             * SEL-PAINEL3 (Ruan 13/08) — "assistiram o vídeo? Aí tá cheio de
             * erro." O card mostrava "0%" como se fosse VERDADE MEDIDA. Não é:
             *
             *  1. o sensor de vídeo só subiu em 12/08 — antes disso não existia
             *     nada pra medir, então 0 não significa "ninguém assistiu";
             *  2. o player do Bunny não emite os eventos do Player.js (a conta
             *     precisa habilitar), então `video_progress` (25/50/75/100%)
             *     NUNCA chega — o denominador não existe;
             *  3. os únicos `video_play` que chegam são do vídeo decorativo da
             *     home (video_id 'hero:…', autoplay+loop+muted) — máquina, não
             *     gente escolhendo assistir.
             *
             * Então: 0 vira NULL (= "sem medição"), e vai junto o porquê. A tela
             * mostra a explicação em vez de um zero que mente.
             */
            $playsReais = SiteAnalyticsEvent::where('created_at', '>=', $since)
                ->where('event_type', 'video_play')
                ->where(function ($q) {
                    $q->whereNull('video_id')->orWhere('video_id', 'NOT LIKE', 'hero:%');
                })
                ->distinct('visitor_uid')->count('visitor_uid');
            $playsHero = SiteAnalyticsEvent::where('created_at', '>=', $since)
                ->where('event_type', 'video_play')
                ->where('video_id', 'LIKE', 'hero:%')
                ->distinct('visitor_uid')->count('visitor_uid');
            $progressoTotal = SiteAnalyticsEvent::where('created_at', '>=', $since)
                ->where('event_type', 'video_progress')->count();
            $primeiroEventoVideo = SiteAnalyticsEvent::whereIn('event_type', ['video_play', 'video_progress'])
                ->min('created_at');

            $videoMedindo = $progressoTotal > 0;
            $videoTracking = [
                'medindo'          => $videoMedindo,
                'sensor_desde'     => $primeiroEventoVideo ? (string) $primeiroEventoVideo : null,
                'plays_reais_7d'   => $playsReais,
                'plays_hero_7d'    => $playsHero,
                'progresso_7d'     => $progressoTotal,
                'motivo'           => $videoMedindo
                    ? null
                    : 'O player do Bunny não devolve os eventos de progresso (25/50/75/100%) — a conta precisa habilitar o Player.js/eventos na biblioteca 723099. Sem esses eventos não existe percentual assistido pra medir: o que aparecer aqui como 0 seria invenção, não medição.',
            ];

            $obrigadoVisits = 0;
            if (is_array($obrigado7)) {
                // Actions.getPageUrl retorna um array (as vezes aninhado em 1 nivel) com nb_visits
                $flat = $obrigado7[0] ?? $obrigado7;
                $obrigadoVisits = (int) ($flat['nb_visits'] ?? 0);
            }

            /**
             * SEL-PAINEL3 (13/08) — NÚMERO MENTINDO, conferido no banco.
             *
             * O card dizia "3 chegaram no obrigado · 0,56%". Os dois números
             * estavam errados, por motivos diferentes:
             *
             *  - o "3" vinha do Matomo (Actions.getPageUrl.nb_visits), que conta
             *    VISITA DE PÁGINA, não pessoa;
             *  - o "0,56%" dividia esse 3 por 531, que é nb_visits = SESSÃO do
             *    site inteiro. Numerador e denominador de universos diferentes.
             *
             * Medido no banco (últimos 7 dias):
             *    eventos em /obrigado ....... 14  (TODOS event_type=click)
             *    pessoas distintas .......... 2
             *    pageviews em /obrigado ..... 0   <- nenhum, nunca
             *    visitantes distintos 7d .... 142
             *    conversão real ............. 2/142 = 1,41%
             *
             * Agora a conta é: PESSOAS distintas que estiveram em /obrigado
             * dividido por PESSOAS distintas do site, na MESMA janela e na MESMA
             * tabela — dá pra conferir na mão contra o banco.
             *
             * Por que "esteve em /obrigado" e não "deu pageview em /obrigado":
             * porque pageview nessa rota nunca chega (0 em toda a base), embora
             * clique chegue. Contar por pageview zeraria o card e o drilldown —
             * era exatamente por isso que "Converteram" dava 0 em toda lista.
             * Qualquer evento rastreado na página prova que a pessoa esteve nela.
             */
            $eventoNoObrigado = SiteAnalyticsEvent::where('created_at', '>=', $since)
                ->whereRaw($this->sqlPath() . ' = ?', ['/obrigado']);
            $converteramPessoas = (clone $eventoNoObrigado)->whereNotNull('visitor_uid')
                ->distinct('visitor_uid')->count('visitor_uid');
            $pessoasNoPeriodo = SiteAnalyticsEvent::where('created_at', '>=', $since)
                ->whereNotNull('visitor_uid')->distinct('visitor_uid')->count('visitor_uid');
            $pessoas30 = SiteAnalyticsEvent::where('created_at', '>=', now()->subDays(30))
                ->whereNotNull('visitor_uid')->distinct('visitor_uid')->count('visitor_uid');

            // IMPORTANTE: nb_uniq_visitors NAO existe pra period=range nessa instancia
            // Matomo (metrica desabilitada por padrao pra ranges arbitrarios — testado
            // 12/08, erro "metric nb_uniq_visitors is not enabled for the requested
            // period"). So period=day tem unique visitors exato. Pra 7d/30d usamos
            // nb_visits (sessoes, nao visitantes unicos) — rotulado assim no frontend
            // pra nao prometer um numero que o Matomo nao entrega sem mudar config dele.
            $visits7  = $somaSerie($serie7, 'nb_visits');
            $visits30 = $somaSerie($serie30, 'nb_visits');
            $uniq7    = $somaSerie($serie7, 'nb_uniq_visitors');
            $uniq30   = $somaSerie($serie30, 'nb_uniq_visitors');

            /**
             * SEL-PAINEL3 (13/08): o card dizia "Campanhas ativas (7d): 1" e o
             * painel logo abaixo dizia "Campanhas: 4" — na MESMA tela. Não era
             * bug de conta: eram duas definições diferentes (7d × 30d, e só
             * utm_source preenchido × toda origem agrupada). Pro dono isso lê
             * como erro. Agora o card usa a MESMA definição e a MESMA janela do
             * painel: par origem+campanha nos últimos 30 dias.
             */
            $campanhas30 = SiteVisitorCampaign::where('created_at', '>=', now()->subDays(30))->get();
            $campaigns30 = $campanhas30
                ->map(fn ($r) => $r->sourceKey() . '|' . ($r->utm_campaign ?: ($r->fbclid ? '(sem utm_campaign — veio com fbclid)' : '(tráfego sem campanha)')))
                ->unique()->count();
            $campaigns7 = SiteVisitorCampaign::where('created_at', '>=', $since)
                ->whereNotNull('utm_source')
                ->distinct('utm_campaign')
                ->count('utm_campaign');

            $recordings7 = SiteSessionRecording::where('created_at', '>=', $since)->distinct('session_id')->count('session_id');

            // "dois visitantes hoje — visitou aonde? visitou o quê?": o número do
            // Matomo não abre lista nominal. Este, das NOSSAS tabelas, abre.
            $visitantesHojeProprio = SiteAnalyticsEvent::whereDate('created_at', now()->toDateString())
                ->whereNotNull('visitor_uid')->distinct('visitor_uid')->count('visitor_uid');

            return [
                'matomo_ok'             => $matomoOk,
                'visitors_today'        => (int) ($today['nb_uniq_visitors'] ?? 0),
                'visits_today'          => (int) ($today['nb_visits'] ?? 0),
                'visits_7d'             => $visits7,
                'visits_30d'            => $visits30,
                'uniq_visitors_7d'      => $uniq7,
                'uniq_visitors_30d'     => $uniq30,
                'avg_visit_duration_7d' => (int) ($last7['avg_time_on_site'] ?? 0),
                'bounce_rate_7d'        => $last7['bounce_rate'] ?? null,
                'video_watched_count_7d' => $watchedVisitors,
                // null = SEM MEDIÇÃO (a tela escreve isso por extenso). Só vira
                // número quando o progresso do player realmente chegar.
                'video_watched_pct_7d'  => ($videoMedindo && $visits7 > 0)
                    ? round(min(100, $watchedVisitors / $visits7 * 100), 1)
                    : null,
                'video_tracking'        => $videoTracking,
                // pessoas (nossa tabela) — é o que o card mostra e o que abre lista
                'converted_people_7d'   => $converteramPessoas,
                'people_7d'             => $pessoasNoPeriodo,
                'people_30d'            => $pessoas30,
                'conversion_pct_7d'     => $pessoasNoPeriodo > 0
                    ? round(min(100, $converteramPessoas / $pessoasNoPeriodo * 100), 2)
                    : null,
                // número do Matomo mantido só como referência auditável — NÃO
                // entra em conta nenhuma (conta visita de página, não pessoa).
                'obrigado_visits_7d'    => $obrigadoVisits,
                'campaigns_7d'          => $campaigns7,
                'campaigns_30d'         => $campaigns30,
                'recordings_7d'         => $recordings7,
                'visitors_today_own'    => $visitantesHojeProprio,
            ];
        }));
    }

    // GET /api/v1/admin/analytics/visitors?limit=&offset=&days=
    public function visitors(Request $request)
    {
        $this->requireSuperAdmin($request);

        $limit = min((int) $request->query('limit', 50), 200);
        $offset = max((int) $request->query('offset', 0), 0);
        $days = min(max((int) $request->query('days', 30), 1), 90);

        $raw = $this->matomoApi('Live.getLastVisitsDetails', [
            'period'        => 'range',
            'date'          => 'last' . $days,
            'filter_limit'  => $limit,
            'filter_offset' => $offset,
        ]);

        if ($raw === null) {
            return response()->json(['matomo_ok' => false, 'data' => [], 'limit' => $limit, 'offset' => $offset]);
        }

        $visitorIds = collect($raw)->pluck('visitorId')->filter()->unique()->values()->all();
        $ownByVisitor = SiteAnalyticsEvent::whereIn('visitor_uid', $visitorIds)->get()->groupBy('visitor_uid');
        $campaignByVisitor = SiteVisitorCampaign::whereIn('visitor_uid', $visitorIds)->get()->keyBy('visitor_uid');
        $recordingsByVisitor = SiteSessionRecording::whereIn('visitor_uid', $visitorIds)
            ->selectRaw('visitor_uid, COUNT(DISTINCT session_id) as n')
            ->groupBy('visitor_uid')
            ->pluck('n', 'visitor_uid');

        $data = collect($raw)->map(function (array $v) use ($ownByVisitor, $campaignByVisitor, $recordingsByVisitor) {
            $own = $ownByVisitor->get($v['visitorId'] ?? null, collect());
            $maxPct = $own->where('event_type', 'video_progress')->max('video_pct');
            $clicks = $own->where('event_type', 'click')->count();
            $lastUserAgent = $own->sortByDesc('id')->first()->user_agent ?? null;
            $device = $this->deviceDetails($lastUserAgent);

            $campaign = $campaignByVisitor->get($v['visitorId'] ?? null);
            $sourceKey = $campaign ? $campaign->sourceKey() : $this->sourceKeyFromMatomo($v['referrerTypeName'] ?? null, $v['referrerName'] ?? null);

            $pages = collect($v['actionDetails'] ?? [])
                ->filter(fn ($a) => ($a['type'] ?? '') === 'action')
                ->map(fn ($a) => [
                    'url'           => $a['url'] ?? null,
                    'title'         => $a['pageTitle'] ?? null,
                    'time_spent_sec' => $a['timeSpent'] ?? 0,
                ])
                ->values();

            return [
                'visitor_id'      => $v['visitorId'] ?? null,
                'ip'              => $v['visitIp'] ?? null,
                'city'            => $v['city'] ?? null,
                'region'          => $v['region'] ?? null,
                'country'         => $v['country'] ?? null,
                'device_type'     => $device['device_type'] ?? ($v['deviceType'] ?? null),
                'device_brand'    => $device['brand'],
                'device_model'    => $device['model'],
                'browser'         => $v['browser'] ?? $device['client'],
                'os'              => $v['operatingSystem'] ?? null,
                'resolution'      => $v['resolution'] ?? null,
                'referrer_type'   => $v['referrerTypeName'] ?? null,
                'referrer_name'   => $v['referrerName'] ?: ($v['referrerUrl'] ?? null),
                'visitor_type'    => $v['visitorType'] ?? null,
                'visit_count'     => $v['visitCount'] ?? null,
                'first_action_at' => $v['serverDatePrettyFirstAction'] ?? null,
                'last_action_at'  => $v['lastActionDateTime'] ?? null,
                'duration_sec'    => $v['visitDuration'] ?? null,
                'pages'           => $pages,
                'video_watched_pct' => $maxPct !== null ? (int) $maxPct : null,
                'clicks_tracked'  => $clicks,
                'source_key'      => $sourceKey,
                'campaign'        => $campaign ? [
                    'utm_source'   => $campaign->utm_source,
                    'utm_medium'   => $campaign->utm_medium,
                    'utm_campaign' => $campaign->utm_campaign,
                    'utm_content'  => $campaign->utm_content,
                    'utm_term'     => $campaign->utm_term,
                    'utm_id'       => $campaign->utm_id,
                    'fbclid'       => $campaign->fbclid,
                    'gclid'        => $campaign->gclid,
                    'ttclid'       => $campaign->ttclid,
                    'referrer'     => $campaign->referrer,
                    'landing_page' => $campaign->landing_page,
                ] : null,
                'recordings_count' => (int) ($recordingsByVisitor[$v['visitorId'] ?? ''] ?? 0),
            ];
        })->values();

        return response()->json(['matomo_ok' => true, 'data' => $data, 'limit' => $limit, 'offset' => $offset]);
    }

    // GET /api/v1/admin/analytics/heatmap?page_url=/vsl&days=30  (global, todas as visitas)
    public function heatmap(Request $request)
    {
        $this->requireSuperAdmin($request);

        $pageUrl = (string) $request->query('page_url', '');
        $days = min(max((int) $request->query('days', 30), 1), 90);

        // SEL-VISITANTES-2 (12/08): ANTES agrupava por page_url CRU. Como todo
        // clique de tráfego pago traz um fbclid único, o mesmo "/" virava
        // dezenas de páginas diferentes (medido: 39 URLs distintas pra 986
        // cliques) e o mapa de calor saía picotado — 1 clique por "página".
        // Agora agrupa por PATH normalizado, que é o que "página" significa.
        $sqlPath = $this->sqlPath();

        $pages = SiteAnalyticsEvent::where('event_type', 'click')
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw("$sqlPath as path, COUNT(*) as clicks, COUNT(DISTINCT visitor_uid) as visitors")
            ->groupBy('path')
            ->orderByDesc('clicks')
            ->limit(200)
            ->get();

        if ($pageUrl === '') {
            return response()->json(['points' => [], 'available_pages' => $pages]);
        }

        $alvo = $this->normPath($pageUrl);

        $points = SiteAnalyticsEvent::where('event_type', 'click')
            ->whereRaw("$sqlPath = ?", [$alvo])
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('ROUND(x_pct / 2) * 2 as xb, ROUND(y_pct / 2) * 2 as yb, COUNT(*) as c')
            ->groupBy('xb', 'yb')
            ->get();

        $elementos = SiteAnalyticsEvent::where('event_type', 'click')
            ->whereRaw("$sqlPath = ?", [$alvo])
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('el_text')->where('el_text', '<>', '')
            ->selectRaw('el_text, COUNT(*) as c, COUNT(DISTINCT visitor_uid) as v')
            ->groupBy('el_text')->orderByDesc('c')->limit(15)->get();

        return response()->json([
            'points'          => $points,
            'available_pages' => $pages,
            'path'            => $alvo,
            'top_elements'    => $elementos,
            'total_clicks'    => (int) $points->sum('c'),
            'visitors'        => SiteAnalyticsEvent::where('event_type', 'click')
                ->whereRaw("$sqlPath = ?", [$alvo])
                ->where('created_at', '>=', now()->subDays($days))
                ->distinct()->count('visitor_uid'),
        ]);
    }

    // GET /api/v1/admin/analytics/visitor/{visitorUid}/journey — mapa da jornada (não tabela).
    public function visitorJourney(Request $request, string $visitorUid)
    {
        $this->requireSuperAdmin($request);

        $events = SiteAnalyticsEvent::where('visitor_uid', $visitorUid)
            ->whereIn('event_type', ['pageview', 'pageview_end', 'click'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // Pareia pageview -> pageview_end em ordem (fila por page_url); o que
        // sobra sem "end" (aba fechada antes do beacon) fica com duracao null.
        $open = [];
        $steps = [];
        foreach ($events as $e) {
            if ($e->event_type === 'pageview') {
                $open[] = ['url' => $e->page_url, 'title' => $e->page_title, 'entered_at' => $e->created_at, 'idx' => count($steps)];
                $steps[] = [
                    'page_url'       => $e->page_url,
                    'page_title'     => $e->page_title,
                    'entered_at'     => $e->created_at?->toIso8601String(),
                    'duration_sec'   => null,
                    'scroll_max_pct' => null,
                    'clicks'         => [],
                ];
            } elseif ($e->event_type === 'pageview_end') {
                // acha o open mais antigo da MESMA url; senão, o mais antigo geral
                $pick = null;
                foreach ($open as $k => $o) {
                    if ($o['url'] === $e->page_url) { $pick = $k; break; }
                }
                if ($pick === null && count($open) > 0) {
                    $pick = array_key_first($open);
                }
                if ($pick !== null) {
                    $idx = $open[$pick]['idx'];
                    $meta = $e->meta ?? [];
                    $steps[$idx]['duration_sec']   = isset($meta['duration_sec']) ? (int) $meta['duration_sec'] : null;
                    $steps[$idx]['scroll_max_pct'] = isset($meta['scroll_max_pct']) ? (int) $meta['scroll_max_pct'] : null;
                    unset($open[$pick]);
                }
            } elseif ($e->event_type === 'click') {
                /**
                 * SEL-PAINEL3 (13/08) — "aqui tem um clique, mas não marcou
                 * aonde". A coordenada (x_pct/y_pct) e o seletor SEMPRE
                 * chegam do client (medido: 1140/1140 cliques com x_pct/y_pct,
                 * 99,8% com el_selector) — só nunca foram anexados na
                 * jornada, que só olhava pageview/pageview_end. Agora cada
                 * clique é pendurado na página onde ele aconteceu (a mais
                 * recente ainda aberta; se nenhuma bater a URL exata, cai na
                 * última aberta — mesmo critério de "página atual" do
                 * pageview_end, só que do fim pro começo).
                 */
                $pick = null;
                foreach ($open as $k => $o) {
                    if ($o['url'] === $e->page_url) { $pick = $k; }
                }
                if ($pick === null && count($open) > 0) {
                    $pick = array_key_last($open);
                }
                if ($pick !== null) {
                    $idx = $open[$pick]['idx'];
                    $steps[$idx]['clicks'][] = [
                        'el_selector' => $e->el_selector,
                        'el_text'     => $e->el_text,
                        'x_pct'       => $e->x_pct !== null ? (float) $e->x_pct : null,
                        'y_pct'       => $e->y_pct !== null ? (float) $e->y_pct : null,
                        'at'          => $e->created_at?->toIso8601String(),
                    ];
                }
            }
        }

        return response()->json(['steps' => array_values($steps)]);
    }

    // GET /api/v1/admin/analytics/visitor/{visitorUid}/heatmap?page_url=  — mapa de calor DAQUELE visitante.
    public function visitorHeatmap(Request $request, string $visitorUid)
    {
        $this->requireSuperAdmin($request);

        $pageUrl = (string) $request->query('page_url', '');

        // SEL-VISITANTES-2 (12/08): mesma correção do mapa global — agrupa por
        // PATH, senão o fbclid único de cada visita vira uma "página" nova.
        $sqlPath = $this->sqlPath();

        $availablePages = SiteAnalyticsEvent::where('visitor_uid', $visitorUid)
            ->whereIn('event_type', ['click', 'mousemove_batch'])
            ->selectRaw("$sqlPath as path, COUNT(*) as n")
            ->groupBy('path')
            ->orderByDesc('n')
            ->limit(100)
            ->pluck('path');

        if ($pageUrl === '') {
            return response()->json(['clicks' => [], 'moves' => [], 'available_pages' => $availablePages]);
        }

        $alvo = $this->normPath($pageUrl);

        $clicks = SiteAnalyticsEvent::where('visitor_uid', $visitorUid)
            ->where('event_type', 'click')
            ->whereRaw("$sqlPath = ?", [$alvo])
            ->selectRaw('ROUND(x_pct / 2) * 2 as xb, ROUND(y_pct / 2) * 2 as yb, COUNT(*) as c')
            ->groupBy('xb', 'yb')
            ->get();

        // mousemove_batch guarda os pontos dentro de meta.points (JSON) — bucketiza em PHP.
        $moveRows = SiteAnalyticsEvent::where('visitor_uid', $visitorUid)
            ->where('event_type', 'mousemove_batch')
            ->whereRaw("$sqlPath = ?", [$alvo])
            ->limit(500)
            ->pluck('meta');

        $buckets = [];
        foreach ($moveRows as $meta) {
            $points = $meta['points'] ?? [];
            if (! is_array($points)) continue;
            foreach ($points as $p) {
                $x = round((float) ($p['x'] ?? -1) / 2) * 2;
                $y = round((float) ($p['y'] ?? -1) / 2) * 2;
                if ($x < 0 || $y < 0) continue;
                $key = "{$x}:{$y}";
                $buckets[$key] = ($buckets[$key] ?? 0) + 1;
            }
        }
        $moves = collect($buckets)->map(function ($c, $key) {
            [$xb, $yb] = explode(':', $key);
            return ['xb' => (float) $xb, 'yb' => (float) $yb, 'c' => $c];
        })->values();

        return response()->json(['clicks' => $clicks, 'moves' => $moves, 'available_pages' => $availablePages]);
    }

    // GET /api/v1/admin/analytics/visitor/{visitorUid}/recordings — lista de gravações desse visitante.
    public function visitorRecordings(Request $request, string $visitorUid)
    {
        $this->requireSuperAdmin($request);

        $sessions = SiteSessionRecording::where('visitor_uid', $visitorUid)
            ->selectRaw('session_id, MIN(created_at) as started_at, MAX(created_at) as ended_at, SUM(events_count) as events_count, SUM(raw_bytes) as raw_bytes, COUNT(*) as chunks, MAX(page_url) as last_page')
            ->groupBy('session_id')
            ->orderByDesc('started_at')
            ->limit(20)
            ->get();

        if ($sessions->isEmpty()) {
            return response()->json(['sessions' => $sessions]);
        }

        /**
         * SEL-PAINEL3 (Ruan 13/08) — "o resumo, aonde é que ele tá na página
         * e o que tá sendo feito". Antes cada gravação só trazia metadado
         * técnico (bytes, chunks) — pra saber o que aconteceu tinha que ABRIR
         * o player e assistir os minutos inteiros.
         *
         * site_analytics_events NÃO guarda session_id (a tabela nasceu antes
         * da gravação de tela existir — ver migration 2026_08_12_060000), então
         * o cruzamento é por JANELA DE TEMPO do mesmo visitante: os eventos
         * que caem entre o primeiro e o último chunk da gravação (+-8s de
         * folga pro atraso do beacon fire-and-forget) são tratados como
         * pertencentes a ela. Isso é impreciso SÓ quando o mesmo visitante tem
         * duas gravações emendadas sem pausa (raro — cada sessão de gravação
         * nasce de um reload de página); o resumo então pode repetir um
         * evento de borda nas duas. Não inventa página nem clique que não
         * exista — só pode juntar de mais numa borda de poucos segundos.
         */
        $janelas = $sessions->map(fn ($s) => [
            'ini' => \Carbon\Carbon::parse($s->started_at)->subSeconds(8),
            'fim' => \Carbon\Carbon::parse($s->ended_at)->addSeconds(8),
        ]);

        $eventos = SiteAnalyticsEvent::where('visitor_uid', $visitorUid)
            ->whereIn('event_type', ['pageview', 'pageview_end', 'click', 'video_progress', 'video_play'])
            ->where('created_at', '>=', $janelas->min('ini'))
            ->where('created_at', '<=', $janelas->max('fim'))
            ->orderBy('created_at')
            ->get();

        $sessions = $sessions->values()->map(function ($s, $i) use ($eventos, $janelas) {
            $janelaIni = $janelas[$i]['ini'];
            $janelaFim = $janelas[$i]['fim'];
            $doIntervalo = $eventos->filter(fn ($e) => $e->created_at !== null && $e->created_at->between($janelaIni, $janelaFim));

            $paginas = $doIntervalo->whereIn('event_type', ['pageview', 'click', 'video_play', 'video_progress'])
                ->map(fn ($e) => $this->normPath($e->page_url))->filter()->unique()->values()->all();

            $cliques = $doIntervalo->where('event_type', 'click');
            $topClicks = $cliques->map(fn ($e) => trim((string) ($e->el_text ?? '')))
                ->filter(fn ($t) => $t !== '')->countBy()->sortDesc()->take(5)
                ->map(fn ($n, $t) => ['texto' => $t, 'n' => $n])->values()->all();

            $ends = $doIntervalo->where('event_type', 'pageview_end');
            $duracao = $ends->sum(fn ($e) => (int) ($e->meta['duration_sec'] ?? 0));
            $scrollMax = $ends->max(fn ($e) => (int) ($e->meta['scroll_max_pct'] ?? 0));

            $videoPct = $doIntervalo->where('event_type', 'video_progress')->max('video_pct');

            $s->resumo = [
                'paginas'        => $paginas,
                'cliques_total'  => $cliques->count(),
                'cliques_no_que' => $topClicks,
                'duration_sec'   => $duracao ?: null,
                'scroll_max_pct' => $scrollMax ?: null,
                'video_pct'      => $videoPct !== null ? (int) $videoPct : null,
                // quantos eventos próprios caíram na janela — 0 é sinal
                // honesto de "não achei nada pra resumir", não erro.
                'eventos_no_intervalo' => $doIntervalo->count(),
            ];

            return $s;
        });

        return response()->json(['sessions' => $sessions]);
    }

    // GET /api/v1/admin/analytics/recording/{sessionId} — eventos rrweb mesclados pro player.
    public function recordingEvents(Request $request, string $sessionId)
    {
        $this->requireSuperAdmin($request);

        $chunks = SiteSessionRecording::where('session_id', $sessionId)
            ->orderBy('seq')
            ->limit(400)
            ->get();

        $events = [];
        foreach ($chunks as $chunk) {
            foreach ($chunk->decodeEvents() as $ev) {
                $events[] = $ev;
            }
        }

        return response()->json(['events' => $events, 'chunks' => $chunks->count()]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * SEL-VISITANTES-2 (Ruan 12/08) — "deixa de ser um monte de número solto e
     * vira ANÁLISE". Palavras dele: "só tem uma campanha ativa?", "esses 42 eu
     * tenho que clicar e selecionar os 42", "essa origem é de onde?", "por que
     * ninguém assistiu o vídeo? tem que ter uma análise aí", "esse mapa de
     * calor é do quê? tem que ter o site de fundo".
     *
     * Regra que vale pra tudo aqui embaixo: o page_url que a coleta grava vem
     * de window.location.href, ou seja COM query string. Como todo clique de
     * tráfego pago carrega um fbclid ÚNICO, agrupar por page_url cru espalha
     * o mesmo "/" em dezenas de URLs diferentes (medido: 39 URLs distintas pra
     * 986 cliques). Por isso tudo aqui agrupa por PATH normalizado.
     * ═══════════════════════════════════════════════════════════════════════ */

    /** Path sem query string nem hash — a chave real de "página". */
    private function normPath(?string $url): string
    {
        if (! $url) {
            return '/';
        }
        $semQuery = explode('?', explode('#', $url)[0])[0];
        $path = parse_url($semQuery, PHP_URL_PATH);

        return $path ?: '/';
    }

    /** Expressão SQL que normaliza page_url pra path (sem query/hash/host). */
    private function sqlPath(string $col = 'page_url'): string
    {
        // tira o hash, tira a query, tira o https://dominio
        return "CONCAT('/', SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX($col, '#', 1), '?', 1), '/', -1))";
    }

    // GET /api/v1/admin/analytics/campaigns?days=30
    // "Só tem uma campanha ativa?" — campanha -> anúncio -> criativo, cada nível
    // com o número de visitantes por trás (e clicável no front).
    public function campaigns(Request $request)
    {
        $this->requireSuperAdmin($request);

        $days = min(max((int) $request->query('days', 30), 1), 90);
        $since = now()->subDays($days);

        $rows = SiteVisitorCampaign::where('created_at', '>=', $since)->get();

        // agrupa: origem -> campanha -> anúncio (utm_content) -> criativo (utm_term)
        $porCampanha = [];
        foreach ($rows as $r) {
            $src = $r->sourceKey();
            $camp = $r->utm_campaign ?: ($r->fbclid ? '(sem utm_campaign — veio com fbclid)' : '(tráfego sem campanha)');
            $key = $src . '|' . $camp;

            if (! isset($porCampanha[$key])) {
                $porCampanha[$key] = [
                    'source_key'    => $src,
                    'utm_source'    => $r->utm_source,
                    'utm_medium'    => $r->utm_medium,
                    'utm_campaign'  => $r->utm_campaign,
                    'campaign_label' => $camp,
                    'visitors'      => 0,
                    'first_seen'    => $r->created_at,
                    'last_seen'     => $r->created_at,
                    'landing_pages' => [],
                    'ads'           => [],
                ];
            }
            $c = &$porCampanha[$key];
            $c['visitors']++;
            if ($r->created_at < $c['first_seen']) $c['first_seen'] = $r->created_at;
            if ($r->created_at > $c['last_seen']) $c['last_seen'] = $r->created_at;

            $lp = $this->normPath($r->landing_page);
            $c['landing_pages'][$lp] = ($c['landing_pages'][$lp] ?? 0) + 1;

            $adKey = $r->utm_content ?: '(sem utm_content)';
            if (! isset($c['ads'][$adKey])) {
                $c['ads'][$adKey] = [
                    'utm_content' => $r->utm_content,
                    'ad_label'    => $adKey,
                    'visitors'    => 0,
                    'creatives'   => [],
                ];
            }
            $c['ads'][$adKey]['visitors']++;
            $termo = $r->utm_term ?: '(sem utm_term)';
            $c['ads'][$adKey]['creatives'][$termo] = ($c['ads'][$adKey]['creatives'][$termo] ?? 0) + 1;
            unset($c);
        }

        $campanhas = collect($porCampanha)->map(function ($c) {
            $c['ads'] = collect($c['ads'])->map(function ($a) {
                $a['creatives'] = collect($a['creatives'])->map(fn ($n, $t) => ['utm_term' => $t, 'visitors' => $n])->values();
                return $a;
            })->sortByDesc('visitors')->values();
            $c['landing_pages'] = collect($c['landing_pages'])->map(fn ($n, $p) => ['path' => $p, 'visitors' => $n])->sortByDesc('visitors')->values();
            $c['first_seen'] = $c['first_seen']?->toIso8601String();
            $c['last_seen'] = $c['last_seen']?->toIso8601String();
            $c['ads_count'] = $c['ads']->count();
            return $c;
        })->sortByDesc('visitors')->values();

        /**
         * SEL-PAINEL3 (13/08) — "Anúncios distintos" mostrava 7 e o banco tem 4.
         *
         * Era soma dos baldes por campanha, e cada campanha ganha um balde
         * "(sem utm_content)" pro tráfego que não identificou anúncio. Somando
         * os baldes, os 3 "sem anúncio" de 3 origens diferentes viravam 3
         * "anúncios". Conferência: SELECT COUNT(DISTINCT utm_content) WHERE
         * utm_content <> '' -> 4.
         *
         * Agora conta anúncio DE VERDADE, distinto, e é o mesmo universo que o
         * drilldown metric=ads abre.
         */
        $anunciosDistintos = $rows->pluck('utm_content')
            ->filter(fn ($v) => $v !== null && $v !== '')->unique()->count();

        return response()->json([
            'days'            => $days,
            'campaigns'       => $campanhas,
            'campaigns_count' => $campanhas->count(),
            'ads_count'       => $anunciosDistintos,
            'visitors_total'  => $campanhas->sum('visitors'),
        ]);
    }

    /**
     * GET /api/v1/admin/analytics/drilldown?metric=&value=&days=
     * "Esses 42, eu tenho que clicar e tenho que selecionar os 42" — qualquer
     * número da tela abre AQUI a lista de visitantes por trás dele.
     *
     * Roda 100% em cima das NOSSAS tabelas (não depende do Matomo estar de pé),
     * por isso responde sempre.
     */
    public function drilldown(Request $request)
    {
        $this->requireSuperAdmin($request);

        $metric = (string) $request->query('metric', 'all');
        $value = (string) $request->query('value', '');
        $days = min(max((int) $request->query('days', 30), 1), 90);
        $since = now()->subDays($days);
        $limit = min((int) $request->query('limit', 500), 1000);

        $path = $this->sqlPath();
        $uids = null;   // null = todos
        $titulo = 'Todos os visitantes';

        switch ($metric) {
            case 'campaign':
                $uids = SiteVisitorCampaign::where('created_at', '>=', $since)
                    ->when($value !== '', fn ($q) => $q->where('utm_campaign', $value))
                    ->when($value === '', fn ($q) => $q->whereNull('utm_campaign'))
                    ->pluck('visitor_uid');
                $titulo = "Visitantes da campanha {$value}";
                break;

            case 'ad':
                $uids = SiteVisitorCampaign::where('created_at', '>=', $since)
                    ->where('utm_content', $value)->pluck('visitor_uid');
                $titulo = "Visitantes do anúncio {$value}";
                break;

            case 'creative':
                $uids = SiteVisitorCampaign::where('created_at', '>=', $since)
                    ->where('utm_term', $value)->pluck('visitor_uid');
                $titulo = "Visitantes do criativo {$value}";
                break;

            // SEL-PAINEL3 (13/08): o card "Anúncios distintos" era o último
            // número morto da tela.
            case 'ads':
                $uids = SiteVisitorCampaign::where('created_at', '>=', $since)
                    ->whereNotNull('utm_content')->where('utm_content', '!=', '')
                    ->pluck('visitor_uid');
                $titulo = 'Visitantes que vieram de um anúncio identificado';
                break;

            case 'source':
                $uids = SiteVisitorCampaign::where('created_at', '>=', $since)->get()
                    ->filter(fn ($c) => $c->sourceKey() === $value)->pluck('visitor_uid');
                $titulo = "Visitantes vindos de {$value}";
                break;

            // idem: pageview é esparso (178 na base inteira) enquanto clique é
            // farto (1.052). Filtrar por pageview fazia "quem passou por X"
            // abrir quase vazio. Presença na página = qualquer evento nela.
            case 'page':
                $uids = SiteAnalyticsEvent::where('created_at', '>=', $since)
                    ->whereRaw("$path = ?", [$value])
                    ->pluck('visitor_uid');
                $titulo = "Visitantes que passaram por {$value}";
                break;

            case 'video_played':
                // NAO conta o video decorativo da home (video_id 'hero:...',
                // autoplay+loop+muted): ele roda sozinho, nao e alguem
                // escolhendo assistir. Contar isso seria 100% falso.
                $uids = SiteAnalyticsEvent::where('created_at', '>=', $since)
                    ->whereIn('event_type', ['video_play', 'video_progress'])
                    ->where(function ($q) {
                        $q->whereNull('video_id')->orWhere('video_id', 'NOT LIKE', 'hero:%');
                    })
                    ->pluck('visitor_uid');
                $titulo = 'Visitantes que deram play no vídeo';
                break;

            case 'recordings':
                $uids = SiteSessionRecording::where('created_at', '>=', $since)->pluck('visitor_uid');
                $titulo = 'Visitantes com gravação de sessão';
                break;

            case 'clicked':
                $uids = SiteAnalyticsEvent::where('created_at', '>=', $since)
                    ->where('event_type', 'click')->pluck('visitor_uid');
                $titulo = 'Visitantes que clicaram em alguma coisa';
                break;

            /**
             * SEL-PAINEL3 (13/08): filtrava por event_type='pageview' e a base
             * NÃO TEM nenhum pageview em /obrigado (0 em toda a base) — só
             * clique. Resultado: o card "Conversão" era clicável e abria uma
             * lista VAZIA. Número clicável que abre vazio é pior que número não
             * clicável. Qualquer evento na página prova presença.
             */
            case 'converted':
                $uids = SiteAnalyticsEvent::where('created_at', '>=', $since)
                    ->whereRaw("$path = ?", ['/obrigado'])
                    ->pluck('visitor_uid');
                $titulo = 'Visitantes que chegaram no /obrigado';
                break;

            /**
             * SEL-PAINEL3 (Ruan 13/08) — literal: "dois visitantes hoje.
             * Visitou aonde? Visitou o quê?". O card de hoje era número morto.
             * Aqui ele vira gente, com o caminho de cada um.
             */
            case 'today':
                $uids = SiteAnalyticsEvent::whereDate('created_at', now()->toDateString())->pluck('visitor_uid');
                $since = now()->startOfDay();
                $titulo = 'Visitantes de hoje';
                break;

            case 'all':
            default:
                $uids = SiteAnalyticsEvent::where('created_at', '>=', $since)->pluck('visitor_uid');
                $titulo = 'Todos os visitantes rastreados';
                break;
        }

        $uids = collect($uids)->filter()->unique()->values();
        $visitantes = $this->hydrateVisitors($uids->take($limit)->all(), $since);

        return response()->json([
            'metric'   => $metric,
            'value'    => $value,
            'title'    => $titulo,
            'count'    => $uids->count(),
            'summary'  => $this->resumoDoRecorte($visitantes),
            'visitors' => $visitantes,
        ]);
    }

    /**
     * SEL-PAINEL3 (Ruan 13/08) — "clico na campanha e quero MAIS DETALHES
     * dessa campanha. E eu tô SEM OS DADOS."
     *
     * Todo número que abre uma lista abre TAMBÉM este resumo do recorte, em
     * cima da lista: quantas pessoas, de qual anúncio e criativo vieram, onde
     * caíram, quanto ficaram, o que clicaram, se assistiram, se converteram.
     * É a resposta inteira no mesmo clique, calculada só com os visitantes
     * DESTE recorte — nada de média global disfarçada.
     */
    private function resumoDoRecorte(array $visitantes): array
    {
        $n = count($visitantes);
        if ($n === 0) {
            return ['pessoas' => 0];
        }

        $col = collect($visitantes);
        $tempos = $col->pluck('time_total_sec')->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)->sort()->values();

        // contagem simples por chave, já ordenada e cortada no que cabe na tela
        $topDe = function (string $campo, int $qtd = 6) use ($col) {
            return $col->pluck($campo)->flatten()->filter(fn ($v) => is_string($v) && $v !== '')
                ->countBy()->sortDesc()->take($qtd)
                ->map(fn ($qt, $rot) => ['rotulo' => $rot, 'n' => $qt])->values()->all();
        };

        return [
            'pessoas'          => $n,
            'anuncios'         => $topDe('utm_content'),
            'criativos'        => $topDe('utm_term'),
            'landings'         => $topDe('landing_page'),
            'paginas'          => $topDe('pages', 8),
            'aparelhos'        => $topDe('device_type', 4),
            // "o que clicaram" — texto do próprio botão que a pessoa apertou
            'cliques_no_que'   => $col->pluck('top_clicks')->flatten(1)
                ->filter(fn ($c) => is_array($c) && ($c['texto'] ?? '') !== '')
                ->groupBy('texto')->map->sum('n')->sortDesc()->take(8)
                ->map(fn ($qt, $rot) => ['rotulo' => $rot, 'n' => $qt])->values()->all(),
            'tempo_medio_sec'  => $tempos->count() ? (int) round($tempos->avg()) : null,
            'tempo_mediano_sec' => $tempos->count() ? (int) $tempos[(int) floor(($tempos->count() - 1) / 2)] : null,
            'base_tempo'       => $tempos->count(),
            'converteram'      => $col->where('converted', true)->count(),
            'com_gravacao'     => $col->filter(fn ($v) => (int) ($v['recordings'] ?? 0) > 0)->count(),
            'deram_play'       => $col->where('video_played', true)->count(),
            'deram_play_real'  => $col->where('video_played_real', true)->count(),
            'clicaram'         => $col->filter(fn ($v) => (int) ($v['clicks'] ?? 0) > 0)->count(),
            'cliques_total'    => $col->sum(fn ($v) => (int) ($v['clicks'] ?? 0)),
        ];
    }

    /** Monta a ficha de cada visitante a partir das NOSSAS tabelas (sem Matomo). */
    private function hydrateVisitors(array $uids, $since): array
    {
        if (empty($uids)) {
            return [];
        }

        $path = $this->sqlPath();

        $eventos = SiteAnalyticsEvent::whereIn('visitor_uid', $uids)
            ->where('created_at', '>=', $since)
            ->get()
            ->groupBy('visitor_uid');

        $campanhas = SiteVisitorCampaign::whereIn('visitor_uid', $uids)->get()->keyBy('visitor_uid');

        $gravacoes = SiteSessionRecording::whereIn('visitor_uid', $uids)
            ->selectRaw('visitor_uid, COUNT(DISTINCT session_id) n')
            ->groupBy('visitor_uid')->pluck('n', 'visitor_uid');

        $out = [];
        foreach ($uids as $uid) {
            $ev = $eventos->get($uid, collect());
            if ($ev->isEmpty() && ! $campanhas->has($uid)) {
                continue;
            }

            $pageviews = $ev->where('event_type', 'pageview');
            $ends = $ev->where('event_type', 'pageview_end');
            $tempoTotal = $ends->sum(fn ($e) => (int) (($e->meta['duration_sec'] ?? 0)));
            $scrollMax = $ends->max(fn ($e) => (int) (($e->meta['scroll_max_pct'] ?? 0))) ?: 0;

            $ua = $ev->sortByDesc('id')->first()->user_agent ?? null;
            $device = $this->deviceDetails($ua);
            $camp = $campanhas->get($uid);

            /**
             * SEL-PAINEL3 (13/08): montava a lista de páginas só com `pageview`.
             * Como pageview é esparso (178 na base) e clique é farto (1.052),
             * as duas pessoas que converteram apareciam com "0 páginas" e a
             * ficha delas abria praticamente vazia — o pior caso do painel:
             * número clicável que abre no nada.
             *
             * Qualquer evento carrega a page_url onde aconteceu, então a
             * presença na página é fato registrado. A lista passa a ser de onde
             * a pessoa REALMENTE esteve.
             */
            $paginas = $ev->map(fn ($e) => $this->normPath($e->page_url))
                ->filter()->unique()->values();

            /**
             * SEL-PAINEL3 (13/08) — "o que clicaram". O banco já guardava o
             * texto do elemento clicado (el_text) desde sempre e a tela nunca
             * mostrou. Agora cada pessoa leva junto os botões que ela apertou,
             * em ordem de quantidade.
             */
            $cliques = $ev->where('event_type', 'click');
            $topClicks = $cliques
                ->map(fn ($e) => trim((string) ($e->el_text ?? '')))
                ->filter(fn ($t) => $t !== '')
                ->countBy()->sortDesc()->take(6)
                ->map(fn ($n, $t) => ['texto' => $t, 'n' => $n])->values()->all();

            /**
             * SEL-PAINEL3 (13/08) — "aqui tem um clique, mas não marcou
             * aonde". x_pct/y_pct SEMPRE vêm preenchidos do client (medido:
             * 1140/1140 cliques) — só não estavam saindo na ficha do
             * visitante, só o texto agregado (top_clicks) saía. Aqui vai a
             * lista CRUA (até 30, mais recentes primeiro) com página +
             * coordenada + seletor, pra plotar "aonde" sem precisar abrir o
             * mapa de calor global por página.
             */
            $clickPoints = $cliques->sortByDesc('created_at')->take(30)
                ->map(fn ($e) => [
                    'page_url'    => $this->normPath($e->page_url),
                    'x_pct'       => $e->x_pct !== null ? (float) $e->x_pct : null,
                    'y_pct'       => $e->y_pct !== null ? (float) $e->y_pct : null,
                    'el_selector' => $e->el_selector,
                    'el_text'     => $e->el_text,
                    'created_at'  => $e->created_at?->toIso8601String(),
                ])->values()->all();

            // converteu = esteve no /obrigado. QUALQUER evento (não só pageview:
            // a base tem 0 pageview nessa rota e 14 cliques — filtrar por
            // pageview fazia "Converteram" dar 0 em toda lista, sempre).
            $converteu = $ev->contains(fn ($e) => $this->normPath($e->page_url) === '/obrigado');

            // play "de verdade" ≠ play do vídeo decorativo da home (hero:…,
            // autoplay+loop+muted). O decorativo roda sozinho: contar como
            // "assistiu" seria mentira.
            $playsReais = $ev->whereIn('event_type', ['video_play', 'video_progress'])
                ->filter(fn ($e) => ! str_starts_with((string) ($e->video_id ?? ''), 'hero:'))->count();

            $out[] = [
                'visitor_uid'    => $uid,
                // SEL-PAINEL3 (13/08): estava lendo ->ip_address, atributo que
                // não existe no model (a coluna real é `ip`) — sempre voltava
                // null e a ficha do visitante nunca mostrava IP nenhum.
                'ip'             => $ev->sortByDesc('id')->first()->ip ?? null,
                'device_type'    => $device['device_type'],
                'device_brand'   => $device['brand'],
                'device_model'   => $device['model'],
                'browser'        => $device['client'],
                'pages'          => $paginas,
                'pages_count'    => $paginas->count(),
                'time_total_sec' => $tempoTotal,
                'scroll_max_pct' => $scrollMax,
                'clicks'         => $cliques->count(),
                'top_clicks'     => $topClicks,
                'click_points'   => $clickPoints,
                'converted'      => $converteu,
                'video_pct'      => $ev->where('event_type', 'video_progress')->max('video_pct'),
                'video_played'   => $ev->whereIn('event_type', ['video_play', 'video_progress'])->count() > 0,
                'video_played_real' => $playsReais > 0,
                'recordings'     => (int) ($gravacoes[$uid] ?? 0),
                'source_key'     => $camp?->sourceKey(),
                'utm_campaign'   => $camp?->utm_campaign,
                'utm_content'    => $camp?->utm_content,
                'utm_term'       => $camp?->utm_term,
                'landing_page'   => $camp ? $this->normPath($camp->landing_page) : null,
                'first_seen'     => $ev->min('created_at')?->toIso8601String(),
                'last_seen'      => $ev->max('created_at')?->toIso8601String(),
            ];
        }

        usort($out, fn ($a, $b) => strcmp((string) $b['last_seen'], (string) $a['last_seen']));

        return $out;
    }

    /**
     * GET /api/v1/admin/analytics/video-diagnosis?days=30
     * "Por que ninguém assistiu o vídeo? O que está acontecendo? Tem que ter
     * uma análise aí." — responde com dado, não com número solto.
     */
    public function videoDiagnosis(Request $request)
    {
        $this->requireSuperAdmin($request);

        $days = min(max((int) $request->query('days', 30), 1), 90);
        $since = now()->subDays($days);
        $path = $this->sqlPath();

        // 1) o que existe de evento de vídeo, de fato
        $eventosVideo = SiteAnalyticsEvent::where('created_at', '>=', $since)
            ->where('event_type', 'LIKE', 'video%')
            ->selectRaw('event_type, COUNT(*) n, COUNT(DISTINCT visitor_uid) v')
            ->groupBy('event_type')->get()->keyBy('event_type');

        // plays REAIS: fora o laco decorativo da home. O hero entra separado,
        // como sinal de saude do player ("o player subiu"), nunca como audiencia.
        $plays = SiteAnalyticsEvent::where('created_at', '>=', $since)
            ->where('event_type', 'video_play')
            ->where(function ($q) {
                $q->whereNull('video_id')->orWhere('video_id', 'NOT LIKE', 'hero:%');
            })->count();
        $heroCarregou = SiteAnalyticsEvent::where('created_at', '>=', $since)
            ->where('event_type', 'video_play')
            ->where('video_id', 'LIKE', 'hero:%')
            ->distinct()->count('visitor_uid');
        $progressos = (int) ($eventosVideo['video_progress']->n ?? 0);

        $marcos = SiteAnalyticsEvent::where('created_at', '>=', $since)
            ->where('event_type', 'video_progress')
            ->selectRaw('video_pct, COUNT(DISTINCT visitor_uid) v')
            ->groupBy('video_pct')->pluck('v', 'video_pct');

        // 2) quem chegou nas páginas que TÊM vídeo
        $paginasComVideo = ['/', '/vsl', '/lista', '/drop', '/2', '/ruan', '/desafio30d', '/desafio2'];

        $chegaram = SiteAnalyticsEvent::where('created_at', '>=', $since)
            ->where('event_type', 'pageview')
            ->whereRaw("$path IN ('" . implode("','", $paginasComVideo) . "')")
            ->distinct()->count('visitor_uid');

        // 3) comportamento de quem chegou: tempo e rolagem reais.
        //
        // ATENÇÃO (SEL-VISITANTES-2, 12/08): só entram aqui as linhas que TÊM
        // meta gravado. Até a correção de hoje o meta vinha NULL em 100% dos
        // pageview_end (bug do validate() do Laravel — ver
        // SiteAnalyticsController::track). Se contássemos as linhas antigas,
        // duration_sec viraria 0 pra todo mundo e o painel afirmaria "100% dos
        // visitantes saem em até 3s", que é número inventado pelo bug e não
        // comportamento de ninguém. Linha sem meta = não medida, fica de fora.
        $ends = SiteAnalyticsEvent::where('created_at', '>=', $since)
            ->where('event_type', 'pageview_end')
            ->whereNotNull('meta')
            ->whereRaw("$path IN ('" . implode("','", $paginasComVideo) . "')")
            ->get(['meta']);

        $duracoes = $ends->filter(fn ($e) => isset($e->meta['duration_sec']))
            ->map(fn ($e) => (int) $e->meta['duration_sec'])->values();
        $scrolls = $ends->filter(fn ($e) => isset($e->meta['scroll_max_pct']))
            ->map(fn ($e) => (int) $e->meta['scroll_max_pct'])->values();

        // amostra mínima pra afirmar comportamento — abaixo disso o painel diz
        // "ainda medindo" em vez de cravar percentual em cima de 3 sessões.
        $AMOSTRA_MINIMA = 20;
        $amostraSuficiente = $duracoes->count() >= $AMOSTRA_MINIMA;

        $mediana = function ($col) {
            $c = collect($col)->sort()->values();
            if ($c->isEmpty()) return null;
            $n = $c->count();
            return $n % 2 ? $c[intdiv($n, 2)] : (int) round(($c[$n / 2 - 1] + $c[$n / 2]) / 2);
        };

        $totalEnds = max(1, $duracoes->count());
        $ate3s = $duracoes->filter(fn ($d) => $d <= 3)->count();
        $ate10s = $duracoes->filter(fn ($d) => $d <= 10)->count();
        $semScroll = $scrolls->filter(fn ($s) => $s <= 5)->count();

        // 4) causas — cada uma com a evidência numérica que a sustenta
        $causas = [];

        if ($plays === 0 && $progressos === 0) {
            $causas[] = [
                'gravidade' => 'critica',
                'titulo'    => 'O sensor não estava ligado na página que recebe o tráfego pago (corrigido em 12/08)',
                'evidencia' => "0 eventos de vídeo (video_play/video_progress) em {$days} dias, com {$chegaram} visitante(s) tendo aberto páginas com vídeo. Zero absoluto não é 'ninguém assistiu' — é sensor desligado.",
                'causa_raiz' => 'A landing " / " embutia o Bunny por <iframe> cru, sem passar pelo componente que fala Player.js e dispara os marcos de 25/50/75/100%. Todo o tráfego pago cai nessa página; as páginas que TINHAM o sensor (/vsl, /drop, /lista) praticamente não recebem verba. Ou seja: a medição nunca existiu onde o dinheiro entra.',
                'acao'      => 'Sensor já religado nessa página em 12/08 (hook useBunnyVideoTracking, sem mudar o visual). Ver a causa seguinte antes de esperar número.',
            ];

            $causas[] = [
                'gravidade' => 'critica',
                'titulo'    => 'O player do Bunny não responde à telemetria — testado ao vivo, não é suposição',
                'evidencia' => 'Teste no site publicado em 12/08: mandei o handshake do protocolo Player.js direto pro iframe do Bunny já carregado (addEventListener de ready/play/pause/timeupdate/ended) e esperei 6s. Voltaram ZERO mensagens. Nenhum evento, nem o "ready".',
                'causa_raiz' => 'O embed atual (iframe.mediadelivery.net) não está emitindo os eventos do Player.js pro site. Enquanto isso não mudar, religar o sensor não basta: não existe o que medir, porque o player não conta nada pra fora.',
                'acao'      => 'Habilitar o Player.js/eventos no player da biblioteca 723099 no painel do Bunny (ou trocar por um embed que emita). Depois disso o marco de 25/50/75/100% passa a chegar sozinho — o sensor já está no ar esperando.',
            ];

            $causas[] = [
                'gravidade' => 'alta',
                'titulo'    => 'O vídeo da home fica parado em 00:00 — ele não dá play sozinho',
                'evidencia' => 'Aberto o site publicado em navegador real, com o embed marcado autoplay+loop+muted: depois de 10s de página aberta o cronômetro do player seguia em 00:00, com o botão de play aparecendo. O visitante só vê o vídeo rodar se ele mesmo clicar.',
                'causa_raiz' => 'Autoplay não engatou nesse embed. Como a página é o destino de 100% do tráfego pago, na prática o "vídeo de vendas" é uma imagem parada pra quem chega.',
                'acao'      => 'Conferir o autoplay do player no Bunny (muted+playsinline) ou assumir o play manual e colocar um botão de play grande e explícito, em vez de depender do controle pequeno do player.',
            ];
        }

        if (! $amostraSuficiente) {
            $causas[] = [
                'gravidade' => 'informativa',
                'titulo'    => 'Tempo de página e rolagem só começaram a ser medidos de verdade em 12/08',
                'evidencia' => "Hoje há {$duracoes->count()} sessão(ões) com tempo/rolagem realmente gravados (mínimo de {$AMOSTRA_MINIMA} pra afirmar percentual).",
                'causa_raiz' => 'Um bug na coleta descartava o meta de todo evento (duration_sec, scroll_max_pct, referrer) antes de salvar — o campo ia NULL em 100% dos pageview_end. Corrigido em 12/08.',
                'acao'      => 'Deixar rodar 1 ou 2 dias de tráfego. A partir daí este bloco passa a responder tempo real na página, rolagem e quique com número que dá pra confiar.',
            ];
        }

        if ($amostraSuficiente && $ate3s / $totalEnds > 0.4) {
            $causas[] = [
                'gravidade' => 'alta',
                'titulo'    => 'A maior parte do tráfego pago sai em 3 segundos ou menos',
                'evidencia' => "{$ate3s} de {$totalEnds} saídas de página duraram <= 3s (" . round($ate3s / $totalEnds * 100) . '%).',
                'causa_raiz' => 'Tráfego frio de Facebook chegando e quicando — normalmente promessa do anúncio diferente da promessa da página, ou a página demora a pintar no 4G.',
                'acao'      => 'Comparar o criativo do anúncio com a primeira dobra da landing e medir o LCP no mobile.',
            ];
        }

        if ($scrolls->count() >= $AMOSTRA_MINIMA && $semScroll / max(1, $scrolls->count()) > 0.5) {
            $causas[] = [
                'gravidade' => 'alta',
                'titulo'    => 'Metade dos visitantes não rola a página',
                'evidencia' => "{$semScroll} de {$scrolls->count()} sessões com rolagem máxima <= 5%.",
                'causa_raiz' => 'Só a primeira dobra é vista. Qualquer coisa abaixo dela (inclusive vídeo e CTA) não existe pro visitante.',
                'acao'      => 'Subir vídeo e CTA principal pra primeira dobra no mobile.',
            ];
        }

        $causas[] = [
            'gravidade' => 'informativa',
            'titulo'    => 'A gravação de tela nunca vai mostrar a imagem do vídeo — e isso não é bug',
            'evidencia' => 'O vídeo é servido por iframe de OUTRO domínio (iframe.mediadelivery.net). O rrweb grava o DOM da NOSSA página; por política de origem cruzada do navegador, nenhum script nosso enxerga o conteúdo dentro desse iframe. Na /convite o vídeo é <video> alimentado por hls.js (MediaSource/blob), que também não tem frame capturável.',
            'causa_raiz' => 'Limite do navegador, igual pra qualquer ferramenta do mercado (Hotjar, Clarity, PostHog têm exatamente a mesma limitação).',
            'acao'      => 'O que dá pra ter é a TELEMETRIA do player (play/pause/% assistido) desenhada por cima da linha do tempo da gravação — que é o que a correção do VideoPlayer acima destrava.',
        ];

        return response()->json([
            'days'            => $days,
            'video_events'    => $eventosVideo,
            'plays'           => $plays,
            'hero_player_loaded' => $heroCarregou,
            'progress_events' => $progressos,
            'milestones'      => ['25' => (int) ($marcos[25] ?? 0), '50' => (int) ($marcos[50] ?? 0), '75' => (int) ($marcos[75] ?? 0), '100' => (int) ($marcos[100] ?? 0)],
            'reached_video_pages' => $chegaram,
            'behaviour'       => [
                'sessions_measured'  => $duracoes->count(),
                'median_duration_sec' => $mediana($duracoes),
                'avg_duration_sec'   => $duracoes->count() ? (int) round($duracoes->avg()) : null,
                'pct_leave_under_3s' => round($ate3s / $totalEnds * 100, 1),
                'pct_leave_under_10s' => round($ate10s / $totalEnds * 100, 1),
                'median_scroll_pct'  => $mediana($scrolls),
                'pct_no_scroll'      => $scrolls->count() ? round($semScroll / $scrolls->count() * 100, 1) : null,
            ],
            'causes'          => $causas,
        ]);
    }

    /**
     * GET /api/v1/admin/analytics/page-snapshot?path=/
     * "Tem que ter o site de fundo e o mapa de calor por cima." Devolve o
     * snapshot rrweb (DOM completo) já gravado daquela página, pro front
     * desenhar a PÁGINA de verdade atrás do calor — sem screenshot, sem
     * serviço externo: é o DOM que a própria gravação já guarda.
     */
    public function pageSnapshot(Request $request)
    {
        $this->requireSuperAdmin($request);

        $alvo = $this->normPath((string) $request->query('path', '/'));
        $sqlPath = $this->sqlPath();
        // 'desktop' (padrão) | 'mobile' — o dono escolhe na tela qual versão do
        // site quer ver atrás do calor.
        $prefere = (string) $request->query('prefer', 'desktop');
        $sessaoPedida = trim((string) $request->query('session', ''));

        // pega os primeiros chunks (seq baixo) das sessões dessa página — o
        // snapshot completo (type 2) mora no começo da gravação.
        $chunks = SiteSessionRecording::whereRaw("$sqlPath = ?", [$alvo])
            ->where('seq', '<=', 2)
            ->orderByDesc('created_at')
            ->limit(40)
            ->get();

        /**
         * SEL-PAINEL3 (Ruan 13/08) — "tem um BURACO aqui, dá nem pra saber o
         * que que é".
         *
         * A causa: aqui devolvia o snapshot MAIS RECENTE, e o mais recente
         * quase sempre é celular (a maior parte do tráfego pago é mobile).
         * Medido em produção: o snapshot servido tinha 384px de largura e o
         * front esticava 2,9× pra encher o quadro — a home virava uma torre
         * borrada de 11.717px de altura, com vãos pretos gigantes no lugar das
         * seções. Isso é o "buraco".
         *
         * E o desktop ESTAVA na base o tempo todo: 1707px, 1536px, 1528px pra
         * mesma "/" — nunca escolhido.
         *
         * Agora: varre os candidatos, mede a largura real de cada um e devolve
         * o DESKTOP mais largo (ou o celular, se for o pedido). Manda também a
         * lista de variantes, pra tela deixar trocar entre as duas.
         */
        $candidatos = [];
        foreach ($chunks as $chunk) {
            $meta = null;
            $full = null;
            foreach ($chunk->decodeEvents() as $ev) {
                $tipo = $ev['type'] ?? null;
                if ($tipo === 4 && $meta === null) $meta = $ev;
                if ($tipo === 2 && $full === null) $full = $ev;
            }
            if ($full === null) continue;
            $largura = (int) ($meta['data']['width'] ?? 0);
            $candidatos[] = [
                'session_id'  => $chunk->session_id,
                'captured_at' => $chunk->created_at,
                'width'       => $largura ?: 1280,
                'height'      => (int) ($meta['data']['height'] ?? 720),
                'events'      => $meta ? [$meta, $full] : [$full],
            ];
        }

        if (empty($candidatos)) {
            return response()->json([
                'ok'     => false,
                'path'   => $alvo,
                'reason' => 'Ainda não há snapshot de DOM gravado dessa página (a gravação de sessão só guarda o DOM completo quando alguém navega nela com o rrweb ativo).',
                'events' => [],
                'variants' => [],
            ]);
        }

        $ehDesktop = fn (array $c) => $c['width'] >= 1024;

        // escolhe: sessão pedida > preferência de aparelho > o mais largo
        $escolhido = null;
        if ($sessaoPedida !== '') {
            $escolhido = collect($candidatos)->firstWhere('session_id', $sessaoPedida);
        }
        if (! $escolhido) {
            $doTipo = collect($candidatos)->filter(
                $prefere === 'mobile' ? fn ($c) => ! $ehDesktop($c) : $ehDesktop
            );
            // mais largo do tipo pedido; se não existir nenhum, o mais largo que houver
            $escolhido = ($doTipo->isNotEmpty() ? $doTipo : collect($candidatos))
                ->sortByDesc('width')->first();
        }

        $variantes = collect($candidatos)
            ->sortByDesc('width')
            ->unique(fn ($c) => ($ehDesktop($c) ? 'desktop' : 'mobile'))
            ->map(fn ($c) => [
                'session_id'  => $c['session_id'],
                'device'      => $ehDesktop($c) ? 'desktop' : 'mobile',
                'width'       => $c['width'],
                'height'      => $c['height'],
                'captured_at' => $c['captured_at']?->toIso8601String(),
            ])->values();

        return response()->json([
            'ok'          => true,
            'path'        => $alvo,
            'session_id'  => $escolhido['session_id'],
            'captured_at' => $escolhido['captured_at']?->toIso8601String(),
            'width'       => $escolhido['width'],
            'height'      => $escolhido['height'],
            'device'      => $ehDesktop($escolhido) ? 'desktop' : 'mobile',
            'variants'    => $variantes,
            'events'      => $escolhido['events'],
        ]);
    }

    /**
     * GET /api/v1/admin/analytics/insights?days=30
     * "Baseado nessas informações a gente pode trazer melhorias no site."
     * Só entra recomendação que nasce de número REAL desta base — nada de
     * boa prática genérica.
     */
    public function insights(Request $request)
    {
        $this->requireSuperAdmin($request);

        $days = min(max((int) $request->query('days', 30), 1), 90);
        $since = now()->subDays($days);
        $path = $this->sqlPath();

        $porPagina = SiteAnalyticsEvent::where('created_at', '>=', $since)
            ->where('event_type', 'pageview')
            ->selectRaw("$path p, COUNT(*) n, COUNT(DISTINCT visitor_uid) v")
            ->groupBy('p')->orderByDesc('n')->limit(30)->get();

        $cliquesPorPagina = SiteAnalyticsEvent::where('created_at', '>=', $since)
            ->where('event_type', 'click')
            ->selectRaw("$path p, COUNT(*) n, COUNT(DISTINCT visitor_uid) v")
            ->groupBy('p')->orderByDesc('n')->limit(30)->get();

        $topCliques = SiteAnalyticsEvent::where('created_at', '>=', $since)
            ->where('event_type', 'click')
            ->whereNotNull('el_text')->where('el_text', '<>', '')
            ->selectRaw('el_text, COUNT(*) n, COUNT(DISTINCT visitor_uid) v')
            ->groupBy('el_text')->orderByDesc('n')->limit(20)->get();

        $visitantesTotais = SiteAnalyticsEvent::where('created_at', '>=', $since)->distinct()->count('visitor_uid');
        $comCampanha = SiteVisitorCampaign::where('created_at', '>=', $since)->distinct()->count('visitor_uid');
        $converteram = SiteAnalyticsEvent::where('created_at', '>=', $since)
            ->where('event_type', 'pageview')->whereRaw("$path = ?", ['/obrigado'])
            ->distinct()->count('visitor_uid');

        // Mesma regra do diagnóstico: só linha COM meta entra (antes de 12/08 o
        // meta era descartado na coleta e viraria 0s falso pra todo mundo).
        $landing = SiteAnalyticsEvent::where('created_at', '>=', $since)
            ->where('event_type', 'pageview_end')
            ->whereNotNull('meta')
            ->whereRaw("$path = ?", ['/'])
            ->get(['meta']);
        $durLanding = $landing->filter(fn ($e) => isset($e->meta['duration_sec']))
            ->map(fn ($e) => (int) $e->meta['duration_sec'])->values();
        $scrollLanding = $landing->filter(fn ($e) => isset($e->meta['scroll_max_pct']))
            ->map(fn ($e) => (int) $e->meta['scroll_max_pct'])->values();
        $AMOSTRA_MINIMA = 20;

        $recomendacoes = [];

        if ($visitantesTotais > 0 && $converteram === 0) {
            $recomendacoes[] = [
                'prioridade' => 1,
                'titulo'     => 'Nenhum visitante do período chegou no /obrigado',
                'dado'       => "{$visitantesTotais} visitantes rastreados, {$comCampanha} vindos de campanha, 0 no /obrigado.",
                'acao'       => 'O funil inteiro está parando antes da confirmação. Antes de subir verba, vale rodar 1 compra de ponta a ponta e conferir se o /obrigado está mesmo sendo carregado no fim do checkout.',
            ];
        }

        if ($scrollLanding->count() >= $AMOSTRA_MINIMA) {
            $semRolar = $scrollLanding->filter(fn ($s) => $s <= 5)->count();
            $pct = round($semRolar / $scrollLanding->count() * 100);
            if ($pct >= 40) {
                $recomendacoes[] = [
                    'prioridade' => 2,
                    'titulo'     => 'A landing não passa da primeira dobra pra maioria',
                    'dado'       => "{$semRolar} de {$scrollLanding->count()} sessões na ' / ' com rolagem máxima <= 5% ({$pct}%).",
                    'acao'       => 'Tudo que importa (vídeo, prova, CTA) precisa caber na primeira tela do celular. O que está embaixo, hoje, não é visto.',
                ];
            }
        }

        if ($durLanding->count() >= $AMOSTRA_MINIMA) {
            $rapidos = $durLanding->filter(fn ($d) => $d <= 3)->count();
            $pct = round($rapidos / $durLanding->count() * 100);
            if ($pct >= 30) {
                $recomendacoes[] = [
                    'prioridade' => 2,
                    'titulo'     => 'Boa parte do tráfego pago quica em 3s',
                    'dado'       => "{$rapidos} de {$durLanding->count()} sessões na ' / ' duraram <= 3s ({$pct}%).",
                    'acao'       => 'Alinhar a promessa do criativo com a primeira dobra da página e checar o tempo de pintura no 4G.',
                ];
            }
        }

        $entrar = $topCliques->firstWhere('el_text', 'Entrar');
        if ($entrar) {
            $recomendacoes[] = [
                'prioridade' => 3,
                'titulo'     => '"Entrar" é o elemento mais clicado do site inteiro',
                'dado'       => "{$entrar->n} cliques de {$entrar->v} visitantes distintos — mais que qualquer CTA de venda.",
                'acao'       => 'Quem chega já é cliente e quer login. Vale separar visualmente "Entrar" (cliente) do CTA de aquisição, pra não competirem pelo mesmo espaço na primeira dobra.',
            ];
        }

        return response()->json([
            'days'             => $days,
            'visitors_total'   => $visitantesTotais,
            'visitors_from_campaign' => $comCampanha,
            'converted'        => $converteram,
            'pages'            => $porPagina,
            'click_pages'      => $cliquesPorPagina,
            'top_clicks'       => $topCliques,
            'recommendations'  => $recomendacoes,
        ]);
    }

}
