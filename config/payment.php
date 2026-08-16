<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Gateway de Pagamento Padrão
    |--------------------------------------------------------------------------
    | Opções: pagarme | asaas | shipay
    */
    'default_gateway' => env('PAYMENT_DEFAULT_GATEWAY', 'pagarme'),

    /*
    |--------------------------------------------------------------------------
    | Pagar.me v5
    |--------------------------------------------------------------------------
    */
    'pagarme' => [
        'api_key' => env('PAGARME_API_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | E-mail de boas-vindas do ciclo "comprou -> tem acesso" (SEL-CICLO 12/08)
    |--------------------------------------------------------------------------
    | DESARMADO por ordem do dono: "só mandar e-mail depois que tiver certeza
    | que a empresa está 100%". Enquanto estiver false, o acesso continua sendo
    | criado e liberado normalmente — só o SellerWelcomeMail não sai, e fica
    | registrado em storage/logs/ciclo-acesso.log o que ele mandaria.
    |
    | PRA LIGAR (quando a empresa estiver 100%):
    |   1) no .env do backend:  PAGARME_WELCOME_MAIL_ENABLED=true
    |   2) sudo -u apifrn0001 /usr/local/lsws/lsphp83/bin/php artisan optimize:clear
    |   3) confira que a fila está rodando (QUEUE_CONNECTION=database): o e-mail
    |      é enfileirado com ->queue(); quem entrega é o worker.
    | Pra desligar de novo: volte pra false e repita o passo 2.
    |
    | Vale pros DOIS caminhos de venda: webhook (PIX/boleto) e checkout de cartão.
    */
    'welcome_mail_enabled' => env('PAGARME_WELCOME_MAIL_ENABLED', false),

    'asaas_webhook_token' => env('ASAAS_WEBHOOK_TOKEN'),
];
