<?php

/**
 * SEL-417: Configuração do módulo de assinaturas de vídeo IA.
 *
 * Feature flag VIDEO_SUBSCRIPTIONS_ENABLED (default true):
 *   - true  → guard ativo; bloqueia quando usuário esgota o limite mensal
 *   - false → guard transparente; canGenerate() sempre retorna true (backwards-compat)
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Assinaturas de vídeo habilitadas
    |--------------------------------------------------------------------------
    | Quando false, VideoSubscriptionGuard::canGenerate() retorna true para
    | qualquer usuário, preservando o comportamento anterior (sem limite).
    */
    'subscriptions_enabled' => (bool) env('VIDEO_SUBSCRIPTIONS_ENABLED', true),

];
