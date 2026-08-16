<?php

namespace App\Services\Ai;

use App\Contracts\LlmContract;
use App\Models\AiEngine;
use Illuminate\Support\Facades\Log;

/**
 * SEL-461 -- DicloakGptUIAdapter
 *
 * LLM via UI automation Windows dentro do perfil DICloak SN 7 (ChatGPT ILIMITADO).
 * NAO usa API key. NAO abre painel developer. SO perfil logado DICloak.
 *
 * Fluxo:
 *   1. POST {vm_api_base}/api/llm/chat -> vm-job-server.py na VM (Session 1)
 *   2. vm-job-server.py roda gpt_ui_worker.py sincronamente:
 *      a. Verifica se janela GinsBrowser ChatGPT ja esta aberta
 *      b. Se nao: abre perfil SN 7 (envId 2046389146560139265) via CDP DICloak SPA
 *      c. Aguarda ginsbrowser abrir em chatgpt.com
 *      d. Navega para chatgpt.com/?model=gpt-4o via Ctrl+L
 *      e. Cola prompt no textarea via clipboard
 *      f. Submete com Ctrl+Enter
 *      g. Poll DOM [data-message-author-role="assistant"] ate estabilizar 3s
 *      h. Extrai innerText e retorna
 *   3. PHP recebe {ok, text, took_s} e retorna string
 *
 * Config em ai_engines.config_json (engine id=22, provider='dicloak-gpt-ui'):
 *   {
 *     "vm_api_base":    "https://dicloak-vm-api.hubai.club",
 *     "vm_api_secret":  "sel458-hubai-dicloak",
 *     "profile_name":   "ChatGPT ILIMITADO",
 *     "env_id":         "2046389146560139265",
 *     "backup_env_id":  "2047044067198484482",
 *     "timeout_s":      60,
 *     "quota_per_day":  500
 *   }
 *
 * Fallback (AiEnginePool):
 *   - Se vm_api nao responder -> DicloakNotConfiguredException -> pool tenta proximo engine
 *   - Se ChatGPT UI falhar (janela nao abre) -> RuntimeException -> recordFailure -> proximo engine
 *   - NUNCA cai para API paga -- pool escolhe proximo engine disponivel
 */
class DicloakGptUIAdapter implements LlmContract
{
    /** Secret padrao compartilhado com vm-job-server.py */
    private const DEFAULT_SECRET = 'sel458-hubai-dicloak';

    /** Base URL padrao do vm-job-server (via cloudflare tunnel) */
    private const DEFAULT_VM_API = 'https://dicloak-vm-api.hubai.club';

    /** Timeout HTTP da requisicao para a VM (inclui tempo de resposta do ChatGPT) */
    private const HTTP_TIMEOUT_S = 150;

    public function __construct(private AiEngine $engine) {}

    /**
     * Envia mensagens para ChatGPT via UI automation DICloak e retorna resposta.
     *
     * @param  array  $messages  [{role: user|assistant|system, content: string}]
     * @param  float  $temperature  ignorado (UI nao tem controle de temp)
     * @param  int    $maxTokens    ignorado (UI nao tem controle de tokens)
     * @return string resposta do ChatGPT
     * @throws DicloakNotConfiguredException se VM nao acessivel
     * @throws \RuntimeException se ChatGPT UI falhar
     */
    public function chat(array $messages, float $temperature = 0.7, int $maxTokens = 800): string
    {
        $cfg       = $this->engine->config_json ?? [];
        $vmApiBase = rtrim($cfg['vm_api_base'] ?? env('VM_JOB_API_BASE', self::DEFAULT_VM_API), '/');
        $secret    = $cfg['vm_api_secret']  ?? env('VM_JOB_SECRET', self::DEFAULT_SECRET);
        $profileName = $cfg['profile_name'] ?? 'ChatGPT ILIMITADO';
        $envId     = $cfg['env_id']         ?? '2046389146560139265';
        $timeoutS  = (int) ($cfg['timeout_s'] ?? 60);

        if (empty($vmApiBase)) {
            throw new DicloakNotConfiguredException(
                'DicloakGptUIAdapter: vm_api_base nao configurado. ' .
                'Definir VM_JOB_API_BASE no .env ou config_json do engine id=' . $this->engine->id
            );
        }

        $T0 = microtime(true);

        Log::info('[SEL-461][DicloakGptUI] iniciando request', [
            'engine_id'    => $this->engine->id,
            'engine_name'  => $this->engine->name,
            'profile_name' => $profileName,
            'env_id'       => $envId,
            'messages_cnt' => count($messages),
        ]);

        // 1. Verificar health do vm-job-server
        $this->checkVmHealth($vmApiBase, $secret);

        // 2. Enviar request de chat para a VM (sincrono -- vm-job-server aguarda resposta)
        $url  = $vmApiBase . '/api/llm/chat';
        $body = json_encode([
            'messages'     => $messages,
            'timeout_s'    => $timeoutS,
            'profile_name' => $profileName,
            'env_id'       => $envId,
        ]);

        $response = $this->httpPost($url, $body, $secret, self::HTTP_TIMEOUT_S);

        $tookS = round(microtime(true) - $T0, 1);

        if (empty($response['ok']) || empty($response['text'])) {
            $error = $response['error'] ?? 'resposta_vazia';
            Log::warning('[SEL-461][DicloakGptUI] request falhou', [
                'engine_id' => $this->engine->id,
                'error'     => $error,
                'took_s'    => $tookS,
            ]);

            // Erros especificos mapeados
            if (str_contains($error, 'window_not_found')) {
                throw new \RuntimeException(
                    'dicloak_gpt_ui_window_not_found: perfil ' . $envId . ' nao abriu janela ChatGPT. ' .
                    'Verifique se DICloak esta rodando na VM e o perfil SN 7 esta disponivel.'
                );
            }
            if (str_contains($error, 'empty_response')) {
                throw new \RuntimeException(
                    'dicloak_gpt_ui_empty_response: ChatGPT nao retornou texto. ' .
                    'Verifique se o perfil esta logado em chatgpt.com.'
                );
            }

            throw new \RuntimeException(
                'dicloak_gpt_ui_failed: ' . mb_substr($error, 0, 200)
            );
        }

        Log::info('[SEL-461][DicloakGptUI] resposta OK', [
            'engine_id' => $this->engine->id,
            'chars'     => $response['chars'] ?? strlen($response['text']),
            'took_s'    => $tookS,
            'vm_took_s' => $response['took_s'] ?? null,
        ]);

        return $response['text'];
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    /**
     * Verifica se o vm-job-server esta acessivel.
     * Lanca DicloakNotConfiguredException se nao responder.
     */
    private function checkVmHealth(string $vmApiBase, string $secret): void
    {
        $url = $vmApiBase . '/health';
        try {
            $resp = $this->httpGet($url, $secret, 8);
            if (empty($resp['ok'])) {
                throw new DicloakNotConfiguredException(
                    'DicloakGptUI: vm-job-server health retornou not ok: ' . json_encode($resp)
                );
            }
        } catch (DicloakNotConfiguredException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DicloakNotConfiguredException(
                'DicloakGptUI: vm-job-server inacessivel em ' . $vmApiBase . '. ' .
                'Verificar tunnel cloudflare e schtask DicloakVmApi na VM. Erro: ' .
                mb_substr($e->getMessage(), 0, 200)
            );
        }
    }

    private function httpPost(string $url, string $body, string $secret, int $timeoutS): array
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nX-Job-Secret: {$secret}\r\n",
                'content' => $body,
                'timeout' => $timeoutS,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $resp = @file_get_contents($url, false, $context);
        if ($resp === false) {
            $err = error_get_last();
            throw new \RuntimeException('dicloak_gpt_ui_http_post_failed: ' . ($err['message'] ?? $url));
        }

        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(
                'dicloak_gpt_ui_bad_json: ' . mb_substr($resp, 0, 200)
            );
        }

        return $decoded;
    }

    private function httpGet(string $url, string $secret, int $timeoutS = 10): array
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "X-Job-Secret: {$secret}\r\n",
                'timeout' => $timeoutS,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $resp = @file_get_contents($url, false, $context);
        if ($resp === false) {
            $err = error_get_last();
            throw new \RuntimeException('http_get_failed: ' . ($err['message'] ?? $url));
        }

        $decoded = json_decode($resp, true);
        return is_array($decoded) ? $decoded : [];
    }
}
