<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        /*
         * SEL-EMAILTOKFY (12/08): conta SMTP PROPRIA da marca Tokfy.
         * O e-mail de acesso do cliente Tokfy nao pode sair de
         * gabriel@seller.global — o remetente entregaria a outra marca no
         * cabecalho, e o dono proibiu qualquer rastro de seller.global.
         * Credencial real da caixa suporte@tokfy.io (a mesma que o backend
         * api.tokfy.io ja usa). O dominio tokfy.io tem SPF (ip4:66.94.100.155)
         * e assinatura DKIM (*@tokfy.io no SigningTable) neste servidor, entao
         * o envio autentica pela propria marca.
         * Mesmo formato do mailer 'smtp' acima de proposito — nao inventar
         * chave nova pra nao divergir do que ja funciona.
         */
        'smtp_tokfy' => [
            'transport' => 'smtp',
            'scheme' => env('TOKFY_MAIL_SCHEME'),
            'url' => env('TOKFY_MAIL_URL'),
            'host' => env('TOKFY_MAIL_HOST', env('MAIL_HOST', '127.0.0.1')),
            'port' => env('TOKFY_MAIL_PORT', env('MAIL_PORT', 2525)),
            'username' => env('TOKFY_MAIL_USERNAME'),
            'password' => env('TOKFY_MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('TOKFY_MAIL_EHLO_DOMAIN', 'api.tokfy.io'),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

    /*
     * SEL-EMAILTOKFY: remetente global da marca Tokfy. Fica separado de
     * 'from' de proposito — SmtpConfigService sobrescreve 'mail.from.*' com o
     * que estiver na tabela settings, e isso NAO pode vazar pro Tokfy.
     */
    'tokfy_from' => [
        'address' => env('TOKFY_MAIL_FROM_ADDRESS', 'suporte@tokfy.io'),
        'name' => env('TOKFY_MAIL_FROM_NAME', 'Tokfy'),
    ],

    // MUL-221: e-mail de boas-vindas desativado por padrao (decisao Ruan 11/07)
    'welcome_enabled' => env('WELCOME_MAIL_ENABLED', false),

];
