<?php
namespace App\Services\Ai;

use App\Contracts\LlmContract;
use App\Models\AiEngine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * SEL-429 -- Adapter DICloak GPT (LLM via perfil ChatGPT no DICloak).
 *
 * Fluxo:
 *   1. Adquire lock Redis "dicloak_gpt_lock:{profileId}" (max 1 req por vez — ChatGPT UI nao suporta paralelo)
 *   2. Chama a API DICloak VM via tunnel pra abrir o perfil SN 7 (ChatGPT ILIMITADO)
 *      POST {tunnel_url}/dicloak-api/profile/{profileId}/open
 *      -> retorna { wsEndpoint: "ws://127.0.0.1:XXXX" }
 *   3. Passa o wsEndpoint pro worker Node.js gpt_cdp.js via ENV CDP_ENDPOINT
 *   4. Worker navega chatgpt.com (sessao ja logada), envia prompt, captura resposta
 *   5. Retorna texto; libera lock; fecha aba (NAO fecha o browser/perfil)
 *
 * Fallback automatico (AiEnginePool):
 *   - Se openProfile falhar -> DicloakNotConfiguredException -> pool tenta GeminiDirect
 *   - Se quota ChatGPT atingida -> log + Telegram -> RuntimeException -> pool tenta proximo
 *   - Se timeout (45s) -> RuntimeException -> recordFailure (cooldown 5 min) -> pool tenta proximo
 *
 * Config esperada em ai_engines.config_json (id=9):
 *   {
 *     "profile_id":   "2046389146560139265",  // SN 7 ChatGPT ILIMITADO
 *     "backup_ids":   ["2047044067198484482"], // SN 21 ChatGPT 2 (fallback intrafila)
 *     "tunnel_url":   "https://dicloak-vm.hubai.club",
 *     "timeout_s":    45,
 *     "worker_js":    "/home/api.seller.global/browser-worker/gpt_cdp.js",
 *     "worker_dir":   "/home/api.seller.global/browser-worker"
 *   }
 */
class DicloakGptAdapter implements LlmContract
{
    private const LOCK_TTL = 90; // segundos -- tempo max por request GPT

    public function __construct(private AiEngine $engine) {}

    public function chat(array $messages, float $temperature = 0.7, int $maxTokens = 800): string
    {
        $cfg       = $this->engine->config_json ?? [];
        $profileId = $cfg['profile_id'] ?? null;
        $tunnelUrl = rtrim($cfg['tunnel_url'] ?? (string) env('DICLOAK_TUNNEL_URL', ''), '/');
        $timeoutS  = (int) ($cfg['timeout_s'] ?? 45);
        $workerJs  = $cfg['worker_js']  ?? '/home/api.seller.global/browser-worker/gpt_cdp.js';
        $workerDir = $cfg['worker_dir'] ?? '/home/api.seller.global/browser-worker';

        if (empty($profileId)) {
            throw new DicloakNotConfiguredException(
                'DicloakGptAdapter: profile_id nao configurado no config_json do engine id=' . $this->engine->id
            );
        }
        if (empty($tunnelUrl)) {
            throw new DicloakNotConfiguredException(
                'DicloakGptAdapter: tunnel_url nao configurado (DICLOAK_TUNNEL_URL vazio)'
            );
        }

        $lockKey = 'dicloak_gpt_lock:' . $profileId;
        $lock    = Cache::lock($lockKey, self::LOCK_TTL);

        if (!$lock->block(30)) {
            // Tentar perfil de backup se houver
            $backupIds = $cfg['backup_ids'] ?? [];
            if (!empty($backupIds)) {
                $backupId  = $backupIds[0];
                $backupKey = 'dicloak_gpt_lock:' . $backupId;
                $bLock     = Cache::lock($backupKey, self::LOCK_TTL);
                if ($bLock->get()) {
                    Log::info('[SEL-429][DicloakGPT] principal ocupado, usando backup', [
                        'primary' => $profileId,
                        'backup'  => $backupId,
                    ]);
                    try {
                        return $this->runWithProfile($backupId, $tunnelUrl, $messages, $timeoutS, $workerJs, $workerDir);
                    } finally {
                        $bLock->release();
                    }
                }
            }
            throw new \RuntimeException(
                'dicloak_gpt_locked: perfil ' . $profileId . ' ocupado e sem backup disponivel'
            );
        }

        try {
            return $this->runWithProfile($profileId, $tunnelUrl, $messages, $timeoutS, $workerJs, $workerDir);
        } finally {
            $lock->release();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function runWithProfile(
        string $profileId,
        string $tunnelUrl,
        array  $messages,
        int    $timeoutS,
        string $workerJs,
        string $workerDir
    ): string {
        $T0 = microtime(true);

        Log::info('[SEL-429][DicloakGPT] iniciando request', [
            'engine_id'  => $this->engine->id,
            'profile_id' => $profileId,
        ]);

        // 1. Abrir perfil DICloak e obter wsEndpoint CDP
        $wsEndpoint = $this->openProfile($profileId, $tunnelUrl);

        // 2. Montar input para o worker Node.js
        $workerInput = [
            'messages'   => $messages,
            'timeout_s'  => $timeoutS,
        ];

        // 3. Montar env do processo
        $procEnv = [
            'CDP_ENDPOINT'       => $wsEndpoint,
            'DICLOAK_PROFILE_ID' => $profileId,
            'GPT_TIMEOUT_S'      => (string) $timeoutS,
            'PATH'               => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME'               => '/root',
        ];

        // 4. Rodar worker Node.js
        $cmd  = ['node', $workerJs];
        $proc = new Process($cmd, $workerDir, $procEnv);
        $proc->setTimeout($timeoutS + 30);
        $proc->setInput(json_encode($workerInput));

        Log::info('[SEL-429][DicloakGPT] executando worker', ['worker' => $workerJs]);
        $proc->run();

        // 5. Fechar aba/perfil (fail-silent -- aba e fechada pelo proprio worker)
        $this->closeProfile($profileId, $tunnelUrl);

        if (!$proc->isSuccessful() && $proc->getExitCode() !== 0) {
            $stderr = mb_substr($proc->getErrorOutput() ?: '', 0, 300);
            $stdout = mb_substr($proc->getOutput() ?: '', 0, 300);
            Log::warning('[SEL-429][DicloakGPT] worker falhou', [
                'exit_code' => $proc->getExitCode(),
                'stderr'    => $stderr,
                'stdout'    => $stdout,
            ]);
        }

        // 6. Parsear output JSON do worker (ultima linha JSON valida)
        $lines  = preg_split('/\r?\n/', trim($proc->getOutput() ?? ''));
        $result = null;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line !== '' && $line[0] === '{' && substr($line, -1) === '}') {
                $result = json_decode($line, true);
                break;
            }
        }

        if (!$result) {
            throw new \RuntimeException(
                'gpt_cdp_bad_response: worker nao retornou JSON valido. stdout: ' .
                mb_substr($proc->getOutput(), 0, 200)
            );
        }

        // 7. Tratar erros especificos
        $error = $result['error'] ?? null;

        if ($error === 'chatgpt_quota_exceeded') {
            $this->alertTelegramQuota($profileId);
            throw new \RuntimeException(
                'chatgpt_quota_exceeded: perfil ' . $profileId . ' atingiu o limite de uso do ChatGPT'
            );
        }

        if ($error === 'chatgpt_session_expired') {
            $this->alertTelegramSession($profileId);
            throw new DicloakNotConfiguredException(
                'chatgpt_session_expired: sessao ChatGPT expirou no perfil ' . $profileId . ' -- relogin necessario'
            );
        }

        if (empty($result['ok']) || empty($result['text'])) {
            throw new \RuntimeException(
                'gpt_cdp_failed: ' . ($error ?? 'resposta vazia') .
                ' (stage=' . ($result['stage'] ?? '?') . ')'
            );
        }

        $tookS = round(microtime(true) - $T0, 1);
        Log::info('[SEL-429][DicloakGPT] response OK', [
            'profile_id' => $profileId,
            'chars'      => $result['chars'] ?? strlen($result['text']),
            'took_s'     => $tookS,
        ]);

        return $result['text'];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Abre perfil DICloak e retorna o wsEndpoint CDP.
     * Lanca DicloakNotConfiguredException se o tunnel nao responder corretamente.
     */
    private function openProfile(string $profileId, string $tunnelUrl): string
    {
        Log::info('[SEL-429][DicloakGPT] abrindo perfil', ['profile_id' => $profileId]);

        $resp = Http::timeout(30)
            ->post("{$tunnelUrl}/dicloak-api/profile/{$profileId}/open");

        if (!$resp->successful()) {
            throw new DicloakNotConfiguredException(
                "DicloakGPT openProfile HTTP {$resp->status()}: " . mb_substr($resp->body(), 0, 200)
            );
        }

        $data = $resp->json();
        $ws   = $data['wsEndpoint'] ?? $data['ws_endpoint'] ?? $data['cdp'] ?? null;

        if (empty($ws)) {
            // Verificar se a resposta e HTML (tunnel mal configurado)
            $body = $resp->body();
            if (str_contains($body, '<!doctype html>') || str_contains($body, '<html')) {
                throw new DicloakNotConfiguredException(
                    'DicloakGPT: tunnel retornou HTML em vez de JSON. ' .
                    'O servidor proxy da VM nao esta configurado para /dicloak-api/*. ' .
                    'Ver INF-07X para configurar o endpoint no servidor da VM.'
                );
            }
            throw new DicloakNotConfiguredException(
                'DicloakGPT: openProfile nao retornou wsEndpoint. body: ' . mb_substr($body, 0, 200)
            );
        }

        Log::info('[SEL-429][DicloakGPT] perfil aberto', ['profile_id' => $profileId, 'ws' => $ws]);
        return $ws;
    }

    /**
     * Fecha/libera o perfil apos o request. Fail-silent.
     */
    private function closeProfile(string $profileId, string $tunnelUrl): void
    {
        try {
            Http::timeout(10)->post("{$tunnelUrl}/dicloak-api/profile/{$profileId}/close");
            Log::info('[SEL-429][DicloakGPT] perfil fechado', ['profile_id' => $profileId]);
        } catch (\Throwable $e) {
            Log::warning('[SEL-429][DicloakGPT] falha ao fechar perfil (ignorada)', [
                'profile_id' => $profileId,
                'err'        => $e->getMessage(),
            ]);
        }
    }

    private function alertTelegramQuota(string $profileId): void
    {
        try {
            $token  = config('services.telegram.bot_token', '');
            $chatId = config('services.telegram.chat_id', '');
            if (empty($token) || empty($chatId)) return;
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'    => $chatId,
                'parse_mode' => 'HTML',
                'text'       => "[SEL-429] ChatGPT ILIMITADO atingiu cota!\n\nPerfil: <code>{$profileId}</code>\nFallback ativo: Gemini API.\n\nAcesse chatgpt.com e verifique.",
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEL-429][DicloakGPT] falha ao alertar Telegram', ['err' => $e->getMessage()]);
        }
    }

    private function alertTelegramSession(string $profileId): void
    {
        try {
            $token  = config('services.telegram.bot_token', '');
            $chatId = config('services.telegram.chat_id', '');
            if (empty($token) || empty($chatId)) return;
            Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'    => $chatId,
                'parse_mode' => 'HTML',
                'text'       => "[SEL-429] Sessao ChatGPT expirou!\n\nPerfil DICloak: <code>{$profileId}</code>\nFallback ativo: Gemini API.\n\nAbra o DICloak e faca re-login no ChatGPT.",
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEL-429][DicloakGPT] falha ao alertar Telegram sessao', ['err' => $e->getMessage()]);
        }
    }
}
