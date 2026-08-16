<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * MUL-340 — monta as etapas da devolucao de um pedido, prontas para exibir.
 *
 * Vive num trait porque dois painels precisam do mesmo dado: o do fornecedor
 * (SupplierAdminPanelController) e o do seller (OrderController, consumido pelo front em
 * multdrop.app). Duas copias divergiriam — foi o que aconteceu com meia duzia de rotinas neste
 * sistema, e o conserto de cada uma custou um dia.
 *
 * Devolve lista vazia em pedido sem devolucao. Um pedido pode ter mais de uma: o 134373 teve uma
 * cancelada as 18:17 e outra aceita as 22:48, quando o comprador reabriu.
 */
trait MontaEtapasDeDevolucao
{
    /** @return array<int,array<string,mixed>> */
    protected function etapasDeDevolucao(?int $orderId, ?string $externalOrderId): array
    {
        if (! $orderId && ! $externalOrderId) {
            return [];
        }
        $motivos = DevolucaoDicionario::MOTIVOS;
        $desfechos = DevolucaoDicionario::DESFECHOS;

        return DB::table('marketplace_returns')
            ->where(function ($q) use ($orderId, $externalOrderId) {
                if ($orderId)         { $q->where('order_id', $orderId); }
                if ($externalOrderId) { $q->orWhere('order_sn', $externalOrderId); }
            })
            ->orderBy('marketplace_created_at')
            ->get()
            ->map(function ($d) use ($motivos, $desfechos) {
                $etapas = [];

                $etapas[] = [
                    'etapa'     => 'aberta',
                    'titulo'    => 'Devolucao aberta',
                    'data'      => $d->marketplace_created_at,
                    'detalhe'   => 'Motivo: ' . ($motivos[$d->reason] ?? $d->reason ?? 'nao informado'),
                    'concluida' => true,
                ];

                if ($d->needs_logistics) {
                    $etapas[] = [
                        'etapa'     => $d->is_arrived_at_warehouse ? 'recebida' : 'em_transito',
                        'titulo'    => $d->is_arrived_at_warehouse
                            ? 'Mercadoria recebida de volta'
                            : 'Mercadoria em transito de volta',
                        'data'      => $d->is_arrived_at_warehouse ? $d->marketplace_updated_at : null,
                        'detalhe'   => $d->tracking_number
                            ? 'Rastreio: ' . $d->tracking_number
                            : 'Sem rastreio informado pelo marketplace',
                        'concluida' => (bool) $d->is_arrived_at_warehouse,
                    ];
                }

                // o prazo e onde se perde dinheiro: vencido, o estorno sai sem contestacao
                $prazo = $d->seller_evidence_deadline ?: $d->return_seller_due_date;
                if ($prazo) {
                    $vencido = \Carbon\Carbon::parse($prazo)->isPast();
                    $etapas[] = [
                        'etapa'     => $vencido ? 'prazo_vencido' : 'prazo_aberto',
                        'titulo'    => $vencido ? 'Prazo para contestar VENCIDO' : 'Prazo para contestar',
                        'data'      => $prazo,
                        'detalhe'   => $vencido
                            ? 'A devolucao segue sem contestacao'
                            : 'Contestar ate esta data, senao o estorno e automatico',
                        'concluida' => $vencido,
                        'urgente'   => ! $vencido,
                    ];
                }

                if (isset($desfechos[$d->status])) {
                    [$rotulo, $texto] = $desfechos[$d->status];
                    $etapas[] = [
                        'etapa'     => strtolower((string) $d->status),
                        'titulo'    => 'Devolucao ' . $rotulo,
                        'data'      => $d->marketplace_updated_at,
                        'detalhe'   => $texto,
                        'concluida' => true,
                    ];
                }

                return [
                    'return_sn'         => $d->return_sn,
                    'status'            => $d->status,
                    'motivo'            => $d->reason,
                    'motivo_texto'      => $motivos[$d->reason] ?? $d->reason,
                    'erro_de_expedicao' => DevolucaoDicionario::ehErroDeExpedicao($d->reason),
                    'valor_estornado'   => $d->refund_amount !== null ? (float) $d->refund_amount : null,
                    'mercadoria_volta'  => (bool) $d->needs_logistics,
                    'ja_chegou'         => (bool) $d->is_arrived_at_warehouse,
                    'rastreio'          => $d->tracking_number,
                    'aberta_em'         => $d->marketplace_created_at,
                    'etapas'            => $etapas,
                ];
            })
            ->values()
            ->all();
    }
}