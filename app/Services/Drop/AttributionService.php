<?php

namespace App\Services\Drop;

use App\Models\Drop\DropAttributionSession;
use App\Models\Drop\DropOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servico de atribuicao de pedidos Drop Internacional.
 *
 * Responsabilidades:
 *  - Vincular sessao de tracking ao DropOrder recebido via webhook
 *  - Calcular ROAS estimado por campanha UTM
 *  - Relatorios de performance por canal (utm_source)
 */
class AttributionService
{
    // =========================================================================
    // Atribuicao de pedido
    // =========================================================================

    /**
     * Associa a sessao de atribuicao a um pedido Drop.
     *
     * Busca o session_id nos note_attributes do payload Shopify (ja extraidos
     * para o DropOrder). Quando encontra, copia fbp/fbc/gclid/utm_source para
     * o DropOrder — campos que podem ter ficado vazios se o pedido chegou sem
     * os parametros no nivel do pedido.
     *
     * @param  DropOrder $order
     * @return DropAttributionSession|null
     */
    public function attributeOrderToSession(DropOrder $order): ?DropAttributionSession
    {
        // session_id pode vir como utm_content ou campo dedicado nos note_attributes
        // O ShopifyWebhookHandler ja extrai utm_content; usamos como session_id
        $sessionId = $order->utm_content ?? null;

        if (!$sessionId) {
            Log::debug('[AttributionService] Pedido sem session_id (utm_content), sem atribuicao', [
                'drop_order_id' => $order->id,
            ]);
            return null;
        }

        $session = DropAttributionSession::where('session_id', $sessionId)
            ->where('client_id', $order->client_id)
            ->first();

        if (!$session) {
            Log::debug('[AttributionService] Sessao nao encontrada', [
                'session_id'    => $sessionId,
                'drop_order_id' => $order->id,
            ]);
            return null;
        }

        // Copiar campos de rastreamento para o pedido apenas se ainda vazios
        $updates = [];

        if (!$order->utm_source && $session->utm_source) {
            $updates['utm_source'] = $session->utm_source;
        }
        if (!$order->utm_campaign && $session->utm_campaign) {
            $updates['utm_campaign'] = $session->utm_campaign;
        }
        if (!$order->utm_medium && $session->utm_medium) {
            $updates['utm_medium'] = $session->utm_medium;
        }
        if (!$order->fbp && $session->fbp) {
            $updates['fbp'] = $session->fbp;
        }
        if (!$order->fbc && $session->fbc) {
            $updates['fbc'] = $session->fbc;
        }
        if (!$order->gclid && $session->gclid) {
            $updates['gclid'] = $session->gclid;
        }

        if (!empty($updates)) {
            $order->update($updates);
            Log::info('[AttributionService] DropOrder atualizado com dados da sessao', [
                'drop_order_id' => $order->id,
                'session_id'    => $sessionId,
                'fields_copied' => array_keys($updates),
            ]);
        }

        return $session;
    }

    // =========================================================================
    // ROAS por campanha
    // =========================================================================

    /**
     * Calcula ROAS estimado para uma campanha UTM no periodo informado.
     *
     * Como nao temos o custo real de anuncio integrado, o ROAS aqui e uma
     * estimativa de receita atribuida — util para comparar campanhas entre si.
     *
     * @param  int    $clientId
     * @param  string $utmCampaign
     * @param  string $startDate   Formato Y-m-d
     * @param  string $endDate     Formato Y-m-d
     * @return array  {campaign, orders, revenue, profit, roas_estimate}
     */
    public function calculateCampaignRoas(
        int $clientId,
        string $utmCampaign,
        string $startDate,
        string $endDate
    ): array {
        $row = DB::table('drop_orders')
            ->where('client_id', $clientId)
            ->where('utm_campaign', $utmCampaign)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->selectRaw('
                COUNT(*) as orders,
                COALESCE(SUM(total_amount), 0) as revenue,
                COALESCE(SUM(profit_estimate), 0) as profit
            ')
            ->first();

        $orders  = (int) ($row->orders ?? 0);
        $revenue = (float) ($row->revenue ?? 0);
        $profit  = (float) ($row->profit ?? 0);

        // ROAS estimado = receita / custo implicito (profit como proxy do custo)
        // Sem custo real, retornamos apenas receita e margem
        $roasEstimate = null;
        if ($profit > 0 && $revenue > 0) {
            $impliedCost  = $revenue - $profit;
            $roasEstimate = $impliedCost > 0 ? round($revenue / $impliedCost, 2) : null;
        }

        return [
            'campaign'      => $utmCampaign,
            'orders'        => $orders,
            'revenue'       => round($revenue, 2),
            'profit'        => round($profit, 2),
            'roas_estimate' => $roasEstimate,
            'start_date'    => $startDate,
            'end_date'      => $endDate,
        ];
    }

    // =========================================================================
    // Performance por canal
    // =========================================================================

    /**
     * Agrega performance de pedidos Drop por canal (utm_source) no periodo.
     *
     * @param  int    $clientId
     * @param  string $startDate  Formato Y-m-d
     * @param  string $endDate    Formato Y-m-d
     * @return array  Array de {source, orders, revenue, profit, avg_order_value}
     */
    public function getChannelPerformance(
        int $clientId,
        string $startDate,
        string $endDate
    ): array {
        $rows = DB::table('drop_orders')
            ->where('client_id', $clientId)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->selectRaw('
                COALESCE(utm_source, \'(direto)\') as source,
                COUNT(*) as orders,
                COALESCE(SUM(total_amount), 0) as revenue,
                COALESCE(SUM(profit_estimate), 0) as profit,
                COALESCE(AVG(total_amount), 0) as avg_order_value
            ')
            ->groupBy('utm_source')
            ->orderByDesc('revenue')
            ->get();

        return $rows->map(function ($row) {
            return [
                'source'          => $row->source,
                'orders'          => (int) $row->orders,
                'revenue'         => round((float) $row->revenue, 2),
                'profit'          => round((float) $row->profit, 2),
                'avg_order_value' => round((float) $row->avg_order_value, 2),
            ];
        })->toArray();
    }
}
