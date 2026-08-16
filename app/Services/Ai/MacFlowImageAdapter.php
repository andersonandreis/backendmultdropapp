<?php
namespace App\Services\Ai;

use App\Contracts\ImageGeneratorContract;
use App\Models\AiEngine;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * SEL-avatar (10/08) -- Adapter de IMAGEM pelo Google Flow / Nano Banana.
 *
 * IRMAO do MacFlowVideoAdapter, mas modo IMAGEM (R$0, incluso no Ultra). Chama o
 * worker NOVO browser-worker/flow_image.js (NAO o veo_generate.js, que o fix de
 * audio esta mexendo). Roda LOCAL no servidor via xvfb-run com a sessao Google da
 * conta (por padrao a "Reserva" id 14, google-session.json), que NAO e uma das 5
 * contas que servem video pros clientes -> zero colisao.
 *
 * Provado 10/08 na Reserva: 1 imagem 9:16 realista, 0 credito (saldo intacto), ~33s.
 *
 * Retorno: ['url' => <URL publica>, 'local_path' => ..., 'source_url' => ...,
 *           'saldo' => <int|null>]
 */
class MacFlowImageAdapter implements ImageGeneratorContract
{
    public function __construct(private AiEngine $engine) {}

    public function generate(string $prompt, array $refImages = [], string $size = '1024x1024'): array
    {
        $cfg = $this->engine->config_json ?? [];
        if (!is_array($cfg)) { $cfg = json_decode((string) $cfg ?: '{}', true) ?: []; }

        $sessionPath = $cfg['session_path']
            ?? '/home/api.seller.global/storage/kling-browser/google-session.json';
        $workerJs = $cfg['worker_js']
            ?? '/home/api.seller.global/browser-worker/flow_image.js';
        $workerDir = $cfg['worker_dir']
            ?? '/home/api.seller.global/browser-worker';

        if (!is_file($sessionPath)) {
            throw new \RuntimeException("flow_image_session_missing: {$sessionPath} nao existe");
        }
        if (!is_file($workerJs)) {
            throw new \RuntimeException("flow_image_worker_missing: {$workerJs} nao existe");
        }

        // avatar do vídeo é 9:16; o `size` do contrato ('1024x1024') não importa pro Flow.
        $taskId = 'avt_' . uniqid();
        $outTmp = sys_get_temp_dir() . '/flow_img_' . $taskId . '.png';

        // SEL-TRYON-REFS (14/08) — o parametro $refImages EXISTIA na assinatura deste
        // metodo desde 10/08 e NUNCA era usado: o payload que ia pro worker so levava
        // prompt/aspect/out. Era ai que o trabalho de avatar tinha parado, e e por isso
        // que o provador virtual nunca funcionou — vestir a pessoa da foto A com a roupa
        // da foto B exige as DUAS fotos anexadas ao comando do Flow.
        // Agora as referencias sao baixadas pro disco e mandadas em cfg.refs[]; o worker
        // anexa cada uma e ABORTA se alguma nao virar chip (foto faltando = roupa errada
        // no video, que e pior que falhar).
        $refsLocais = [];
        foreach (array_slice(array_values($refImages), 0, 4) as $i => $ref) {
            if (is_file($ref)) { $refsLocais[] = $ref; continue; }
            $bytes = @file_get_contents($ref);
            if ($bytes === false || strlen($bytes) < 2000) {
                throw new \RuntimeException('flow_image_ref_ilegivel: nao consegui baixar a referencia ' . ($i + 1));
            }
            $ext  = preg_match('/\.(png|webp)(\?|$)/i', $ref) ? 'png' : 'jpg';
            $dest = sys_get_temp_dir() . '/flow_ref_' . $taskId . '_' . $i . '.' . $ext;
            file_put_contents($dest, $bytes);
            $refsLocais[] = $dest;
        }

        $workerInput = [
            'prompt'           => $prompt,
            'aspect'           => '9:16',
            'out'              => $outTmp,
            'external_task_id' => $taskId,
            'refs'             => $refsLocais,
        ];

        $procEnv = [
            'PLAYWRIGHT_BROWSERS_PATH' => env('PLAYWRIGHT_BROWSERS_PATH', '/opt/ms-playwright'),
            'PATH'                     => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'DISPLAY'                  => ':99',
            'VEO_SESSION'              => $sessionPath,
            'OUTDIR'                   => '/tmp/flow_img_gen_' . $taskId,
        ];
        if ($pu = ($cfg['project_url'] ?? null)) {
            $procEnv['VEO_PROJECT_URL'] = $pu;
        }

        $cmd  = ['xvfb-run', '-a', '--server-args=-screen 0 1440x900x24', 'node', $workerJs];
        $proc = new Process($cmd, $workerDir, $procEnv);
        $proc->setTimeout(240);
        $proc->setInput(json_encode($workerInput));
        $proc->run();

        // Log por task (sobrevive à limpeza do /tmp) — secundário, nunca derruba.
        try {
            $logDir = storage_path('logs/flow-image');
            if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
            @file_put_contents(
                $logDir . '/' . $taskId . '.log',
                '[' . now()->toDateTimeString() . '] exit=' . var_export($proc->getExitCode(), true)
                . "\n--- STDERR ---\n" . $proc->getErrorOutput() . "\n"
            );
        } catch (\Throwable $e) {}

        // última linha JSON do stdout
        $lines = preg_split('/\r?\n/', trim($proc->getOutput()));
        $decoded = null;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $l = trim($lines[$i]);
            if ($l !== '' && $l[0] === '{' && substr($l, -1) === '}') { $decoded = json_decode($l, true); break; }
        }

        if (!$decoded || empty($decoded['ok']) || empty($decoded['output_url']) || !is_file($decoded['output_url'])) {
            $err = ($decoded['error'] ?? null) ?: mb_substr($proc->getErrorOutput() ?: $proc->getOutput(), 0, 300);
            throw new \RuntimeException('flow_image_failed: ' . $err);
        }

        // move o PNG pro storage público -> URL persistente pro cliente
        $publicPath = 'ai-images/' . date('Y/m/d') . '/' . $taskId . '.png';
        Storage::disk('public')->put($publicPath, file_get_contents($decoded['output_url']));
        @unlink($decoded['output_url']);
        $url = rtrim(config('app.url'), '/') . '/storage/' . $publicPath;

        // guarda saldo (alerta de crédito baixo no Data Center) — secundário
        try {
            if (isset($decoded['saldo_depois']) && is_numeric($decoded['saldo_depois'])) {
                $cfgNow = $this->engine->config_json ?? [];
                if (!is_array($cfgNow)) { $cfgNow = json_decode((string) $cfgNow ?: '{}', true) ?: []; }
                $cfgNow['saldo']    = (int) $decoded['saldo_depois'];
                $cfgNow['saldo_em'] = now()->toDateTimeString();
                \DB::table('ai_engines')->where('id', $this->engine->id)
                    ->update(['config_json' => json_encode($cfgNow, JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {}

        return [
            'url'        => $url,
            'local_path' => $publicPath,
            'source_url' => $decoded['source_url'] ?? null,
            'saldo'      => $decoded['saldo_depois'] ?? null,
        ];
    }
}
