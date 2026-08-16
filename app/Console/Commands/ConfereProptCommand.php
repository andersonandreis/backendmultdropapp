<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * SEL-CONFERE-PROMPT (16/08) — o teste que pega o defeito que mais se repetiu hoje.
 *
 * TRES VEZES na mesma noite um texto nosso morreu CALADO no caminho ate o motor:
 *   1. PRODUTO  — cortado em 150 chars; comia a exigencia de cor. Cliente recebeu cadeira
 *                 PRETA no lugar da cinza-clara dele. (#1297)
 *   2. CLIENTE  — o carimbo do prompt/cenario ficou numa funcao que so roda no fallback;
 *                 84 de 98 geracoes iam pelo outro caminho. 160 videos, 3 com a frase do
 *                 cliente, ZERO com o cenario.
 *   3. POV      — `framing` nao era usado por esse montador e `subject` era cortado em
 *                 120 chars. Todo o formato POV morria antes do motor. (#1313)
 *
 * Em todos os tres o codigo "estava certo" e o teste unitario passava. O que faltava era
 * alguem conferir o TEXTO FINAL, que e a unica coisa que o motor le.
 *
 * Este comando monta o prompt de cada estilo pelo caminho de verdade e exige que os blocos
 * obrigatorios cheguem INTEIROS. Roda em segundos, nao gera video, nao gasta credito.
 *
 * USO:  php artisan video:confere-prompt
 *       (sai com codigo 1 se algum bloco sumiu — da pra por no cron)
 */
class ConfereProptCommand extends Command
{
    protected $signature = 'video:confere-prompt';
    protected $description = 'Confere que os blocos obrigatorios chegam INTEIROS no prompt de cada estilo';

    /** o que NAO pode faltar, por estilo */
    private const EXIGE = [
        'ugc' => [
            'PRODUTO:'                              => 'o produto e nomeado',
            'NAO inventar'                          => 'proibicao de trocar de produto',
            'identico em cor'                       => 'exigencia de cor',
            'PEDIDO ESCRITO PELO PROPRIO CLIENTE'   => 'texto que o cliente colou',
            'CENARIO DO CLIENTE'                    => 'foto de ambiente que o cliente subiu',
        ],
        'pov' => [
            'PRODUTO:'          => 'o produto e nomeado',
            'NAO inventar'      => 'proibicao de trocar de produto',
            'PROPRIAS PERNAS'   => 'primeira pessoa com o corpo no quadro',
            'DUAS MAOS'         => 'as duas maos',
            'SAI DE QUADRO'     => 'a mao sai no fim (permite emendar)',
            'ARCO DA CENA'      => 'chega -> abre -> revela -> usa',
        ],
        'zero' => [
            'PEDIDO ESCRITO PELO PROPRIO CLIENTE' => 'texto que o cliente colou',
        ],
    ];

    public function handle(): int
    {
        $svc = app(\App\Services\Ai\KaloclipStyleScriptService::class);
        $ctl = new \App\Http\Controllers\Api\V1\StudioOptionsController();

        // simula um cliente que escreveu o proprio prompt e subiu foto de cenario
        foreach (['pedidoPromptColado' => 'QUERO A MOCA SEGURANDO A CANECA AZUL TURQUESA',
                  'pedidoCenarioFoto'  => 'https://api.seller.global/storage/tt-media/teste.jpg'] as $prop => $val) {
            try {
                $p = new \ReflectionProperty($ctl, $prop);
                $p->setAccessible(true);
                $p->setValue($ctl, $val);
            } catch (\Throwable $e) {
                $this->error("propriedade {$prop} nao existe mais — o carimbo do cliente mudou de lugar");

                return self::FAILURE;
            }
        }

        $carimbo = new \ReflectionMethod($ctl, 'carimbarPedidoDoCliente');
        $carimbo->setAccessible(true);
        $visual = new \ReflectionMethod($ctl, 'aplicarVisualPorEstilo');
        $visual->setAccessible(true);

        $base = [
            'audio_diegetic' => 'Olha essa caneca.',
            'speech'         => 'Olha essa caneca.',
            'product_desc'   => 'Caneca termica inox, cor azul turquesa. Mostrar EXATAMENTE este produto, '
                              . 'identico em cor, forma, marca e embalagem a foto de referencia anexada. '
                              . 'NAO inventar, substituir nem trocar por outro produto.',
            'scene'          => 'cozinha',
            'subject'        => 'mulher brasileira',
            '_lang'          => 'pt-BR',
            'duration'       => 10,
        ];

        $falhou = false;

        foreach (self::EXIGE as $estilo => $exigencias) {
            $this->newLine();
            $this->line("=== {$estilo} ===");

            try {
                $script = $visual->invoke($ctl, $base, $estilo, [], 'camera de mao', false);
                $script = $carimbo->invoke($ctl, $script);
                $prompt = $svc->toKlingPrompt($script);
            } catch (\Throwable $e) {
                $this->error('  explodiu ao montar: ' . mb_substr($e->getMessage(), 0, 140));
                $falhou = true;
                continue;
            }

            foreach ($exigencias as $marca => $oQueE) {
                $tem = mb_stripos($prompt, $marca) !== false;
                $this->line(sprintf('  %-42s %s', $oQueE, $tem ? 'OK' : 'SUMIU'));
                if (! $tem) { $falhou = true; }
            }
            $this->line('  (prompt final: ' . mb_strlen($prompt) . ' chars)');
        }

        $this->newLine();
        if ($falhou) {
            $this->error('ALGUM BLOCO OBRIGATORIO NAO CHEGOU NO PROMPT — o motor nao vai ver isso.');

            return self::FAILURE;
        }

        $this->info('Todos os blocos obrigatorios chegam inteiros no prompt.');

        return self::SUCCESS;
    }
}
