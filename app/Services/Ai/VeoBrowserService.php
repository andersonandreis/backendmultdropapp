<?php

namespace App\Services\Ai;

/**
 * SEL-486 — Google Flow / Veo via NAVEGADOR (Playwright + sessao Google logada).
 *
 * O "carro" (pipeline/Studio/fila) NAO muda: quem troca e so o MOTOR. Este service
 * estende KlingBrowserService e herda TODA a camada de entrada ja endurecida ticket
 * a ticket (SEL-381/402/419/468/485):
 *   - garanteIdioma()  (pt-BR/es/en no prompt, evita narracao em ingles)
 *   - garanteCameraNoProduto()  (produto e a estrela, camera em movimento)
 *   - recusa barata e legivel (sem imagem / sem roteiro) ANTES de ocupar o worker
 *   - fila priority pra admin, cache de estado, resposta compativel com a API Kling
 *   - imageToVideo / textToVideo / multiImageToVideo herdados sem alteracao
 *
 * A UNICA diferenca real esta no WORKER Node que roda no fim. Os metodos herdados
 * enfileiram KlingBrowserGenerateJob na fila `kling-browser` (a MESMA que o worker
 * supervisor sellerapp-kling-browser ja consome, RUNNING hoje) — e ESSE job escolhe
 * o script Node por config('services.video_engine'):
 *     'veo'   -> veo_generate.js  (Google Flow / Veo, 9:16, audio pt-BR nativo)
 *     'kling' -> generate_video.js (comportamento atual, intocado)
 * Ou seja: com VIDEO_ENGINE=veo, todo pedido que hoje ia pro Kling passa a sair pelo
 * Veo, SEM tocar em nenhum chamador, SEM job novo e SEM supervisor novo.
 *
 * Por que ESTENDER e nao reescrever: as validacoes de payload (9:16, duracao minima,
 * idioma, camera no produto) sao regras de PRODUTO do Ruan, nao regras do Kling.
 * Valem igual pro Veo. Reusar evita divergir e reescrever o poll de estado.
 *
 * Compatibilidade de estado: mantem o namespace de cache `kling_browser:{taskId}` que
 * getVideoTask()/setState() herdados leem/escrevem. So UM motor fica ligado por vez
 * (o binding decide), o taskId e uuid unico -> nao ha colisao.
 *
 * Telas que o worker do Veo AINDA nao dirige (lipSync, motionControl, omni com video)
 * continuam caindo nos fallbacks legiveis herdados. image2video, text2video e
 * cena-com-referencias sao os caminhos vivos.
 */
class VeoBrowserService extends KlingBrowserService
{
    /**
     * Ligado quando VEO_BROWSER_ENABLED=true. O binding em AppServiceProvider so
     * injeta este service quando ALEM disso video_engine === 'veo' — os dois
     * precisam concordar, pra ninguem ligar o Veo pela metade.
     */
    public function isConfigured(): bool
    {
        return (bool) config('services.veo.browser_enabled', false);
    }

    /**
     * Nome do provider pra logging/telemetria. Diferente de 'kling_browser' pra dar
     * pra separar custo/uso dos dois motores nas metricas.
     */
    public function providerName(): string
    {
        return 'veo_browser';
    }

    /**
     * Health da sessao Google (o AdminKlingBrowserController e o
     * veo-browser:health-check leem isto). Mesma forma do health() do Kling, mas
     * apontando pra sessao Google e pro worker do Veo.
     *
     * ATENCAO: session_exists=true NAO garante sessao VIVA — os cookies podem estar
     * no arquivo e o Google ter revogado a sessao server-side. A prova de vida real
     * e `php artisan veo-browser:health-check --live` (bate no /v1/credits
     * autenticado). Aqui fica so o barato (arquivo/idade), sem abrir navegador.
     */
    public function health(): array
    {
        $sessionPath = config('services.veo.browser_session_path')
            ?: '/home/api.seller.global/storage/kling-browser/google-session.json';
        $sessionExists = is_file($sessionPath);

        return [
            'engine'         => 'veo',
            'enabled'        => $this->isConfigured(),
            'active'         => config('services.video_engine') === 'veo',
            'session_path'   => $sessionPath,
            'session_exists' => $sessionExists,
            'session_age_s'  => $sessionExists ? (time() - filemtime($sessionPath)) : null,
            'account_email'  => config('services.veo.browser_account_email'),
            'plan'           => config('services.veo.browser_plan', 'Pro'),
            'model'          => config('services.veo.model', 'Omni Flash'),
            'project_url'    => config('services.veo.project_url'),
            'worker_js'      => config('services.veo.browser_worker_js'),
        ];
    }
}
