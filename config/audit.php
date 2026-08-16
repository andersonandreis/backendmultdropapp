<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AuditUnpaidOrders guard
    |--------------------------------------------------------------------------
    |
    | Quando false (padrao), o AuditUnpaidOrdersJob nao executa — evita
    | cobranca dupla de pedidos quitados no legado.
    |
    | Setar AUDIT_UNPAID_ORDERS_ENABLED=true apenas quando o fluxo de
    | pagamento no novo sistema for a fonte de verdade (sem legado paralelo).
    |
    */
    'unpaid_orders_enabled' => (bool) env('AUDIT_UNPAID_ORDERS_ENABLED', false),

];
