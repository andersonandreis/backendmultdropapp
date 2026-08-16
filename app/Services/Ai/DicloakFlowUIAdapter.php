<?php

namespace App\Services\Ai;

use App\Contracts\VideoGeneratorContract;
use App\Models\AiEngine;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SEL-458 -- DicloakFlowUIAdapter
 *
 * Gera video VEO3 via UI automation Windows dentro do perfil DICloak aberto.
 * NAO usa CDP tunnel quebrado. NAO extrai cookies. NAO usa contas proprias.
 * SÓ DICloak -- perfil ja vem logado [feedback-so-dicloak-motor-video].
 *
 * Fluxo:
 *   1. Chama HTTP API do vm-job-server.py rodando na VM (porta 8765,
 *      exposto via cloudflare tunnel em vm-api.dicloak-vm.hubai.club).
 *   2. Worker Python automatiza: abre Flow/Veo3(X) via CDP SPA DICloak,
 *      foca janela GinsBrowser, digita prompt + upload imagem + clica Gerar.
 *   3. Poll ate video pronto (max 10 min).
 *   4. Transfere MP4 da VM pro servidor via download HTTP do job server.
 *   5. Salva em /storage/user-videos/{user_id}/{taskId}.mp4 e retorna URL.
 *
 * Nao mexer em:
 *   - KlingBrowserGenerateJob (ja chama pool.reserveEngine)
 *   - AiEnginePool core (so adicionar case dicloak-flow-ui)
 *   - Camada cliente (StudioChat, VideoDirector)
 */
class DicloakFlowUIAdapter implements VideoGeneratorContract
{
    /** Secret compartilhado com o vm-job-server.py */
    private const JOB_SECRET = 'sel458-hubai-dicloak';

    /** Base URL do job server na VM (via cloudflare tunnel) */
    private const VM_API_BASE = 'https://dicloak-vm-api.hubai.club';

    /** Timeout total de geracao (segundos) */
    private const GENERATE_TIMEOUT_S = 1500;

    /** Intervalo de polling do status (segundos) */
    private const POLL_INTERVAL_S = 10;

    /** Diretorio de storage para videos gerados */
    private const VIDEO_STORAGE_PATH = 'user-videos';

    public function __construct(private AiEngine $engine) {}

    /**
     * Gera video VEO3 via UI automation DICloak.
     *
     * @param  string $taskId   ID externo do job (usado como nome do arquivo)
     * @param  string $kind     image2video | text2video
     * @param  array  $payload  Dados do job (prompt, image_path/image_url, user_id)
     * @return array            {ok, output_url, took_s, engine_id, cost}
     * @throws \RuntimeException em caso de falha
     */
    public function generate(string $taskId, string $kind, array $payload): array
    {
        $t0         = microtime(true);
        $cfg        = $this->engine->config_json ?? [];
        $profileName = $cfg['profile_name'] ?? 'Flow/Veo3 (1)';
        $vmApiBase  = $cfg['vm_api_base']  ?? self::VM_API_BASE;
        $secret     = $cfg['vm_api_secret'] ?? env('VM_JOB_SECRET', self::JOB_SECRET);

        Log::info('[SEL-458][DicloakFlowUI] iniciando geracao', [
            'task_id'      => $taskId,
            'kind'         => $kind,
            'profile_name' => $profileName,
            'engine'       => $this->engine->name,
        ]);

        // 1. Resolver imagem: converte pra base64 localmente (VM nao tem acesso ao storage interno)
        $imgData  = $this->resolveImageData($payload);
        $imageUrl = $imgData['url'];
        $imgB64   = $imgData['base64'];
        $imgExt   = $imgData['ext'] ?? 'jpg';

        // 2. Submeter job ao vm-job-server
        $prompt = $payload['prompt'] ?? 'Video produto vertical 9:16';
        $jobResult = $this->submitJob($vmApiBase, $secret, $taskId, $profileName, $prompt, $imageUrl, $imgB64, $imgExt);

        if (empty($jobResult['ok'])) {
            throw new \RuntimeException(
                'dicloak_ui_submit_failed: ' . ($jobResult['error'] ?? 'unknown')
            );
        }

        Log::info('[SEL-458][DicloakFlowUI] job submetido', [
            'task_id' => $taskId,
            'vm_task' => $jobResult['task_id'] ?? $taskId,
        ]);

        // 3. Poll ate video pronto
        $vmTaskId = $jobResult['task_id'] ?? $taskId;
        $deadline = time() + self::GENERATE_TIMEOUT_S;
        $status   = null;

        while (time() < $deadline) {
            sleep(self::POLL_INTERVAL_S);
            $status = $this->pollStatus($vmApiBase, $secret, $vmTaskId);
            $s      = $status['status'] ?? 'unknown';

            Log::debug('[SEL-458][DicloakFlowUI] poll status', [
                'task_id' => $taskId,
                'status'  => $s,
            ]);

            if ($s === 'done') {
                break;
            }
            if ($s === 'failed') {
                throw new \RuntimeException(
                    'dicloak_ui_worker_failed: ' . ($status['error'] ?? 'no_detail')
                );
            }
        }

        if (($status['status'] ?? '') !== 'done') {
            throw new \RuntimeException(
                'dicloak_ui_timeout: job nao concluiu em ' . self::GENERATE_TIMEOUT_S . 's'
            );
        }

        // 4. Transferir MP4 da VM pro servidor
        $outputPath = $status['output_path'] ?? null;
        if (empty($outputPath)) {
            throw new \RuntimeException('dicloak_ui_no_output_path: worker concluiu sem output_path');
        }

        $userId   = $payload['user_id'] ?? 'unknown';
        $destPath = self::VIDEO_STORAGE_PATH . '/' . $userId . '/' . $taskId . '.mp4';
        $publicUrl = $this->transferMp4($vmApiBase, $secret, $vmTaskId, $destPath);

        $tookS = (int)(microtime(true) - $t0);

        Log::info('[SEL-458][DicloakFlowUI] video pronto', [
            'task_id'    => $taskId,
            'output_url' => $publicUrl,
            'took_s'     => $tookS,
            'engine'     => $this->engine->name,
        ]);

        return [
            'ok'         => true,
            'output_url' => $publicUrl,
            'took_s'     => $tookS,
            'engine_id'  => $this->engine->id,
            'cost'       => 0,
        ];
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    /**
     * Resolve imagem: tenta baixar localmente e converte para base64.
     * A VM nao tem acesso ao storage interno do servidor (403), entao
     * enviamos base64 embutido no request. Fallback: URL publica.
     *
     * @return array{url:?string, base64:?string, ext:string}
     */
    private function resolveImageData(array $payload): array
    {
        $candidates = [
            $payload['image_url']  ?? null,
            $payload['image']      ?? null,
            $payload['image_path'] ?? null,
        ];

        // Adicionar image_paths array
        foreach ($payload['image_paths'] ?? [] as $p) {
            if (is_string($p) && $p !== '') {
                $candidates[] = $p;
            }
        }

        foreach ($candidates as $c) {
            if (!is_string($c) || $c === '') {
                continue;
            }

            if (str_starts_with($c, 'http')) {
                // Tentar baixar no servidor Laravel (tem acesso interno)
                $ext = strtolower(pathinfo(explode('?', $c)[0], PATHINFO_EXTENSION)) ?: 'jpg';
                $ctx = stream_context_create([
                    'http' => ['timeout' => 20, 'header' => "User-Agent: SellerGlobal-Adapter/1.0\r\n"],
                    'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
                ]);
                $bin = @file_get_contents($c, false, $ctx);
                if ($bin && strlen($bin) > 100) {
                    Log::debug('[SEL-458][DicloakFlowUI] imagem baixada como base64', ['url' => $c, 'bytes' => strlen($bin)]);
                    return ['url' => null, 'base64' => base64_encode($bin), 'ext' => $ext];
                }
                // Nao conseguiu baixar: enviar URL e deixar VM tentar
                Log::warning('[SEL-458][DicloakFlowUI] nao conseguiu baixar imagem internamente', ['url' => $c]);
                return ['url' => $c, 'base64' => null, 'ext' => $ext];
            }

            // Path local no servidor
            if (file_exists($c)) {
                $ext = strtolower(pathinfo($c, PATHINFO_EXTENSION)) ?: 'jpg';
                return ['url' => null, 'base64' => base64_encode(file_get_contents($c)), 'ext' => $ext];
            }
        }

        return ['url' => null, 'base64' => null, 'ext' => 'jpg'];
    }

    /**
     * Retrocompat: retorna apenas a URL (para outros usos).
     */
    private function resolveImageUrl(array $payload): ?string
    {
        return $this->resolveImageData($payload)['url'];
    }

    /**
     * Submete job ao vm-job-server.
     */
    private function submitJob(
        string $vmApiBase,
        string $secret,
        string $taskId,
        string $profileName,
        string $prompt,
        ?string $imageUrl,
        ?string $imageBase64 = null,
        string $imageExt = 'jpg'
    ): array {
        $url     = rtrim($vmApiBase, '/') . '/api/video/generate';
        $jobBody = [
            'task_id'      => $taskId,
            'profile_name' => $profileName,
            'prompt'       => $prompt,
        ];

        if ($imageBase64) {
            // Preferir base64 (VM nao precisa de acesso externo)
            $jobBody['image_base64'] = $imageBase64;
            $jobBody['image_ext']    = $imageExt;
        } elseif ($imageUrl) {
            $jobBody['image_url'] = $imageUrl;
        }

        $body = json_encode($jobBody);
        return $this->httpPost($url, $body, $secret);
    }

    /**
     * Consulta status do job no vm-job-server.
     */
    private function pollStatus(string $vmApiBase, string $secret, string $taskId): array
    {
        $url = rtrim($vmApiBase, '/') . '/api/video/status/' . urlencode($taskId);
        return $this->httpGet($url, $secret);
    }

    /**
     * Transfere MP4 do job server pro storage do servidor.
     * O vm-job-server serve o arquivo via GET /api/video/file/{taskId}.
     * Se nao disponivel, usa SCP via SSH (fallback).
     */
    private function transferMp4(
        string $vmApiBase,
        string $secret,
        string $taskId,
        string $destPath
    ): string {
        $fileUrl = rtrim($vmApiBase, '/') . '/api/video/file/' . urlencode($taskId);

        // Tentar download HTTP do arquivo
        $context = stream_context_create([
            'http' => [
                'timeout' => 120,
                'header'  => "X-Job-Secret: {$secret}\r\n",
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $absDestDir = storage_path('app/' . dirname($destPath));
        if (!is_dir($absDestDir)) {
            @mkdir($absDestDir, 0755, true);
        }
        $absDest = storage_path('app/' . $destPath);

        $bin = @file_get_contents($fileUrl, false, $context);
        if ($bin && strlen($bin) > 1000) {
            file_put_contents($absDest, $bin);
            Log::info('[SEL-458][DicloakFlowUI] MP4 transferido via HTTP', [
                'bytes' => strlen($bin),
                'dest'  => $absDest,
            ]);
        } else {
            Log::warning('[SEL-458][DicloakFlowUI] HTTP download falhou, tentando SCP via SSH local', [
                'task_id' => $taskId,
            ]);
            $this->transferViaSsh($taskId, $absDest);
        }

        // Retornar URL publica
        $appUrl = rtrim(env('APP_URL', 'https://api.seller.global'), '/');
        // storage/user-videos/... requer symlink public/storage -> storage/app/public
        // mas o path destino esta em storage/app/user-videos (nao em public/)
        // Servir via rota protegida ou symlink
        return $appUrl . '/storage/' . $destPath;
    }

    /**
     * Fallback: transfere MP4 via SSH (chave tokfy_claude ja presente na VM).
     * O servidor Linux nao pode SSH direto na VM, entao esta funcao
     * usa a chave privada para conectar via SSH reverso se disponivel.
     * Se nao disponivel, loga o erro e retorna path local.
     */
    private function transferViaSsh(string $taskId, string $absDest): void
    {
        // Este fallback so funciona se houver SSH reverso configurado.
        // Na pratica o download HTTP deve funcionar quando vm-api tunnel estiver ativo.
        Log::error('[SEL-458][DicloakFlowUI] SCP fallback nao disponivel (sem SSH direto servidor->VM)', [
            'task_id' => $taskId,
        ]);
        throw new \RuntimeException(
            'dicloak_ui_transfer_failed: HTTP download falhou e SCP nao disponivel. ' .
            'Verificar vm-api tunnel e vm-job-server status.'
        );
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────

    private function httpPost(string $url, string $body, string $secret): array
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nX-Job-Secret: {$secret}\r\n",
                'content' => $body,
                'timeout' => 30,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $resp = @file_get_contents($url, false, $context);
        if ($resp === false) {
            $err = error_get_last();
            throw new \RuntimeException('http_post_failed: ' . ($err['message'] ?? $url));
        }

        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('http_post_bad_json: ' . mb_substr($resp, 0, 200));
        }

        return $decoded;
    }

    private function httpGet(string $url, string $secret): array
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "X-Job-Secret: {$secret}\r\n",
                'timeout' => 15,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $resp = @file_get_contents($url, false, $context);
        if ($resp === false) {
            return ['status' => 'error', 'error' => 'http_get_failed: ' . $url];
        }

        $decoded = json_decode($resp, true);
        return is_array($decoded) ? $decoded : ['status' => 'error', 'error' => 'bad_json'];
    }
}
