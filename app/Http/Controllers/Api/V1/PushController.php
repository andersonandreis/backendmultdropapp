<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * SEL-050 + SEL-259 — Web Push (Seller Global).
 *
 * SEL-050: assinatura base (public-key, subscribe, unsubscribe, admin send/list)
 * SEL-259: preferences por nicho + endpoint de teste + trigger admin por client_id
 */
class PushController extends Controller
{
    // -------------------------------------------------------------------------
    // SEL-050: endpoints base (inalterados)
    // -------------------------------------------------------------------------

    public function publicKey()
    {
        $key = config('services.vapid.public');
        if (!$key) {
            return response()->json(['message' => 'Push não configurado'], 503);
        }

        return response()->json(['key' => $key]);
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint'    => 'required|string|max:2000',
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth'   => 'required|string|max:255',
        ]);

        $user     = $request->user();
        $clientId = DB::table('clients')->where('user_id', $user->id)->orderByDesc('id')->value('id');

        DB::table('push_subscriptions')->updateOrInsert(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'client_id'  => $clientId,
                'user_id'    => $user->id,
                'endpoint'   => $data['endpoint'],
                'p256dh'     => $data['keys']['p256dh'],
                'auth'       => $data['keys']['auth'],
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        // SEL-270 Ruan 15:09: registra timestamp de ativação pra medir taxa + destravar gate
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'push_activated_at')) {
            DB::table('users')->where('id', $user->id)->update(['push_activated_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * SEL-270 Ruan 15:09: user recusou push e escolheu WhatsApp — grava fallback
     * pra rotina de notificação enviar por lá em vez do web push.
     */
    public function fallbackWhatsapp(Request $request)
    {
        $u = $request->user();
        if (!$u) return response()->json(['error' => 'unauthenticated'], 401);
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'push_fallback_channel')) {
            DB::table('users')->where('id', $u->id)->update([
                'push_fallback_channel' => 'whatsapp',
                'push_declined_count' => \Illuminate\Support\Facades\DB::raw('COALESCE(push_declined_count, 0) + 1'),
            ]);
        }
        return response()->json(['ok' => true, 'channel' => 'whatsapp']);
    }

    public function unsubscribe(Request $request)
    {
        $data = $request->validate(['endpoint' => 'required|string|max:2000']);
        DB::table('push_subscriptions')->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();

        return response()->json(['ok' => true]);
    }

    public function adminSubscriptions()
    {
        $rows = DB::table('push_subscriptions as ps')
            ->leftJoin('clients as c', 'c.id', '=', 'ps.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'ps.user_id')
            ->select('ps.id', 'ps.client_id', 'ps.user_id', 'u.name', 'u.email', 'ps.user_agent', 'ps.created_at', 'ps.last_used_at')
            ->orderByDesc('ps.id')
            ->limit(200)
            ->get();

        return response()->json(['total' => DB::table('push_subscriptions')->count(), 'subscriptions' => $rows]);
    }

    public function adminSend(Request $request)
    {
        $data = $request->validate([
            'title'     => 'required|string|max:120',
            'body'      => 'required|string|max:500',
            'url'       => 'nullable|string|max:500',
            'client_id' => 'nullable|integer',
            'user_id'   => 'nullable|integer',
            'all'       => 'nullable|boolean',
        ]);

        $q = DB::table('push_subscriptions');
        if (!empty($data['client_id'])) {
            $q->where('client_id', $data['client_id']);
        } elseif (!empty($data['user_id'])) {
            $q->where('user_id', $data['user_id']);
        } elseif (empty($data['all'])) {
            return response()->json(['message' => 'Informe client_id, user_id ou all=true'], 422);
        }

        $subs = $q->get();
        if ($subs->isEmpty()) {
            return response()->json(['sent' => 0, 'failed' => 0, 'message' => 'Nenhuma assinatura encontrada']);
        }

        [$sent, $failed] = $this->dispatchToSubs($subs, $data['title'], $data['body'], $data['url'] ?? '/dashboard');

        return response()->json(['sent' => $sent, 'failed' => $failed]);
    }

    // -------------------------------------------------------------------------
    // SEL-259: preferences
    // -------------------------------------------------------------------------

    /**
     * GET /api/v1/push/preferences — retorna prefs do cliente autenticado.
     */
    public function preferences(Request $request)
    {
        $clientId = $this->resolveClientId($request->user()->id);
        if (!$clientId) {
            return response()->json(['message' => 'Cliente não encontrado'], 404);
        }

        $prefs = DB::table('push_preferences')->where('client_id', $clientId)->first();

        return response()->json([
            'client_id'               => $clientId,
            'niches'                  => $prefs ? json_decode($prefs->niches, true) : [],
            'live_alerts_enabled'     => $prefs ? (bool) $prefs->live_alerts_enabled : true,
            'product_alerts_enabled'  => $prefs ? (bool) $prefs->product_alerts_enabled : true,
            'quiet_hours_start'       => $prefs->quiet_hours_start ?? null,
            'quiet_hours_end'         => $prefs->quiet_hours_end   ?? null,
        ]);
    }

    /**
     * PATCH /api/v1/push/preferences — atualiza prefs.
     */
    public function updatePreferences(Request $request)
    {
        $data = $request->validate([
            'niches'                  => 'nullable|array',
            'niches.*'                => 'string|max:128',
            'live_alerts_enabled'     => 'nullable|boolean',
            'product_alerts_enabled'  => 'nullable|boolean',
            'quiet_hours_start'       => 'nullable|date_format:H:i',
            'quiet_hours_end'         => 'nullable|date_format:H:i',
        ]);

        $clientId = $this->resolveClientId($request->user()->id);
        if (!$clientId) {
            return response()->json(['message' => 'Cliente não encontrado'], 404);
        }

        $payload = [
            'updated_at' => now(),
        ];
        if (array_key_exists('niches', $data)) {
            $payload['niches'] = json_encode($data['niches'] ?? []);
        }
        if (array_key_exists('live_alerts_enabled', $data)) {
            $payload['live_alerts_enabled'] = $data['live_alerts_enabled'] ? 1 : 0;
        }
        if (array_key_exists('product_alerts_enabled', $data)) {
            $payload['product_alerts_enabled'] = $data['product_alerts_enabled'] ? 1 : 0;
        }
        if (array_key_exists('quiet_hours_start', $data)) {
            $payload['quiet_hours_start'] = $data['quiet_hours_start'];
        }
        if (array_key_exists('quiet_hours_end', $data)) {
            $payload['quiet_hours_end'] = $data['quiet_hours_end'];
        }

        DB::table('push_preferences')->updateOrInsert(
            ['client_id' => $clientId],
            array_merge($payload, ['created_at' => now()])
        );

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v1/push/test — dispara push de teste na própria subscription do cliente.
     */
    public function test(Request $request)
    {
        $clientId = $this->resolveClientId($request->user()->id);
        if (!$clientId) {
            return response()->json(['message' => 'Cliente não encontrado'], 404);
        }

        $subs = DB::table('push_subscriptions')->where('client_id', $clientId)->get();
        if ($subs->isEmpty()) {
            return response()->json(['message' => 'Nenhuma assinatura push encontrada. Ative as notificações no navegador primeiro.'], 422);
        }

        [$sent, $failed] = $this->dispatchToSubs(
            $subs,
            'Notificações ativas!',
            'Tudo certo — você vai receber alertas de lives e produtos em alta no TikTok Shop.',
            '/tiktok-shopping'
        );

        return response()->json(['sent' => $sent, 'failed' => $failed]);
    }

    /**
     * POST /api/v1/admin/push/trigger — dispara push pro telefone de um cliente específico.
     * super_admin only. Usado pelo Ruan para demonstração visual.
     *
     * Body: { client_id, product_id?, live_id?, custom_title?, custom_body? }
     */
    public function adminTrigger(Request $request)
    {
        $data = $request->validate([
            'client_id'    => 'required|integer',
            'product_id'   => 'nullable|integer',
            'live_id'      => 'nullable|integer',
            'custom_title' => 'nullable|string|max:120',
            'custom_body'  => 'nullable|string|max:500',
        ]);

        $subs = DB::table('push_subscriptions')->where('client_id', $data['client_id'])->get();
        if ($subs->isEmpty()) {
            return response()->json(['sent' => 0, 'failed' => 0, 'message' => 'Nenhuma assinatura para este cliente']);
        }

        // Monta título/corpo com contexto de produto ou live
        $title = $data['custom_title'] ?? 'Alerta Seller Global';
        $body  = $data['custom_body']  ?? 'Novidade em alta no TikTok Shop agora!';
        $url   = '/tiktok-shopping';

        if (!empty($data['product_id'])) {
            $product = DB::table('tt_shop_raw')
                ->where('type', 'product')
                ->where('id', $data['product_id'])
                ->first();
            if ($product) {
                $p = json_decode($product->payload, true);
                $title = $title === 'Alerta Seller Global'
                    ? 'Produto em alta agora'
                    : $title;
                $body = $body === 'Novidade em alta no TikTok Shop agora!'
                    ? ($p['title'] ?? $body)
                    : $body;
                $url  = $p['seo_url']['canonical_url'] ?? $url;
            }
        }

        if (!empty($data['live_id'])) {
            $live = DB::table('tt_live_snapshots')->where('id', $data['live_id'])->first();
            if ($live) {
                $title = $title === 'Alerta Seller Global'
                    ? "Live com {$live->viewers_now} espectadores agora"
                    : $title;
                $body  = $body === 'Novidade em alta no TikTok Shop agora!'
                    ? ($live->title ?? $body)
                    : $body;
                $url   = $live->tiktok_url ?? $url;
            }
        }

        [$sent, $failed] = $this->dispatchToSubs($subs, $title, $body, $url);

        Log::info('[SEL-259] admin trigger', [
            'client_id' => $data['client_id'],
            'sent'      => $sent,
            'failed'    => $failed,
        ]);

        return response()->json(['sent' => $sent, 'failed' => $failed]);
    }

    // -------------------------------------------------------------------------
    // Helpers internos
    // -------------------------------------------------------------------------

    private function resolveClientId(int $userId): ?int
    {
        return DB::table('clients')->where('user_id', $userId)->orderByDesc('id')->value('id');
    }

    /**
     * Despacha push para uma coleção de subscriptions.
     * Remove subscriptions mortas (404/410) automaticamente.
     *
     * @return array [int $sent, int $failed]
     */
    private function dispatchToSubs(iterable $subs, string $title, string $body, string $url): array
    {
        if (!config('services.vapid.public') || !config('services.vapid.private')) {
            Log::warning('[PushController] VAPID keys não configuradas — push não enviado.');
            return [0, 0];
        }

        // SEL (08/08 Ruan): envio em MASSA quebrava. Mandar 300+ de uma vez
        // estourava conexoes simultaneas -> maioria voltava com resposta NULA
        // (timeout) e o push nao chegava, calado. Fix: timeout curto por request
        // + reuso do header VAPID + flush em LOTES (abaixo). As inscricoes estao
        // ~85% validas; o gargalo era o disparo, nao a chave.
        $webPush = new WebPush([
            'VAPID' => [
                'subject'    => config('services.vapid.subject'),
                'publicKey'  => config('services.vapid.public'),
                'privateKey' => config('services.vapid.private'),
            ],
        ], [], 10);
        $webPush->setReuseVAPIDHeaders(true);

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'url'   => $url,
        ], JSON_UNESCAPED_UNICODE);

        $endpointHashes = [];
        foreach ($subs as $sub) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys'     => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth],
                ]),
                $payload
            );
            $endpointHashes[] = $sub->endpoint_hash;
        }

        $sent   = 0;
        $failed = 0;
        foreach ($webPush->flush(25) as $report) { // SEL 08/08: lotes de 25
            if ($report->isSuccess()) {
                $sent++;
                continue;
            }
            $failed++;
            $status = optional($report->getResponse())->getStatusCode();
            if (in_array($status, [403, 404, 410], true)) { // SEL 08/08: 403 = VAPID antiga (podre) -> limpa
                DB::table('push_subscriptions')
                    ->where('endpoint_hash', hash('sha256', $report->getEndpoint()))
                    ->delete();
            } else {
                Log::warning('[PushController] push fail', [
                    'status' => $status,
                    'reason' => $report->getReason(),
                ]);
            }
        }

        if (!empty($endpointHashes)) {
            DB::table('push_subscriptions')
                ->whereIn('endpoint_hash', $endpointHashes)
                ->update(['last_used_at' => now()]);
        }

        return [$sent, $failed];
    }

    // =========================================================================
    // SEL-267 — Painel de campanhas de push (broadcast por segmento)
    // =========================================================================

    /**
     * GET /api/v1/admin/push/subscribers-count
     * Retorna o número de subscriptions ativas.
     */
    public function subscribersCount()
    {
        $count = DB::table('push_subscriptions')->count();
        return response()->json(['count' => $count]);
    }

    /**
     * GET /api/v1/admin/push/history?limit=20
     * Lista campanhas enviadas com contadores.
     */
    public function history(Request $request)
    {
        $limit = min((int) $request->get('limit', 20), 100);
        $rows = DB::table('push_campaigns')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($c) => [
                'id'            => $c->id,
                'titulo'        => $c->titulo,
                'body'          => $c->body,
                'url'           => $c->url,
                'image_url'     => $c->image_url,
                'segment_type'  => $c->segment_type,
                'segment_value' => $c->segment_value,
                'scheduled_at'  => $c->scheduled_at,
                'sent_at'       => $c->sent_at,
                'sent_count'    => (int) $c->sent_count,
                'failed_count'  => (int) $c->failed_count,
                'clicked_count' => (int) $c->clicked_count,
                'created_at'    => $c->created_at,
            ]);
        return response()->json(['data' => $rows]);
    }

    /**
     * POST /api/v1/admin/push/trigger-self
     * Dispara push pro próprio user autenticado (admin testar no telefone dele).
     */
    public function triggerSelf(Request $request)
    {
        $data = $request->validate([
            'titulo'    => 'required|string|max:200',
            'body'      => 'required|string|max:300',
            'url'       => 'nullable|string|max:500',
            'image_url' => 'nullable|string|max:500',
        ]);

        $userId = $request->user()->id;
        $subs = DB::table('push_subscriptions')->where('user_id', $userId)->get();

        if ($subs->isEmpty()) {
            return response()->json([
                'sent'    => 0,
                'failed'  => 0,
                'message' => 'Você não tem push ativado no seu navegador. Vá em /notifications e ative.',
            ], 200);
        }

        [$sent, $failed] = $this->dispatchToSubs($subs, $data['titulo'], $data['body'], $data['url'] ?? '/');
        return response()->json(['sent' => $sent, 'failed' => $failed]);
    }

    /**
     * POST /api/v1/admin/push/campaign
     * Dispara broadcast por segmento + grava histórico.
     * Body: { titulo, body, url, image_url?, segment_type, segment_value?, scheduled_at? }
     */
    public function campaign(Request $request)
    {
        $data = $request->validate([
            'titulo'        => 'required|string|max:200',
            'body'          => 'required|string|max:300',
            'url'           => 'nullable|string|max:500',
            'image_url'     => 'nullable|string|max:500',
            'segment_type'  => 'required|in:all,plan,niche,recent',
            'segment_value' => 'nullable|string|max:100',
            'scheduled_at'  => 'nullable|date',
        ]);

        // Se agendado no futuro, só grava campanha sem enviar (cron dispara depois)
        $scheduled = !empty($data['scheduled_at']) && strtotime($data['scheduled_at']) > time();

        $campaignId = DB::table('push_campaigns')->insertGetId([
            'titulo'        => $data['titulo'],
            'body'          => $data['body'],
            'url'           => $data['url']       ?? null,
            'image_url'     => $data['image_url'] ?? null,
            'segment_type'  => $data['segment_type'],
            'segment_value' => $data['segment_value'] ?? null,
            'scheduled_at'  => $data['scheduled_at'] ?? null,
            'sent_at'       => $scheduled ? null : now(),
            'sent_count'    => 0,
            'failed_count'  => 0,
            'clicked_count' => 0,
            'created_by'    => $request->user()->id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        if ($scheduled) {
            return response()->json([
                'id'           => $campaignId,
                'sent_count'   => 0,
                'failed_count' => 0,
                'scheduled'    => true,
                'message'      => 'Campanha agendada para ' . $data['scheduled_at'],
            ]);
        }

        // Resolver segmento
        $subs = $this->resolveSegment($data['segment_type'], $data['segment_value'] ?? null);

        [$sent, $failed] = $this->dispatchToSubs($subs, $data['titulo'], $data['body'], $data['url'] ?? '/');

        DB::table('push_campaigns')
            ->where('id', $campaignId)
            ->update([
                'sent_count'   => $sent,
                'failed_count' => $failed,
                'updated_at'   => now(),
            ]);

        return response()->json([
            'id'           => $campaignId,
            'sent_count'   => $sent,
            'failed_count' => $failed,
        ]);
    }

    /**
     * POST /api/v1/push/troubleshoot
     * Cliente reporta "não recebi push de teste". Backend faz diagnóstico + tenta re-enviar.
     *
     * Retorna: status, diagnóstico, próximos passos.
     */
    public function troubleshoot(Request $request)
    {
        $userId = $request->user()->id;
        $subs   = DB::table('push_subscriptions')->where('user_id', $userId)->get();

        $issues = [];
        $steps  = [];

        // 1) VAPID configurado?
        if (!config('services.vapid.public') || !config('services.vapid.private')) {
            $issues[] = 'server_vapid_missing';
            $steps[] = [
                'title' => 'Servidor sem chaves de push',
                'body'  => 'Nosso servidor de push tá com problema. Já reportamos internamente. Enquanto isso, ativa notificação por WhatsApp abaixo.',
                'action' => 'contact_support',
            ];
        }

        // 2) Cliente tem subscription?
        if ($subs->isEmpty()) {
            $issues[] = 'no_subscription';
            $steps[] = [
                'title' => 'Você não tem push ativado ainda',
                'body'  => 'Sua permissão pode ter sido revogada ou o service worker foi desregistrado. Clica no botão pra ativar de novo.',
                'action' => 'resubscribe',
            ];
        } else {
            // 3) Subscription tá recente? (last_used_at > 7 dias = pode estar quebrada)
            $stale = $subs->filter(fn ($s) => !$s->last_used_at || strtotime($s->last_used_at) < strtotime('-7 days'));
            if ($stale->count() > 0 && $stale->count() === $subs->count()) {
                $issues[] = 'subscription_stale';
                $steps[] = [
                    'title' => 'Sua permissão pode ter expirado no navegador',
                    'body'  => 'Reativa clicando em "Ativar de novo" — vai gerar uma nova permissão.',
                    'action' => 'resubscribe',
                ];
            }

            // 4) Tentar re-enviar um push AGORA (pode ter sido bloqueio pontual)
            if (empty($issues)) {
                [$sent, $failed] = $this->dispatchToSubs(
                    $subs,
                    '🔔 Segundo teste — chegou dessa vez?',
                    'Se recebeu essa, tá tudo certo. Se ainda não, olha o checklist na tela.',
                    '/notifications'
                );
                if ($sent > 0 && $failed === 0) {
                    $issues[] = 'retry_sent';
                    $steps[] = [
                        'title' => 'Reenviamos agora — confere no celular',
                        'body'  => "Enviamos {$sent} push de teste. Se ainda não chegou, veja o checklist abaixo.",
                        'action' => 'wait_and_check',
                    ];
                } else {
                    $issues[] = 'delivery_failed';
                    $steps[] = [
                        'title' => 'Push não está sendo entregue',
                        'body'  => "Tentamos enviar {$sent} + {$failed} falharam. Sua subscription pode ter sido rejeitada pelo browser/SO.",
                        'action' => 'resubscribe',
                    ];
                }
            }
        }

        // Checklist universal (sempre retorna)
        $checklist = [
            [
                'id'    => 'permission',
                'label' => 'Notificações permitidas neste site?',
                'help'  => 'Chrome: cadeadinho na URL → Notificações → Permitir. Safari: Safari > Ajustes > Notificações. iOS: Ajustes > Notificações > Chrome/Safari.',
            ],
            [
                'id'    => 'dnd',
                'label' => 'Modo "Não perturbe" do celular está desligado?',
                'help'  => 'iOS: Central de Controle > lua/meia-lua desligada. Android: puxa a tela pra baixo > "Não perturbe" desligado.',
            ],
            [
                'id'    => 'battery',
                'label' => 'Modo economia de bateria desativado?',
                'help'  => 'iOS: Ajustes > Bateria > Modo de Baixa Energia desligado. Android: Ajustes > Bateria > Economia de bateria desativada pra este app.',
            ],
            [
                'id'    => 'chrome_bg',
                'label' => 'Chrome com "atividade em segundo plano" habilitada?',
                'help'  => 'Android: Ajustes > Apps > Chrome > Bateria > Sem restrição. Sem isso, o Chrome não recebe push com tela apagada.',
            ],
            [
                'id'    => 'pwa',
                'label' => 'Você abriu seller.global como PWA (não pela aba do navegador)?',
                'help'  => 'iOS 16.4+ SÓ recebe push se você adicionou seller.global à tela inicial (Safari > Compartilhar > Adicionar à Tela de Início). Web push comum não funciona no iOS Safari fora do PWA.',
            ],
        ];

        return response()->json([
            'has_subscription' => $subs->isNotEmpty(),
            'subs_count'       => $subs->count(),
            'issues'           => $issues,
            'next_steps'       => $steps,
            'checklist'        => $checklist,
            'fallback_channel' => [
                'label' => 'Ainda não chegou? Entra no nosso canal oficial do WhatsApp',
                'url'   => env('WHATSAPP_GROUP_URL_VIP', 'https://whatsapp.com/channel/0029VbAzaW30gcfNCtU7MZ0U'),
            ],
        ]);
    }

    /**
     * Resolve subscriptions pelo segmento configurado na campanha.
     */
    private function resolveSegment(string $type, ?string $value)
    {
        $q = DB::table('push_subscriptions as ps');

        if ($type === 'plan' && $value) {
            $q->join('users as u', 'u.id', '=', 'ps.user_id')
              ->join('clients as c', 'c.user_id', '=', 'u.id')
              ->leftJoin('subscriptions as sub', 'sub.client_id', '=', 'c.id')
              ->leftJoin('plans as p', 'p.id', '=', 'sub.plan_id')
              ->where(function ($w) use ($value) {
                  if ($value === 'free') {
                      $w->whereNull('sub.id')->orWhere('sub.status', '!=', 'active');
                  } else {
                      $w->where('sub.status', 'active')->where('p.slug', 'like', "%{$value}%");
                  }
              });
        } elseif ($type === 'niche' && $value) {
            $q->join('push_preferences as pp', 'pp.client_id', '=', DB::raw('(SELECT id FROM clients WHERE user_id = ps.user_id LIMIT 1)'))
              ->whereRaw("JSON_SEARCH(pp.niches, 'one', ?) IS NOT NULL", [$value]);
        } elseif ($type === 'recent') {
            $q->join('users as u', 'u.id', '=', 'ps.user_id')
              ->where('u.created_at', '>=', now()->subDays(7));
        }
        // else: type=all, sem filtro adicional

        return $q->select('ps.*')->get();
    }
}
