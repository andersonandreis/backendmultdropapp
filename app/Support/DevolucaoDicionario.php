<?php

namespace App\Support;

/**
 * MUL-340 — os codigos de devolucao da Shopee traduzidos, num lugar so.
 *
 * Estavam duplicados entre a API (App\Support\MontaEtapasDeDevolucao) e a timeline do admin
 * (App\Filament\Resources\OrderResource). As duas copias ja tinham divergido: a da API ganhou
 * ITEM_NOT_FIT, OUTER_DAMAGED_PACKAGE, SUSPICIOUS_PARCEL e os status CLOSED/REQUESTED/
 * SELLER_DISPUTE/JUDGING, e a do Filament nao.
 *
 * Codigo que nao esta aqui aparece cru na tela. Quando surgir um, e aqui que se acrescenta.
 */
final class DevolucaoDicionario
{
    /** Por que o comprador devolveu. */
    public const MOTIVOS = [
        'WRONG_ITEM'            => 'produto errado',
        'ITEM_MISSING'          => 'item faltando',
        'CHANGE_MIND'           => 'arrependimento do comprador',
        'FUNCTIONAL_DMG'        => 'defeito',
        'DAMAGED_OTHERS'        => 'avaria no transporte',
        'NOT_RECEIPT'           => 'comprador diz que nao recebeu',
        'ITEM_NOT_FIT'          => 'nao serviu',
        'OUTER_DAMAGED_PACKAGE' => 'embalagem externa danificada',
        'SUSPICIOUS_PARCEL'     => 'encomenda suspeita',
    ];

    /**
     * Motivos que sao culpa da expedicao — o fornecedor mandou a coisa errada ou esqueceu item.
     *
     * NAO inclui avaria nem embalagem danificada: essas acontecem no transporte e a conta seria
     * de outro. Este e o recorte que ja foi medido (R$ 14.037,98 no WL); mudar o criterio muda o
     * numero, entao mude de proposito, nunca de passagem.
     */
    public const ERRO_DE_EXPEDICAO = ['WRONG_ITEM', 'ITEM_MISSING'];

    /**
     * Em que pe esta a devolucao. Todo status precisa cair aqui: sem desfecho a timeline para no
     * prazo e o pedido parece em aberto para sempre.
     *
     * [rotulo, explicacao, icone, cor]
     */
    public const DESFECHOS = [
        'ACCEPTED'       => ['aceita',        'Valor estornado ao comprador',                      '✅', '#16a34a'],
        'CANCELLED'      => ['cancelada',     'A devolucao nao seguiu',                            '🚫', '#6b7280'],
        'CLOSED'         => ['encerrada',     'O marketplace fechou o caso',                       '🔒', '#64748b'],
        'PROCESSING'     => ['em analise',    'Aguardando o marketplace',                          '⏳', '#8b5cf6'],
        'REQUESTED'      => ['solicitada',    'O comprador abriu e aguarda resposta',              '📩', '#f97316'],
        'SELLER_DISPUTE' => ['contestada',    'Contestacao enviada — aguardando o marketplace',    '⚖️', '#0ea5e9'],
        'JUDGING'        => ['em julgamento', 'O marketplace esta decidindo',                      '⚖️', '#eab308'],
    ];

    public static function motivo(?string $codigo): string
    {
        return self::MOTIVOS[$codigo] ?? ($codigo ?: 'nao informado');
    }

    public static function ehErroDeExpedicao(?string $codigo): bool
    {
        return in_array($codigo, self::ERRO_DE_EXPEDICAO, true);
    }
}