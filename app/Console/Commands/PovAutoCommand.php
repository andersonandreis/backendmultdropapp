<?php

namespace App\Console\Commands;

use App\Models\AiVideoPipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * SEL-POV-AUTO (16/08) — A RECEITA DO POV, AUTOMATIZADA, NA MESMA SEQUENCIA DOS VIDEOS.
 *
 * Ruan, depois de me ver pulando etapa: "eles pegam o produto, depois coloca o braco,
 * depois anima. Preste atencao em tudo, todos os detalhes pra tu criar isso, nessa mesma
 * sequencia, so que automatizado."
 *
 * ══ OS TRES PASSOS (cada um provado no servidor antes de virar comando) ═══════════
 *
 *  1. PEGA O PRODUTO   -> /opt/limpa (rembg local): tira o fundo, corta rente ao produto
 *                          e enquadra ele ocupando ~76% do 9:16. ~2s, sem chave.
 *                          (a 1a versao devolvia o produto boiando no branco e o Ruan
 *                           reprovou: "muito ruim". Enquadrar faz parte de limpar.)
 *
 *  2. COLOCA O BRACO   -> gera uma IMAGEM NOVA (Nano Banana pelo Flow) usando a imagem
 *                          limpa como referencia: um braco entra no quadro e toca o
 *                          produto. So braco e mao — sem rosto, sem corpo. ~66s.
 *                          ESTE E O PASSO QUE EU PULAVA. Ir direto da foto pro video faz
 *                          o motor INVENTAR a mao enquanto anima — e por isso saia ruim.
 *                          O braco tem que nascer numa IMAGEM, nao numa frase.
 *
 *  3. ANIMA            -> o video parte da imagem que JA TEM a mao na posicao certa. O
 *                          motor so precisa MOVER, nao criar. A mao entra, mostra e SAI
 *                          de quadro — e a saida que deixa emendar take com take sem
 *                          salto (video A, minuto 44: "nao da pra perceber a juncao").
 *
 * ══ POR QUE ISSO IMPORTA ══════════════════════════════════════════════════════════
 * Com a mao entrando e saindo, da pra emendar N takes e esticar o video o quanto quiser,
 * com a narracao por cima. E a base do Live Shop.
 *
 * USO:
 *   php artisan pov:auto --user=597 --foto=https://... --produto="Cadeira presidente" --takes=3
 *   php artisan pov:auto ... --ensaio      (mostra o plano, nao gasta motor)
 *   php artisan pov:auto ... --pago        (por padrao roda no GRATIS)
 */
class PovAutoCommand extends Command
{
    protected $signature = 'pov:auto
        {--user= : dono dos videos}
        {--foto= : URL da foto do produto}
        {--produto= : nome do produto}
        {--takes=1 : quantos takes gerar (todos da MESMA imagem, pra emendar)}
        {--pago : usa o modelo pago (padrao e o gratis)}
        {--ensaio : mostra o plano e nao gera nada}';

    protected $description = 'POV automatico: limpa o produto -> poe o braco na imagem -> anima';

    public function handle(): int
    {
        $userId  = (int) $this->option('user');
        $foto    = trim((string) $this->option('foto'));
        $produto = trim((string) $this->option('produto'));
        $takes   = max(1, min(10, (int) $this->option('takes')));
        $ensaio  = (bool) $this->option('ensaio');

        if (! $userId || $foto === '' || $produto === '') {
            $this->error('Faltou --user, --foto ou --produto.');

            return self::FAILURE;
        }

        // ── PASSO 1: pega o produto (limpa + enquadra) ────────────────────────────
        $this->line('1/3  limpando e enquadrando o produto...');
        $limpa = $this->limparProduto($foto);
        if (! $limpa) {
            $this->error('nao consegui limpar a foto — abortando (sem imagem limpa o passo 2 nao presta)');

            return self::FAILURE;
        }
        $this->info('     ' . $limpa['url'] . '   (produto ocupa ' . $limpa['pct'] . '% da tela, ' . $limpa['seg'] . 's)');

        // ── PASSO 2: coloca o braco (IMAGEM nova, nao frase) ──────────────────────
        $promptBraco = 'Foto realista, vertical 9:16. O MESMO produto da imagem de referencia — '
            . $produto . ' — identico em cor, forma, marca e pecas, no mesmo lugar e no mesmo '
            . 'enquadramento. Um BRACO e uma MAO humanos realistas entram no quadro pela lateral e '
            . 'seguram/tocam o produto, como se a pessoa que filma estivesse mostrando ele. Aparece '
            . 'SO o braco e a mao: nenhuma pessoa, nenhum rosto, nenhum corpo, nenhum ombro. Luz '
            . 'natural de ambiente domestico. Sem texto, sem legenda, sem numero, sem marca dagua.';

        if ($ensaio) {
            $this->newLine();
            $this->line('2/3  [ENSAIO] geraria a imagem com o braco com este comando:');
            $this->line('     ' . $promptBraco);
            $this->line('3/3  [ENSAIO] animaria essa imagem em ' . $takes . ' take(s).');

            return self::SUCCESS;
        }

        $this->line('2/3  colocando o braco na imagem (leva ~1 min)...');
        try {
            $img = app(\App\Services\Ai\AiEnginePool::class)
                ->generateImage($promptBraco, [$limpa['url']], '1024x1792');
        } catch (\Throwable $e) {
            $this->error('     falhou ao por o braco: ' . mb_substr($e->getMessage(), 0, 160));

            return self::FAILURE;
        }
        $imgBraco = $img['url'] ?? null;
        if (! $imgBraco) {
            $this->error('     motor de imagem nao devolveu url');

            return self::FAILURE;
        }
        $this->info('     ' . $imgBraco);

        // ── PASSO 3: anima a imagem que JA TEM o braco ────────────────────────────
        // SEL-POV-DIZ-O-PRODUTO (16/08): este prompt so falava "o produto", generico. Se a
        // imagem nao grudasse na tela do Flow, o motor inventava um produto qualquer —
        // medido: mesma imagem gerou um POTE DE CREME (#1318) e um TABLET (#1317). Agora o
        // produto vai DITO, com a clausula de fidelidade do estudio. A imagem segue sendo a
        // fonte principal; o texto e a rede pra quando ela falhar.
        $promptVideo = 'PRODUTO: ' . $produto . '. Este e o produto do anuncio: IDENTICO a imagem '
            . 'anexada em cor, forma, marca e pecas, do primeiro ao ultimo frame. NAO inventar, NAO '
            . 'substituir, NAO trocar por outro produto. '
            . 'O braco e a mao que ja aparecem na imagem se MOVEM: a mao desliza sobre o '
            . 'produto mostrando o acabamento, gira levemente o produto e, no FIM, SAI DE QUADRO por '
            . 'completo pela lateral — o ultimo instante mostra o produto SOZINHO, no mesmo lugar e '
            . 'no mesmo enquadramento do comeco. A camera fica PARADA o tempo todo, como uma camera '
            . 'apoiada. Nenhuma pessoa, rosto ou corpo aparece em nenhum momento. Sem texto na tela.';

        $criados = [];
        for ($i = 1; $i <= $takes; $i++) {
            $pipe = AiVideoPipeline::create([
                'user_id'  => $userId,
                'mode'     => 'pov_auto',
                'step'     => 'queued',
                'payloads' => [
                    // SEL-POV-AUTO: 'pov_auto' NAO existe no motor — os pedidos #1315/#1316 morreram com
                    // 'Pipeline nao reconhecido'. Inventei um nome em vez de usar os que o job aceita.
                    // 'animar_produto' e o certo aqui: a imagem JA TEM o braco, entao e so animar.
                    'pipeline'     => 'animar_produto',
                    'prompt'       => $promptVideo,
                    'duration'     => 10,
                    'aspect_ratio' => '9:16',
                    'image_url'    => $imgBraco,     // a imagem COM o braco
                    'image_refs'   => [],
                    'estilo'       => 'pov',
                    'lang'         => 'pt-BR',
                    'sem_som'      => true,          // a narracao entra por cima depois
                    'opcoes_mode'  => true,
                    // Ruan (16/08): "vai testando no gratis". Sem isto, conta da casa
                    // (super_admin/afiliado) cairia no modelo PAGO por causa do
                    // SEL-CASA-NO-PAGO e queimaria credito em teste.
                    'veo_model'    => $this->option('pago') ? 'Veo 3.1 - Quality' : 'Veo 3.1 - Lite [Lower Priority]',
                    'quality_tier' => $this->option('pago') ? 'ultra' : 'gratis',
                    '_limpa'       => $limpa['url'],
                    '_com_braco'   => $imgBraco,
                    'live_take'    => $i,
                ],
            ]);
            \App\Jobs\StudioGenerationJob::dispatch($pipe->id)->onQueue('video');
            $criados[] = $pipe->id;
            $this->line(sprintf('3/3  take %d/%d -> pedido #%d', $i, $takes, $pipe->id));
        }

        Log::error('[SEL-POV-AUTO] pacote despachado', [
            'user' => $userId, 'limpa' => $limpa['url'], 'com_braco' => $imgBraco, 'pipelines' => $criados,
        ]);

        $this->newLine();
        $this->info('Pronto: #' . implode(', #', $criados));
        $this->line('  1) limpa    ' . $limpa['url']);
        $this->line('  2) c/ braco ' . $imgBraco);
        $this->line('  3) video    na fila (' . ($this->option('pago') ? 'PAGO' : 'GRATIS') . ')');

        return self::SUCCESS;
    }

    /** Passo 1: baixa a foto, limpa com o rembg local e publica a imagem limpa. */
    private function limparProduto(string $url): ?array
    {
        $tmpIn  = sys_get_temp_dir() . '/pov_in_' . bin2hex(random_bytes(5)) . '.jpg';
        $nome   = 'limpo_' . bin2hex(random_bytes(6)) . '.jpg';
        $tmpOut = storage_path('app/public/tt-media/' . $nome);

        if (! is_dir(dirname($tmpOut))) { @mkdir(dirname($tmpOut), 0775, true); }

        $dados = @file_get_contents($url);
        if (! $dados) { return null; }
        file_put_contents($tmpIn, $dados);

        try {
            $p = new Process(
                ['/opt/limpa/bin/python', '/opt/limpa/limpa_produto.py', $tmpIn, $tmpOut],
                '/tmp',
                ['HOME' => '/tmp', 'U2NET_HOME' => '/opt/limpa/modelos']
            );
            $p->setTimeout(300);
            $p->run();
            @unlink($tmpIn);

            $saida = trim($p->getOutput());
            $i = strpos($saida, '{');
            $j = $i !== false ? json_decode(substr($saida, $i), true) : null;

            if (! is_array($j) || empty($j['ok']) || ! is_file($tmpOut)) {
                Log::error('[SEL-POV-AUTO] limpeza falhou', ['saida' => mb_substr($saida, 0, 200)]);

                return null;
            }

            return [
                'url' => asset('storage/tt-media/' . $nome),
                'pct' => $j['produto_ocupa_pct'] ?? '?',
                'seg' => $j['segundos'] ?? '?',
            ];
        } catch (\Throwable $e) {
            @unlink($tmpIn);
            Log::error('[SEL-POV-AUTO] erro ao limpar', ['err' => mb_substr($e->getMessage(), 0, 160)]);

            return null;
        }
    }
}
