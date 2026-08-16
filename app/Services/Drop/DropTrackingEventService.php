<?php

namespace App\Services\Drop;

use App\Models\Drop\DropAttributionSession;
use App\Models\Drop\DropOrder;
use App\Models\Drop\DropTrackingPixel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gerencia rastreamento server-side (CAPI) para o modulo Drop Internacional.
 *
 * Eventos suportados: PageView, ViewContent, AddToCart, InitiateCheckout,
 *                     Purchase, Lead.
 */
class DropTrackingEventService
{
    // =========================================================================
    // Sessao de atribuicao
    // =========================================================================

    /**
     * Cria ou atualiza uma sessao de atribuicao.
     * Chamado quando um visitante acessa a loja (via script de tracking).
     *
     * @param  int   $clientId
     * @param  array $params  {session_id, fbp, fbc, gclid, ttclid, utm_source,
     *                          utm_campaign, utm_medium, utm_content, utm_term,
     *                          landing_url, user_agent, ip}
     * @return DropAttributionSession
     */
    public function trackSession(int $clientId, array $params): DropAttributionSession
    {
        $sessionId = $params['session_id'] ?? null;

        if (!$sessionId) {
            throw new \InvalidArgumentException('session_id e obrigatorio para trackSession');
        }

        // Anonimizar IP antes de persistir
        $ipHash = null;
        if (!empty($params['ip'])) {
            $ipHash = hash('sha256', trim($params['ip']));
        }

        $data = [
            'client_id'    => $clientId,
            'session_id'   => $sessionId,
            'fbp'          => $params['fbp'] ?? null,
            'fbc'          => $params['fbc'] ?? null,
            'gclid'        => $params['gclid'] ?? null,
            'ttclid'       => $params['ttclid'] ?? null,
            'utm_source'   => $params['utm_source'] ?? null,
            'utm_campaign' => $params['utm_campaign'] ?? null,
            'utm_medium'   => $params['utm_medium'] ?? null,
            'utm_content'  => $params['utm_content'] ?? null,
            'utm_term'     => $params['utm_term'] ?? null,
            'landing_url'  => $params['landing_url'] ?? null,
            'user_agent'   => $params['user_agent'] ?? null,
            'ip_hash'      => $ipHash,
        ];

        $session = DropAttributionSession::updateOrCreate(
            ['session_id' => $sessionId, 'client_id' => $clientId],
            $data
        );

        Log::info('[DropTrackingEventService] Sessao de atribuicao registrada', [
            'session_id' => $sessionId,
            'client_id'  => $clientId,
            'utm_source' => $params['utm_source'] ?? null,
        ]);

        return $session;
    }

    // =========================================================================
    // Disparo de eventos
    // =========================================================================

    /**
     * Dispara um evento de conversao server-side para todos os pixels ativos
     * do cliente.
     *
     * @param  string $eventName  Ex: 'Purchase', 'ViewContent', 'AddToCart'
     * @param  int    $clientId
     * @param  array  $eventData  {value, currency, order_id, fbp, fbc,
     *                             email, phone, event_id}
     * @return array  {sent: int, errors: array}
     */
    public function fireEvent(string $eventName, int $clientId, array $eventData): array
    {
        $pixels = DropTrackingPixel::where('client_id', $clientId)
            ->active()
            ->get();

        if ($pixels->isEmpty()) {
            Log::debug('[DropTrackingEventService] Nenhum pixel ativo para cliente', [
                'client_id'  => $clientId,
                'event_name' => $eventName,
            ]);
            return ['sent' => 0, 'errors' => []];
        }

        $sent   = 0;
        $errors = [];

        foreach ($pixels as $pixel) {
            try {
                $success = match ($pixel->platform) {
                    'meta'   => $this->fireMetaEvent($pixel, $eventName, $eventData),
                    'google' => $this->fireGoogleEvent($pixel, $eventName, $eventData),
                    'tiktok' => $this->fireTikTokEvent($pixel, $eventName, $eventData),
                    default  => false,
                };

                if ($success) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'pixel_id' => $pixel->id,
                    'platform' => $pixel->platform,
                    'error'    => $e->getMessage(),
                ];

                Log::error('[DropTrackingEventService] Erro ao disparar evento', [
                    'pixel_id'   => $pixel->id,
                    'platform'   => $pixel->platform,
                    'event_name' => $eventName,
                    'client_id'  => $clientId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        Log::info('[DropTrackingEventService] Evento disparado', [
            'event_name' => $eventName,
            'client_id'  => $clientId,
            'sent'       => $sent,
            'errors'     => count($errors),
        ]);

        return ['sent' => $sent, 'errors' => $errors];
    }

    /**
     * Atalho: dispara evento Purchase a partir de um DropOrder existente.
     * Chamado pelo ShopifyWebhookHandler apos criar o pedido.
     */
    public function firePurchaseEvent(DropOrder $order): array
    {
        $eventData = [
            'value'    => (float) $order->total_amount,
            'currency' => $order->currency ?? 'USD',
            'order_id' => $order->shopify_order_id,
            'fbp'      => $order->fbp,
            'fbc'      => $order->fbc,
            'email'    => $order->customer_email,
            'phone'    => $order->customer_phone,
            'event_id' => $order->event_id ?? uniqid('purchase_', true),
        ];

        return $this->fireEvent('Purchase', $order->client_id, $eventData);
    }

    // =========================================================================
    // Handlers privados por plataforma
    // =========================================================================

    /**
     * Envia evento via Meta Conversions API (CAPI).
     * Doc: https://developers.facebook.com/docs/marketing-api/conversions-api
     */
    private function fireMetaEvent(DropTrackingPixel $pixel, string $eventName, array $data): bool
    {
        $accessToken = $pixel->access_token;

        if (!$accessToken) {
            Log::warning('[DropTrackingEventService] Meta pixel sem access_token, pulando', [
                'pixel_id' => $pixel->id,
            ]);
            return false;
        }

        $eventId   = $data['event_id'] ?? uniqid('evt_', true);
        $eventTime = time();

        // Montar user_data — hash SHA256 para PII (exigido pelo Meta)
        $userData = [];
        if (!empty($data['fbp'])) {
            $userData['fbp'] = $data['fbp'];
        }
        if (!empty($data['fbc'])) {
            $userData['fbc'] = $data['fbc'];
        }
        if (!empty($data['email'])) {
            $userData['em'] = hash('sha256', strtolower(trim($data['email'])));
        }
        if (!empty($data['phone'])) {
            // Remove caracteres nao numericos antes do hash
            $phone = preg_replace('/\D/', '', $data['phone']);
            if ($phone) {
                $userData['ph'] = hash('sha256', $phone);
            }
        }

        $eventPayload = [
            'event_name'    => $eventName,
            'event_time'    => $eventTime,
            'event_id'      => $eventId,
            'action_source' => 'website',
            'user_data'     => $userData ?: (object) [],
        ];

        // custom_data para eventos de valor monetario
        if (in_array($eventName, ['Purchase', 'InitiateCheckout', 'AddToCart'], true)) {
            $eventPayload['custom_data'] = [
                'value'    => $data['value'] ?? 0,
                'currency' => strtoupper($data['currency'] ?? 'USD'),
            ];
            if (!empty($data['order_id'])) {
                $eventPayload['custom_data']['order_id'] = (string) $data['order_id'];
            }
        }

        $body = ['data' => [$eventPayload]];

        // test_event_code para validacao em ambiente sandbox do Meta
        if ($pixel->test_event_code) {
            $body['test_event_code'] = $pixel->test_event_code;
        }

        $url = sprintf(
            'https://graph.facebook.com/v18.0/%s/events',
            $pixel->pixel_id
        );

        $response = Http::withToken($accessToken)
            ->timeout(10)
            ->post($url, $body);

        if (!$response->successful()) {
            Log::error('[DropTrackingEventService] Meta CAPI retornou erro', [
                'pixel_id'   => $pixel->id,
                'event_name' => $eventName,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            return false;
        }

        Log::info('[DropTrackingEventService] Meta CAPI OK', [
            'pixel_id'   => $pixel->id,
            'event_name' => $eventName,
            'event_id'   => $eventId,
            'response'   => $response->json(),
        ]);

        return true;
    }

    /**
     * Stub para Google Ads Conversions API.
     * A implementacao completa requer OAuth2 + Google Ads API v14+.
     * Por ora apenas loga para rastreamento interno.
     */
    private function fireGoogleEvent(DropTrackingPixel $pixel, string $eventName, array $data): bool
    {
        Log::info('[DropTrackingEventService] Google Ads event stub (MVP)', [
            'pixel_id'   => $pixel->id,
            'pixel_tag'  => $pixel->pixel_id,
            'event_name' => $eventName,
            'value'      => $data['value'] ?? null,
            'currency'   => $data['currency'] ?? null,
            'order_id'   => $data['order_id'] ?? null,
        ]);

        // TODO: implementar Google Ads Conversions API com OAuth2 + Enhanced Conversions
        return true;
    }

    /**
     * Stub para TikTok Events API.
     */
    private function fireTikTokEvent(DropTrackingPixel $pixel, string $eventName, array $data): bool
    {
        Log::info('[DropTrackingEventService] TikTok Events API stub (MVP)', [
            'pixel_id'   => $pixel->id,
            'event_name' => $eventName,
            'value'      => $data['value'] ?? null,
        ]);

        // TODO: implementar TikTok Events API
        return true;
    }
}
