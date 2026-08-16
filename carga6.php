<?php
// SEL-CARGA (12/08): dispara 6 geracoes de uma vez pra medir a taxa REAL depois do
// conserto (modo gratis + 12 motores + 12 renders simultaneos). Conta do Ruan.
require '/home/api.seller.global/public_html/vendor/autoload.php';
$app = require_once '/home/api.seller.global/public_html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AiVideoPipeline;
use App\Http\Controllers\Api\V1\StudioOptionsController;

$ctrl = app(StudioOptionsController::class);
$ref  = new ReflectionClass($ctrl);
$mB   = $ref->getMethod('buildNarrationBeats'); $mB->setAccessible(true);
$mS   = $ref->getMethod('buildShotPrompts');    $mS->setAccessible(true);

$produtos = [
    ['Garrafa termica inox',        'Essa garrafa segura o gelo o dia inteiro.',      'Cabe na mochila e nao vaza.',        'Corre no link e garante a sua.'],
    ['Fone bluetooth',              'O cancelamento de ruido desse fone e de verdade.','Bateria dura o dia inteiro.',        'Clica no link agora.'],
    ['Organizador de cozinha',      'Minha bancada vive limpa depois desse organizador.','Cabe tudo e ainda sobra espaco.',  'Garante o seu hoje.'],
    ['Luminaria de mesa',           'Essa luminaria mudou meu home office.',          'Tres tons de luz num toque so.',     'Corre que ta acabando.'],
    ['Mochila impermeavel',         'Peguei chuva forte e nada molhou dentro.',       'Tem espaco pro notebook inteiro.',   'Link aqui embaixo.'],
    ['Panela antiaderente',         'Ovo desliza nessa panela, nao gruda nada.',      'Lava em dois segundos.',             'Aproveita e leva a sua.'],
];

$clipSecs = 8; $duracao = 10; $maxSeg = 4;
$durPed = min($duracao, $clipSecs * $maxSeg);
$N = max(1, min((int) ceil($durPed / $clipSecs), $maxSeg));
$base = round($durPed / $N, 2);
$lens = array_fill(0, $N, $base);
$lens[$N - 1] = round($durPed - $base * ($N - 1), 2);

$ids = [];
foreach ($produtos as [$nome, $ini, $meio, $fim]) {
    $v = ['produto_nome' => $nome, 'inicio' => $ini, 'meio' => $meio, 'fim' => $fim];
    $beats = $mB->invoke($ctrl, $v, $N, $clipSecs);
    $shots = $mS->invoke($ctrl, $v, 'ugc', 'Camera em movimento suave, close no produto.',
                         'cozinha brasileira com luz natural', $N, $clipSecs, $beats, $lens);
    $p = AiVideoPipeline::create([
        'user_id' => 592, 'mode' => 'studio_long_carga', 'product_key' => md5('carga_' . $nome . time()),
        'step' => 'queued',
        'payloads' => [
            'pipeline' => 'video_longo', 'long_video' => true, 'duration' => $duracao,
            'clip_seconds' => $clipSecs, 'clip_lens' => $lens, 'n_segments' => $N,
            'aspect_ratio' => '9:16', 'image_url' => null,
            'narration_beats' => $beats, 'narration_text' => trim(implode(' ', array_filter($beats))),
            'clip_prompts' => $shots, 'prompt' => $shots[0], 'estilo' => 'ugc', 'lang' => 'pt-BR',
            'opcoes_mode' => true, '_priority' => 'high', 'quality_tier' => 'padrao',
            'product_name' => $nome, '_teste_carga' => true,
        ],
        'dry_run' => false,
    ]);
    \App\Jobs\StudioLongVideoJob::dispatch($p->id)->onQueue('video-ruan');
    $ids[] = $p->id;
}
echo 'CARGA_IDS=' . implode(',', $ids) . "\n";
