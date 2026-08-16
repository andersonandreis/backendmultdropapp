<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'legacy_sync_enabled' => env('LEGACY_SYNC_ENABLED', true),

    'name' => env('APP_NAME', 'Laravel'),
    // SEL-372: supplier local do WL (fallback SEL-077/relay Bling); env() nao funciona com config cacheada
    'local_supplier_id' => (int) env('LOCAL_SUPPLIER_ID', 0),

    'tenant' => env('APP_TENANT', 'sellerglobal'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),
    'legacy_admin_integrations' => (bool) env('LEGACY_ADMIN_INTEGRATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),
    'frontend_url'          => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),
    'oauth_relay_url'       => env('OAUTH_RELAY_URL', null),
    'oauth_relay_trusted_domains' => array_filter(array_map('trim', explode(',', env('OAUTH_RELAY_TRUSTED_DOMAINS', '')))),
    'session_cookie_name'   => env('SESSION_COOKIE_NAME', 'app_token'),

    // NOV-061: chave compartilhada com o sistema legado (goolhub.io) para chamadas internas.
    // Aceita INTERNAL_BRIDGE_KEY (novo) ou GOOLHUB_BRIDGE_KEY (legado) como fallback.
    'internal_bridge_key'   => env('INTERNAL_BRIDGE_KEY', env('GOOLHUB_BRIDGE_KEY')),
    'session_cookie_domain' => env('SESSION_COOKIE_DOMAIN', null),
    'drop_storefront_base'  => env('DROP_STOREFRONT_BASE', 'https://loja.hubai.io'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'America/Sao_Paulo'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'pt_BR'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'pt_BR'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'gabriel_api_token' => env('GABRIEL_API_TOKEN', ''),

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
