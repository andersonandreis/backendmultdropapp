<?php
/**
 * CANÁRIO DE VÍDEO — SEL-P2P. Gera UM vídeo de verdade, pelo mesmo caminho do
 * cliente (mesmos builders de fala e de prompt, mesma fila), pra provar que a
 * fábrica entrega. Quem lê o resultado é o sentinela ponta a ponta + o conferente.
 * Imprime CANARIO_ID=<pipeline> pra o sentinela guardar.
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AiVideoPipeline;
use App\Http\Controllers\Api\V1\StudioOptionsController;

$USER = 592; // conta do dono: canário nunca ocupa vaga de cliente pagante

$ctrl = app(StudioOptionsController::class);
$ref  = new ReflectionClass($ctrl);
$mB   = $ref->getMethod('buildNarrationBeats'); $mB->setAccessible(true);
$mS   = $ref->getMethod('buildShotPrompts');    $mS->setAccessible(true);

$clipSecs = (int) config('services.sel30s.clip_seconds', 8);
$duracao  = 10;                                  // formato liberado hoje
$maxSeg   = (int) config('services.sel30s.max_segments', 4);

// SEL-CANARIOIGUALCLIENTE (13/08) — o canario tem que sofrer o que o cliente sofre.
//
// Ate agora ele montava os cortes por conta propria e caia SEMPRE em 2 cortes pra um
// video de 10s. So que o caminho do cliente mudou hoje: o controller passou a nao
// partir o video por causa de sobra pequena (SEL-NAOPARTIRATOA, tolerancia de 25%),
// porque partir derrubava a entrega de 83% pra 33%. Ou seja: o canario estava
// testando um caminho que NENHUM cliente usa mais — monitor que nao reproduz o
// cliente e monitor falso, e daria "tudo bem" com a producao quebrada, ou o
// contrario.
//
// Agora ele usa a MESMA regra do controller.
$tolerancia       = (float) config('services.sel30s.tolerancia', 1.25);
$limiteParaPartir = (float) $clipSecs * $tolerancia;

if ($duracao > $limiteParaPartir) {
    $durPed = min($duracao, $clipSecs * $maxSeg);
    $N      = max(1, min((int) ceil($durPed / $clipSecs), $maxSeg));
} else {
    $durPed = $duracao;
    $N      = 1;                                 // cena unica, igual ao cliente
}
$base     = round($durPed / $N, 2);
$lens     = array_fill(0, $N, $base);
$lens[$N - 1] = round($durPed - $base * ($N - 1), 2);

$v = [
    'produto_nome' => 'Garrafa termica inox',
    'inicio'       => 'Essa garrafa segura o gelo o dia inteiro.',
    'meio'         => 'Cabe na mochila e nao vaza.',
    'fim'          => 'Corre no link e garante a sua.',
];

/**
 * SEL-CANARIOCURTO (14/08) — o canario passou a seguir a MESMA bifurcacao do cliente.
 *
 * MEDIDO antes de mexer: o controller so manda pro fluxo LONGO quando
 * `$duracao > $clipSecs * tolerancia` (10 > 8*1.25 = 10 -> FALSO). Como 10s e a unica
 * duracao liberada hoje, **nenhum cliente passa pelo StudioLongVideoJob**: 57 pipelines
 * nas ultimas 24h, todos `studio_opcoes_*`, todos por StudioGenerationJob.
 * O canario, porem, despachava SEMPRE StudioLongVideoJob — ou seja, vigiava um caminho
 * que ZERO cliente usa e nao vigiava o caminho que 100% usa. Monitor que nao reproduz o
 * cliente da "tudo bem" com a producao quebrada (foi o que aconteceu no "video parado":
 * quem viu foi o Ruan, olhando video, nao o canario).
 *
 * Agora: mesma conta (N), mesma regra, e o MESMO job/mode do cliente.
 */
$USAR_LONGO = ($N > 1);

if (! $USAR_LONGO) {
    // ── caminho CURTO: identico ao do cliente (StudioOptionsController:~530-876) ──
    $mD = $ref->getMethod('buildDeterministicScript'); $mD->setAccessible(true);
    $mE = $ref->getMethod('enrichScriptForPrompt');    $mE->setAccessible(true);
    $svc = app(\App\Services\Ai\KaloclipStyleScriptService::class);

    $cameraTexto = 'Camera em movimento suave, close no produto.';
    $script  = $mD->invoke($ctrl, $v, $duracao, $cameraTexto, []);
    $script  = $mE->invoke($ctrl, $script, 'ugc', $duracao, false);
    $promptC = $svc->toKlingPrompt($script);

    $p = AiVideoPipeline::create([
        'user_id'     => $USER,
        'mode'        => 'studio_opcoes_canario',
        'product_key' => md5('canario_' . date('YmdH')),
        'step'        => 'queued',
        'payloads'    => [
            'pipeline'     => 'video_do_zero',  // mesmo tipo que o cliente usa quando nao ha foto (controller:616)
            'prompt'       => $promptC,
            'duration'     => $duracao,
            'aspect_ratio' => '9:16',
            'image_url'    => null,
            'image_refs'   => [],
            'avatar_url'   => null,
            'gear'         => 'recomendado',
            'lang'         => 'pt-BR',
            'estilo'       => 'ugc',
            'camera_id'    => null,
            'opcoes_mode'  => true,
            '_priority'    => 'high',
            'quality_tier' => 'padrao',
            'veo_model'    => 'Omni Flash',
            '_canario'     => true,
        ],
        'dry_run' => false,
    ]);
    \App\Jobs\StudioGenerationJob::dispatch($p->id)->onQueue('video-ruan');
    echo "CANARIO_ID={$p->id}\n";
    return;
}

$beats = $mB->invoke($ctrl, $v, $N, $clipSecs);
$shots = $mS->invoke($ctrl, $v, 'ugc', 'Camera em movimento suave, close no produto.',
                     'cozinha brasileira com luz natural', $N, $clipSecs, $beats, $lens);

$p = AiVideoPipeline::create([
    'user_id'     => $USER,
    'mode'        => 'studio_long_canario',
    'product_key' => md5('canario_' . date('YmdH')),
    'step'        => 'queued',
    'payloads'    => [
        'pipeline'        => 'video_longo',
        'long_video'      => true,
        'duration'        => $duracao,
        'clip_seconds'    => $clipSecs,
        'clip_lens'       => $lens,
        'n_segments'      => $N,
        'aspect_ratio'    => '9:16',
        'image_url'       => null,
        'narration_beats' => $beats,
        'narration_text'  => trim(implode(' ', array_filter($beats))),
        'clip_prompts'    => $shots,
        'prompt'          => $shots[0],
        'estilo'          => 'ugc',
        'lang'            => 'pt-BR',
        'opcoes_mode'     => true,
        '_priority'       => 'high',
        'quality_tier'    => 'padrao',
        '_canario'        => true,
    ],
    'dry_run' => false,
]);
\App\Jobs\StudioLongVideoJob::dispatch($p->id)->onQueue('video-ruan');

echo "CANARIO_ID={$p->id}\n";
