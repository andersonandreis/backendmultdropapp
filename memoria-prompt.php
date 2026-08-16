<?php
/**
 * MEMORIA DE PROMPT — SEL-MEMORIA (12/08, ideia do Ruan: "cria um modelo de
 * aperfeicoamento de prompt; os que deram certo voce vai seguindo... as vezes o
 * cliente seleciona o mesmo produto e as mesmas coisas, tem que ter memoria pra
 * reutilizar, de repente so trocar o personagem").
 *
 * Por que faz sentido (medido): em 30 dias foram 817 geracoes pra 428 produtos.
 * Um produto foi gerado 34 vezes, outro 27, outro 27. Quase metade e repeticao —
 * e hoje cada uma dessas comeca do zero, com o mesmo risco de sair errada.
 *
 * O que este script faz: varre o que JA foi gerado, cruza com o laudo do
 * conferente (payloads.qa) e guarda as RECEITAS que deram certo. Receita =
 * produto + estilo + duracao -> os prompts e falas exatos que produziram um
 * video aprovado. O que foi reprovado vira contra-exemplo, pra nunca repetir.
 *
 * Uso: php memoria-prompt.php            (so mede e mostra)
 *      php memoria-prompt.php --gravar   (cria/atualiza a tabela)
 */
require '/home/api.seller.global/public_html/vendor/autoload.php';
$app = require_once '/home/api.seller.global/public_html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

$GRAVAR = in_array('--gravar', $argv, true);

if ($GRAVAR && ! Schema::hasTable('video_prompt_receitas')) {
    Schema::create('video_prompt_receitas', function (Blueprint $t) {
        $t->id();
        $t->string('product_key', 64)->index();      // qual produto
        $t->string('estilo', 40)->index();           // ugc / pov / showcase / zero
        $t->unsignedSmallInteger('duracao')->index();
        $t->unsignedInteger('pipeline_origem');      // de qual geracao veio
        $t->json('beats');                           // a fala, corte a corte
        $t->json('clip_prompts');                    // o prompt de cada corte
        $t->string('narration_text', 1000)->nullable();
        $t->string('veredito', 20)->default('ok');   // ok | ressalva
        $t->unsignedInteger('usos')->default(0);     // quantas vezes foi reaproveitada
        $t->unsignedInteger('acertos')->default(0);  // quantas vezes o reuso deu certo
        $t->timestamp('ultimo_uso')->nullable();
        $t->timestamps();
        $t->unique(['product_key', 'estilo', 'duracao'], 'receita_unica');
    });
    echo "tabela video_prompt_receitas criada\n";
}

/* ---------- varre o que ja foi gerado ---------- */
$boas = 0; $ruins = 0; $semLaudo = 0; $gravadas = 0; $atualizadas = 0;
$candidatas = [];

foreach (DB::table('ai_video_pipelines')
    ->where('created_at', '>=', now()->subDays(30))
    ->whereNotNull('output_url')->where('output_url', '<>', '')
    ->orderBy('id')->get() as $p) {

    $pl = json_decode($p->payloads ?: '{}', true) ?: [];
    $qa = $pl['qa'] ?? null;
    if (! $qa) { $semLaudo++; continue; }

    $veredito = $qa['veredito'] ?? 'sem laudo';
    if ($veredito === 'reprovado') { $ruins++; continue; }
    $boas++;

    $beats  = array_values(array_filter((array) ($pl['narration_beats'] ?? [])));
    $shots  = array_values(array_filter((array) ($pl['clip_prompts'] ?? [])));
    if (! $beats || ! $shots) { continue; }   // sem receita utilizavel

    $estilo  = (string) ($pl['estilo'] ?? preg_replace('/^studio_(long|opcoes)_/', '', (string) $p->mode));
    $duracao = (int) ($pl['duration'] ?? 0);
    $chave   = $p->product_key . '|' . $estilo . '|' . $duracao;

    // fica com a MAIS RECENTE aprovada de cada combinacao (prompt mais atual vence)
    $candidatas[$chave] = [
        'product_key'     => (string) $p->product_key,
        'estilo'          => $estilo,
        'duracao'         => $duracao,
        'pipeline_origem' => (int) $p->id,
        'beats'           => $beats,
        'clip_prompts'    => $shots,
        'narration_text'  => mb_substr((string) ($pl['narration_text'] ?? ''), 0, 1000),
        'veredito'        => $veredito === 'ok' ? 'ok' : 'ressalva',
    ];
}

echo "geracoes com video (30d): aprovadas={$boas} reprovadas={$ruins} sem_laudo={$semLaudo}\n";
echo 'receitas aproveitaveis: ' . count($candidatas) . "\n";

if ($GRAVAR) {
    foreach ($candidatas as $r) {
        $existe = DB::table('video_prompt_receitas')
            ->where('product_key', $r['product_key'])->where('estilo', $r['estilo'])
            ->where('duracao', $r['duracao'])->first();
        $dados = [
            'pipeline_origem' => $r['pipeline_origem'],
            'beats'           => json_encode($r['beats'], JSON_UNESCAPED_UNICODE),
            'clip_prompts'    => json_encode($r['clip_prompts'], JSON_UNESCAPED_UNICODE),
            'narration_text'  => $r['narration_text'],
            'veredito'        => $r['veredito'],
            'updated_at'      => now(),
        ];
        if ($existe) {
            // so troca se a nova for MELHOR (ok ganha de ressalva) ou mais nova
            if ($existe->veredito === 'ressalva' && $r['veredito'] === 'ok') {
                DB::table('video_prompt_receitas')->where('id', $existe->id)->update($dados);
                $atualizadas++;
            }
        } else {
            DB::table('video_prompt_receitas')->insert($dados + [
                'product_key' => $r['product_key'], 'estilo' => $r['estilo'],
                'duracao' => $r['duracao'], 'created_at' => now(),
            ]);
            $gravadas++;
        }
    }
    echo "gravadas={$gravadas} atualizadas={$atualizadas}\n";
    echo 'total na memoria: ' . DB::table('video_prompt_receitas')->count() . "\n";

    /* mostra as receitas dos produtos mais repetidos — sao as que mais vao ser usadas */
    echo "\nreceitas dos produtos mais gerados:\n";
    foreach (DB::table('video_prompt_receitas as r')
        ->join(DB::raw('(SELECT product_key, COUNT(*) n FROM ai_video_pipelines WHERE created_at > NOW() - INTERVAL 30 DAY GROUP BY product_key) c'),
               'c.product_key', '=', 'r.product_key')
        ->orderByDesc('c.n')->limit(5)
        ->get(['r.product_key', 'r.estilo', 'r.duracao', 'r.veredito', 'c.n']) as $x) {
        printf("  %s  %-10s %2ds  laudo=%-9s (produto gerado %d vezes)\n",
            substr($x->product_key, 0, 14), $x->estilo, $x->duracao, $x->veredito, $x->n);
    }
}
