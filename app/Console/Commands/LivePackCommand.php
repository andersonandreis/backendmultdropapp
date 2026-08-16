<?php

namespace App\Console\Commands;

use App\Models\AiVideoPipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-LIVE-PACK (16/08) — A RECEITA DA LIVE COM IA, AUTOMATIZADA.
 *
 * ORIGEM: o Ruan mandou o video https://vt.tiktok.com/ZSVFUCuHW/ (@ocaiodlugosz, 2m41s)
 * e disse: "aprende a logica imagem mao prompt audio etc para voce automatizar tudo".
 * Transcrevi o video com o nosso proprio ouvido (/opt/ouvido) e destrinchei a receita.
 *
 * ══ A RECEITA DO VIDEO, PASSO A PASSO (com o minuto de onde saiu) ══════════════
 *
 *  [04.9] 1. ACHAR O PRODUTO — ele garimpa uma live que ja vende (no caso, cabeceiras).
 *  [08.0] 2. IMAGEM DA CENA  — pede a um LLM um prompt pra gerar A CENA, e faz questao de
 *                              dizer "SEM AS MAOS de preferencia": nasce a cena LIMPA.
 *  [21.0] 3. VIDEO A PARTIR DA IMAGEM — manda a imagem de volta pro LLM e pede um prompt
 *                              "pra aparecer uma MAO, e depois SAIR no final de cada video".
 *  [31.2] 4. VARIOS TAKES     — gera N clipes DA MESMA CENA com esse mesmo comando.
 *  [44.2] 5. O SEGREDO        — "o prompt pra mao aparecer e depois sair serve pra dar mais
 *                              FLUIDEZ na sua live. Assim nao parece um monte de video
 *                              juntado e editado. Voce faz parecer mais real, parece uma
 *                              transmissao ao vivo. A pessoa entra, depois sai, depois
 *                              entra, e nao da pra perceber a juncao de todos esses frames."
 *  [61.2] 6. BANNER           — pega a foto do produto e gera o banner de oferta.
 *  [76.5] 7. ROTEIRO LONGO    — pede ~59 mil caracteres de narracao (da ~1 hora de fala).
 *  [93.0] 8. NARRACAO         — joga o roteiro num gerador de voz.
 * [107.4] 9. GERENCIADOR      — sobe videos + audio no TikTok Live Studio, ativa "camera
 *                              ao vivo" e o TikTok trata como camera e microfone reais.
 *
 * ══ O QUE ISTO AQUI JA AUTOMATIZA (com o que a fabrica TEM hoje) ═══════════════
 * Passos 2, 3, 4 e 5 — a parte que faz a live parecer live:
 *   - descreve o produto OLHANDO a foto (VideoDirectorService, sem chave, sem custo)
 *   - monta UM comando de cena e reusa ele em TODOS os takes (cena/luz/enquadramento
 *     identicos, senao a emenda aparece)
 *   - cada take: a mao ENTRA, usa o produto e SAI de quadro, terminando com o produto
 *     sozinho — que e exatamente o que permite costurar sem salto
 *   - despacha os N pedidos na fila normal da fabrica (mesma resiliencia, mesmo resgate)
 *
 * ══ O QUE AINDA NAO DA (e por que) ════════════════════════════════════════════
 *   - passo 7/8 (roteiro longo + NARRACAO): as chaves de voz (ElevenLabs/OpenAI) estao
 *     VAZIAS neste backend desde 12/08, por decisao de migrar tudo pro navegador. Sem
 *     motor de texto->voz nao ha narracao de 1 hora. Precisa de decisao do Ruan.
 *   - passo 6 (banner): o pool de imagem existe (AiEnginePool::for('image')), mas o
 *     layout de banner de oferta e trabalho a parte — nao invento aqui.
 *   - passo 9 (subir no TikTok Live Studio): e no computador do cliente, fora do servidor.
 *
 * USO:
 *   php artisan live:pack --user=123 --foto=https://... --produto="Cabeceira casal" --takes=6
 *   php artisan live:pack --user=123 --foto=... --produto="..." --takes=6 --ensaio
 */
class LivePackCommand extends Command
{
    protected $signature = 'live:pack
        {--user= : id do usuario dono dos videos}
        {--foto= : URL da foto do produto (a cena nasce dela)}
        {--produto= : nome do produto, como aparece no anuncio}
        {--takes=6 : quantos clipes gerar (a live costura todos)}
        {--cenario= : descricao do ambiente; vazio = a gente deduz da foto}
        {--ensaio : so mostra o que faria, sem criar pedido nem gastar motor}';

    protected $description = 'Gera o pacote de clipes de uma LIVE com IA: N takes da MESMA cena, mao entrando e saindo';

    public function handle(): int
    {
        $userId  = (int) $this->option('user');
        $foto    = trim((string) $this->option('foto'));
        $produto = trim((string) $this->option('produto'));
        $takes   = max(1, min(20, (int) $this->option('takes')));
        $ensaio  = (bool) $this->option('ensaio');

        if (! $userId || $foto === '' || $produto === '') {
            $this->error('Faltou --user, --foto ou --produto.');

            return self::FAILURE;
        }

        // ── 1) olha a foto e descreve o produto de verdade (cor, material, pecas) ──
        // Sem isto o motor inventa outro produto — foi o defeito que custou o dia 16/08.
        $this->line('olhando a foto do produto...');
        $aparencia = '';
        try {
            $aparencia = app(\App\Services\Ai\VideoDirectorService::class)
                ->descreveAparencia($foto, $produto);
        } catch (\Throwable $e) {
            $this->warn('visao indisponivel, seguindo so com o titulo: ' . mb_substr($e->getMessage(), 0, 80));
        }
        $descProduto = $aparencia !== '' ? $aparencia : $produto;
        $this->line('  produto: ' . mb_substr($descProduto, 0, 110));

        $cenario = trim((string) $this->option('cenario'));
        if ($cenario === '') {
            // O video de referencia gera a CENA antes, e sem maos. Aqui o ambiente sai da
            // propria foto do produto — e a mesma frase em todos os takes, que e o que
            // mantem o cenario identico.
            $cenario = 'o MESMO ambiente que aparece na foto de referencia, sem mudar moveis, cores nem luz';
        }

        // ── 2) UM comando de cena, reusado em TODOS os takes ──────────────────────
        // Se cada take tiver um comando diferente, a emenda aparece. O video de
        // referencia e explicito: "a mesma coisa em todos os takes desses videos".
        $prompt = implode("\n\n", [
            'PRODUTO: ' . $descProduto
                . ' Este e o produto do anuncio: IDENTICO a foto anexada em cor, forma, marca e pecas, em todos os planos.',

            'CENARIO: ' . $cenario . '. A camera fica PARADA, como uma camera de live apoiada em tripe: '
                . 'mesmo enquadramento, mesma altura e mesma luz do primeiro ao ultimo frame.',

            // ESTE bloco e o coracao da receita (minuto 44 do video de referencia).
            'ACAO: uma MAO humana realista ENTRA em quadro pela lateral ou por baixo, pega o produto, '
                . 'mostra e demonstra com calma. No FIM do video a mao SAI DE QUADRO por completo e o '
                . 'ultimo instante mostra o produto SOZINHO, no mesmo lugar e no mesmo enquadramento do '
                . 'comeco. Nenhuma pessoa, rosto ou corpo aparece em nenhum momento.',

            'IMAGEM LIMPA: sem nenhuma letra, legenda, numero ou marca dagua. Maos inteiras e bem '
                . 'formadas, cinco dedos. Nada de CGI ou pele plastica.',
        ]);

        $this->newLine();
        $this->line('COMANDO QUE VAI PRA TODOS OS ' . $takes . ' TAKES:');
        $this->line(str_repeat('-', 66));
        $this->line($prompt);
        $this->line(str_repeat('-', 66));
        $this->newLine();

        if ($ensaio) {
            $this->info('ENSAIO: nada foi criado. Tire --ensaio pra gerar de verdade.');

            return self::SUCCESS;
        }

        // ── 3) despacha os takes na fila normal da fabrica ────────────────────────
        $criados = [];
        for ($i = 1; $i <= $takes; $i++) {
            $pipe = AiVideoPipeline::create([
                'user_id'  => $userId,
                'mode'     => 'live_pack_pov',
                'step'     => 'queued',
                'payloads' => [
                    'pipeline'     => 'live_pack',
                    'prompt'       => $prompt,
                    'duration'     => 10,
                    'aspect_ratio' => '9:16',
                    'image_url'    => $foto,
                    'image_refs'   => [],
                    'estilo'       => 'pov',
                    'lang'         => 'pt-BR',
                    'sem_som'      => true,   // a narracao da live vem por fora, num audio so
                    'opcoes_mode'  => true,
                    'live_take'    => $i,
                    'live_takes'   => $takes,
                ],
            ]);

            \App\Jobs\StudioGenerationJob::dispatch($pipe->id)->onQueue('video');
            $criados[] = $pipe->id;
            $this->line(sprintf('  take %d/%d -> pedido #%d', $i, $takes, $pipe->id));
        }

        Log::error('[SEL-LIVE-PACK] pacote de live despachado', [
            'user' => $userId, 'takes' => $takes, 'pipelines' => $criados,
        ]);

        $this->newLine();
        $this->info('Pacote na fila: ' . count($criados) . ' takes (#' . implode(', #', $criados) . ')');
        $this->line('Acompanhe com: php artisan tinker --execute="..." ou pela Galeria do cliente.');
        $this->newLine();
        $this->warn('FALTA pra live ficar completa (nao da pra fazer daqui):');
        $this->line('  - narracao longa: as chaves de voz estao vazias desde 12/08 (decisao do Ruan)');
        $this->line('  - banner de oferta: layout a definir');
        $this->line('  - subir no TikTok Live Studio: e no computador do cliente');

        return self::SUCCESS;
    }
}
