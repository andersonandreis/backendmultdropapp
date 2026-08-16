<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-010 — Relay CAPI (Meta Conversions API) para pixel Seller Global.
 *
 * Rota publica (sem auth, rate-limit throttle:60):
 *   POST /api/tracking/capi
 *
 * O browser envia o payload apos cada evento de funil (PageView, ViewContent,
 * InitiateCheckout, AddPaymentInfo, Lead, CompleteRegistration).
 * Purchase e disparado EXCLUSIVAMENTE via webhook Pagar.me
 * (PagarmeWebhookController::onChargePaid) com event_id = purchase_{order_id}.
 *
 * Env-gated: se META_CAPI_TOKEN nao estiver no .env, retorna 200 silencioso
 * para que nenhum outro tenant quebre caso herde este controller sem config.
 *
 * Eventos permitidos (whitelist):
 *   PageView, ViewContent, Lead, InitiateCheckout, AddPaymentInfo, CompleteRegistration
 *
 * NÃO aceita Purchase via browser — previne dedup incorreto com o evento
 * server-side do webhook (event_id deterministico = purchase_{order_id}).
 */
class TrackingController extends Controller
{
    /** @var string[] Eventos que o browser pode relay-ar via este endpoint */
    private const ALLOWED_EVENTS = [
        'PageView',
        'ViewContent',
        'Lead',
        'InitiateCheckout',
        'AddPaymentInfo',
        'CompleteRegistration',
    ];

    private const GRAPH_API_VERSION = 'v21.0';

    /**
     * Recebe evento do browser e encaminha para Graph API da Meta.
     */
    public function capi(Request $request): JsonResponse
    {
        $token   = env('META_CAPI_TOKEN', '');
        $pixelId = env('META_PIXEL_ID', '2114981255797268');

        // Env-gated: no-op silencioso se token ausente (outros tenants sem config)
        if (empty($token)) {
            Log::debug('[TrackingCAPI] META_CAPI_TOKEN nao configurado — no-op');
            return response()->json(['ok' => true, 'skipped' => true]);
        }

        $eventName = $request->input('event_name', '');

        // Whitelist — Purchase bloqueado (vem so do webhook Pagar.me)
        if (!in_array($eventName, self::ALLOWED_EVENTS, true)) {
            Log::warning('[TrackingCAPI] Evento nao permitido via browser', ['event_name' => $eventName]);
            return response()->json([
                'ok'      => false,
                'error'   => 'event_not_allowed',
                'allowed' => self::ALLOWED_EVENTS,
            ], 422);
        }

        $eventTime = time();
        $eventId   = $request->input('event_id') ?: ($eventName . '_' . $eventTime . '_' . uniqid());

        // user_data: ip + user-agent injetados pelo servidor; cookies e PII do body
        $userData = [
            'client_ip_address' => $request->ip(),
            'client_user_agent' => $request->input('client_user_agent') ?: $request->userAgent(),
        ];

        if ($fbp = $request->input('fbp')) {
            $userData['fbp'] = $fbp;
        }
        if ($fbc = $request->input('fbc')) {
            $userData['fbc'] = $fbc;
        }
        if ($email = $request->input('email')) {
            $userData['em'] = hash('sha256', strtolower(trim($email)));
        }
        if ($phone = $request->input('phone')) {
            $phone = preg_replace('/\D/', '', $phone);
            $userData['ph'] = hash('sha256', $phone);
        }
        if ($externalId = $request->input('external_id')) {
            $userData['external_id'] = hash('sha256', strtolower(trim($externalId)));
        }

        // custom_data: valor, moeda, conteudo
        $customData = [];
        if ($value = $request->input('value')) {
            $customData['value']    = (float) $value;
            $customData['currency'] = $request->input('currency', 'BRL');
        }
        if ($contentId = $request->input('content_id')) {
            $customData['content_ids']  = [$contentId];
            $customData['content_type'] = $request->input('content_type', 'product');
        }
        if ($contentName = $request->input('content_name')) {
            $customData['content_name'] = $contentName;
        }

        $eventPayload = [
            'event_name'       => $eventName,
            'event_time'       => $eventTime,
            'event_id'         => $eventId,
            'action_source'    => 'website',
            'event_source_url' => $request->input('event_source_url', ''),
            'user_data'        => $userData,
        ];
        if (!empty($customData)) {
            $eventPayload['custom_data'] = $customData;
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/events',
            self::GRAPH_API_VERSION,
            $pixelId
        );

        try {
            $response = Http::timeout(10)->post($url, [
                'access_token' => $token,
                'data'         => [$eventPayload],
            ]);

            $body = $response->json();

            Log::info('[TrackingCAPI] Evento enviado', [
                'event_name'      => $eventName,
                'event_id'        => $eventId,
                'events_received' => $body['events_received'] ?? null,
                'http_status'     => $response->status(),
            ]);

            if (!$response->successful()) {
                Log::warning('[TrackingCAPI] Graph API retornou erro', [
                    'status' => $response->status(),
                    'body'   => $body,
                ]);
                return response()->json([
                    'ok'    => false,
                    'error' => 'graph_api_error',
                    'body'  => $body,
                ], 502);
            }

            return response()->json([
                'ok'              => true,
                'events_received' => $body['events_received'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            Log::error('[TrackingCAPI] Excecao ao enviar evento', [
                'event_name' => $eventName,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'error' => 'internal_error'], 500);
        }
    }
}
