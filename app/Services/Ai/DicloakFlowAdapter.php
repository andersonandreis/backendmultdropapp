<?php
namespace App\Services\Ai;

use App\Contracts\VideoGeneratorContract;
use App\Models\AiEngine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * SEL-429 -- Adapter DICloak Flow para geracao de video.
 * Herda logica do VideoEnginePool::runDicloak() do SEL-425.
 * Abre perfil Chrome via DICloak VM, conecta via CDP, roda veo_generate_cdp.js.
 */
class DicloakFlowAdapter implements VideoGeneratorContract
{
    private const LOCK_TTL = 900; // 15 min em segundos

    public function __construct(private AiEngine $engine) {}

    public function generate(string $taskId, string $kind, array $payload): array
    {
        $cfg       = $this->engine->config_json ?? [];
        $profileId = $cfg['profile_id'] ?? env('DICLOAK_PROFILE_ID', '');
        $tunnelUrl = $cfg['tunnel_url']  ?? env('DICLOAK_TUNNEL_URL', '');

        if (empty($profileId)) {
            throw new \RuntimeException('dicloak_profile_id_not_set: configure profile_id no config_json do engine via /admin/ai-engines');
        }
        if (empty($tunnelUrl)) {
            throw new DicloakNotConfiguredException('DICLOAK_TUNNEL_URL nao configurado -- INF-072 pendente');
        }

        $lockKey = 'lock:dicloak:profile:' . $profileId;
        $lock    = Cache::lock($lockKey, self::LOCK_TTL);
        if (!$lock->get()) {
            throw new \RuntimeException("dicloak_profile_locked: perfil {$profileId} em uso por outro job");
        }

        putenv('DICLOAK_TUNNEL_URL=' . $tunnelUrl);
        $adapter    = new DicloakVeoAdapter();
        $wsEndpoint = null;

        try {
            $wsEndpoint  = $adapter->openProfile($profileId);
            $workerJs    = $cfg['worker_js']  ?? config('services.veo.browser_worker_cdp_js')
                ?? '/home/api.seller.global/browser-worker/veo_generate_cdp.js';
            $workerDir   = $cfg['worker_dir'] ?? config('services.veo.browser_worker_dir')
                ?? '/home/api.seller.global/browser-worker';

            $workerInput = $this->buildWorkerInput($taskId, $kind, $payload);
            $procEnv     = $this->baseEnv();
            $procEnv['CDP_ENDPOINT']       = $wsEndpoint;
            $procEnv['DICLOAK_PROFILE_ID'] = $profileId;
            if ($pu = config('services.veo.project_url')) {
                $procEnv['VEO_PROJECT_URL'] = $pu;
            }

            return $this->runNode($workerJs, $workerDir, $workerInput, $procEnv);

        } finally {
            if ($wsEndpoint !== null) {
                try {
                    (new DicloakVeoAdapter())->closeProfile($profileId);
                } catch (\Throwable $e) {
                    Log::warning('[SEL-429][DicloakFlow] close falhou no finally', [
                        'profile_id' => $profileId,
                        'err'        => $e->getMessage(),
                    ]);
                }
            }
            $lock->release();
        }
    }

    private function buildWorkerInput(string $taskId, string $kind, array $payload): array
    {
        return [
            'image_path'       => $payload['image_path'] ?? ($payload['image_paths'][0] ?? null),
            'image_paths'      => $payload['image_paths'] ?? [],
            'file_paths'       => $payload['image_paths'] ?? [],
            'prompt'           => $payload['prompt'] ?? '',
            'duration'         => (int) ($payload['duration'] ?? 8),
            'aspect_ratio'     => $payload['aspect_ratio'] ?? '9:16',
            'model_name'       => $payload['model_name'] ?? null,
            'external_task_id' => $taskId,
            'kind'             => $kind,
            'mode'             => $kind,
            'tool'             => $kind,
        ];
    }

    private function runNode(string $workerJs, string $workerDir, array $input, array $env): array
    {
        $cmd  = ['xvfb-run', '-a', '--server-args=-screen 0 1440x900x24', 'node', $workerJs];
        $proc = new Process($cmd, $workerDir, $env);
        $proc->setTimeout(900);
        $proc->setInput(json_encode($input));
        $proc->run();

        if (!$proc->isSuccessful()) {
            throw new \RuntimeException('dicloak_worker_failed: ' . mb_substr($proc->getErrorOutput() ?: $proc->getOutput(), 0, 400));
        }

        $lines    = preg_split('/\r?\n/', trim($proc->getOutput()));
        $jsonLine = '';
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line !== '' && $line[0] === '{' && substr($line, -1) === '}') {
                $jsonLine = $line;
                break;
            }
        }
        $decoded = $jsonLine ? json_decode($jsonLine, true) : null;
        if (!$decoded || empty($decoded['ok'])) {
            throw new \RuntimeException('dicloak_worker_bad_response: ' . mb_substr($proc->getOutput(), 0, 400));
        }
        return $decoded;
    }

    private function baseEnv(): array
    {
        return [
            'PLAYWRIGHT_BROWSERS_PATH' => env('PLAYWRIGHT_BROWSERS_PATH', '/opt/ms-playwright'),
            'PATH'                     => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'DISPLAY'                  => ':99',
        ];
    }
}
