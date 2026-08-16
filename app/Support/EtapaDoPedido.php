<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * MUL-341 — em que etapa o pedido esta, decidido num lugar so.
 *
 * O painel do seller mostrava "entregue" e o do fornecedor mostrava o caminhao apagado, no mesmo
 * pedido. Nao era cache nem defasagem: cada painel tinha a propria regra.
 *
 *   seller  (Orders.tsx)      normalize(status) — aceita completed, entregue, delivered
 *   admin   (AdminOrders.tsx) status === 'delivered' — literal
 *
 * Como 1.968 pedidos da Shopee chegam com status = 'completed', o seller via entregue e o admin
 * via cinza. Mesmo dado, duas leituras.
 *
 * A tentacao e copiar o normalize do seller para o admin. Isso conserta hoje e volta a divergir
 * no dia em que alguem mexer num dos dois. E nao resolveria o fundo do problema: NENHUM campo
 * sozinho diz a verdade.
 *
 *   status='paid'      canonical='created'     8.392 — o cru esta na frente
 *   status='paid'      canonical='delivered'      63 — o canonical esta na frente
 *   status='shipped'   canonical='pending'        25 — o cru esta na frente
 *
 * E delivered_at so existe em 25 dos 1.967 entregues, entao nem os carimbos salvam.
 *
 * Entao a regra e: olhar TODOS os sinais e ficar com o mais avancado. Uma vez, aqui.
 */
final class EtapaDoPedido
{
    /** Ordem de avanco. Quem chegou mais longe manda. */
    private const ORDEM = ['pending' => 0, 'paid' => 1, 'shipped' => 2, 'delivered' => 3];

    /** Valores crus do marketplace traduzidos. Vinha do normalize() do front do seller. */
    private const CRU = [
        'pending' => 'pending', 'pendente' => 'pending', 'aguardando' => 'pending',
        'pending_payment' => 'pending', 'awaiting_payment' => 'pending', 'created' => 'pending',

        'paid' => 'paid', 'pago' => 'paid', 'approved' => 'paid', 'aprovado' => 'paid',
        'ready_to_ship' => 'paid', 'processed' => 'paid', 'processing' => 'paid',
        'awaiting_shipment' => 'paid',

        'shipped' => 'shipped', 'enviado' => 'shipped', 'in_transit' => 'shipped',
        'to_confirm_receive' => 'shipped',

        'delivered' => 'delivered', 'entregue' => 'delivered', 'completed' => 'delivered',
    ];

    /**
     * Cancelado de verdade. `to_return` e `returned` NAO entram: o pedido foi enviado e a
     * mercadoria esta voltando — e devolucao, nao cancelamento. Mostrar "cancelado" ali esconde
     * que existe um prazo correndo e uma mercadoria em transito.
     */
    private const CANCELADO = ['cancelled', 'canceled', 'cancelado', 'refused', 'refunded'];

    /**
     * @param  object  $o  linha de orders (model ou stdClass)
     * @return array{etapa:string,rotulo:string,cancelado:bool,devolucao:?string}
     */
    public static function resolver(object $o, ?string $devolucao = null): array
    {
        $cru       = strtolower((string) ($o->status ?? ''));
        $canonical = strtolower((string) ($o->canonical_status ?? ''));

        if (in_array($cru, self::CANCELADO, true) || in_array($canonical, self::CANCELADO, true)) {
            return ['etapa' => 'cancelled', 'rotulo' => 'Cancelado', 'cancelado' => true, 'devolucao' => $devolucao];
        }

        // cada sinal vota; fica o mais avancado
        $candidatos = [
            self::CRU[$cru] ?? null,
            self::CRU[$canonical] ?? null,
            ! empty($o->delivered_at) ? 'delivered' : null,
            ! empty($o->shipped_at)   ? 'shipped'   : null,
            ! empty($o->paid_at)      ? 'paid'      : null,
        ];

        // Existir devolucao e prova de que a mercadoria saiu: nao se devolve o que nunca foi
        // enviado. Sem isso o MUL-260728-68B9 (status='to_return', canonical='processing')
        // aparecia como "Confirmado" com o produto ja voltando pelo correio.
        if ($devolucao !== null) {
            $candidatos[] = 'shipped';
        }

        $etapa = 'pending';
        foreach ($candidatos as $c) {
            if ($c !== null && self::ORDEM[$c] > self::ORDEM[$etapa]) {
                $etapa = $c;
            }
        }

        $rotulos = ['pending' => 'Pendente', 'paid' => 'Confirmado', 'shipped' => 'Enviado', 'delivered' => 'Entregue'];

        return [
            'etapa'     => $etapa,
            'rotulo'    => $rotulos[$etapa],
            'cancelado' => false,
            'devolucao' => $devolucao,
        ];
    }

    /**
     * Em que pe esta a devolucao, para o caminhao de volta.
     *
     *   null          sem devolucao
     *   'aberta'      aberta, a mercadoria ainda nao saiu de volta   -> laranja
     *   'em_transito' mercadoria voltando                            -> amarelo, caminhao invertido
     *   'finalizada'  encerrada, ou a mercadoria ja chegou           -> verde, caminhao invertido
     */
    public static function devolucaoDe(?int $orderId, ?string $externalOrderId): ?string
    {
        if (! $orderId && ! $externalOrderId) {
            return null;
        }

        $d = DB::table('marketplace_returns')
            ->where(function ($q) use ($orderId, $externalOrderId) {
                if ($orderId)         { $q->where('order_id', $orderId); }
                if ($externalOrderId) { $q->orWhere('order_sn', $externalOrderId); }
            })
            ->orderByDesc('marketplace_created_at')
            ->first();

        if (! $d) {
            return null;
        }

        // a mercadoria de volta encerra, seja qual for o status
        if ($d->is_arrived_at_warehouse) {
            return 'finalizada';
        }

        // CANCELLED e o oposto de finalizada: o comprador desistiu, nada voltou, nada foi
        // estornado. O pedido volta a se comportar como pedido normal.
        if ($d->status === 'CANCELLED') {
            return 'cancelada';
        }

        if (in_array($d->status, ['ACCEPTED', 'CLOSED'], true)) {
            return 'finalizada';
        }

        return $d->needs_logistics ? 'em_transito' : 'aberta';
    }

    /**
     * O mesmo, para uma pagina inteira de pedidos — uma consulta, nao uma por linha.
     *
     * @param  array<int,int>  $orderIds
     * @return array<int,string>  id do pedido => estado da devolucao
     */
    public static function devolucoesDe(array $orderIds): array
    {
        if (! $orderIds) {
            return [];
        }

        $mapa = [];

        // mais recente por ultimo: sobrescreve e sobra a que vale
        $linhas = DB::table('marketplace_returns')
            ->whereIn('order_id', $orderIds)
            ->orderBy('marketplace_created_at')
            ->get(['order_id', 'status', 'needs_logistics', 'is_arrived_at_warehouse']);

        foreach ($linhas as $d) {
            // mesma regra do devolucaoDe(): cancelada nao e finalizada
            $mapa[(int) $d->order_id] = match (true) {
                (bool) $d->is_arrived_at_warehouse                     => 'finalizada',
                $d->status === 'CANCELLED'                             => 'cancelada',
                in_array($d->status, ['ACCEPTED', 'CLOSED'], true)     => 'finalizada',
                (bool) $d->needs_logistics                             => 'em_transito',
                default                                                => 'aberta',
            };
        }

        return $mapa;
    }
}
