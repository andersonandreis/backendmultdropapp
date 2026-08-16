<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MEMORIA DE PROMPT — SEL-MEMORIA (12/08/2026, ideia do Ruan: "cria um modelo de
 * aperfeicoamento de prompt, e os que deram certo voce vai seguindo e entendendo a
 * logica. As vezes o cliente seleciona um produto e as mesmas coisas — tem que ter
 * uma memoria pra isso, que a gente pode reutilizar, de repente so trocar o
 * personagem. Ou se for aleatorio, ja usar o mesmo prompt").
 *
 * Por que existe (medido): 30 dias = 817 geracoes pra 428 produtos; um produto foi
 * gerado 34 vezes. Quase metade e repeticao, e hoje cada uma comeca do ZERO — com o
 * mesmo risco de sair errada de novo. A memoria guarda o que ja saiu APROVADO pelo
 * conferente (video_prompt_receitas) e reaproveita.
 *
 * Como funciona, em uma frase por regra:
 *   1. RECEITA = (produto, estilo, duracao) -> a fala (beats) e os prompts de cada
 *      corte que produziram um video aprovado.
 *   2. Laudo 'ok' + cliente nao escreveu nada -> reusa fala E cortes (reuso TOTAL).
 *   3. Cliente escreveu roteiro/inicio/meio/fim, ou a receita e so 'ressalva' ->
 *      reusa so a ESTRUTURA dos cortes e enfia a fala NOVA (reuso PARCIAL). O texto
 *      do cliente sempre vence.
 *   4. Personagem: se o cliente escolheu apresentadora/avatar, a descricao da pessoa
 *      e injetada na frente do prompt — e o "de repente so trocar o personagem".
 *   5. NUNCA engessa: em todo reuso sorteia angulo de camera (um por corte),
 *      iluminacao e cenario (so quando o cliente NAO fixou cenario). Dois clientes
 *      com o mesmo produto nao podem receber o MESMO video — foi o defeito que a
 *      gente matou hoje (video byte-identico entre clientes).
 *
 * FAIL-OPEN em tudo: qualquer erro, receita incompativel ou formato inesperado
 * devolve null e o chamador segue com o prompt novo (fluxo atual, que funciona).
 * Memoria NUNCA pode impedir uma geracao.
 */
class VideoPromptMemory
{
    public const TABELA = 'video_prompt_receitas';

    /** angulos de camera — seguro variar: nao mexe na fala nem na estrutura */
    private const ANGULOS = [
        'camera na altura do peito, leve contra-plongee',
        'camera na altura dos olhos, de frente',
        'angulo de cima (plongee) sobre o produto',
        'camera baixa, rente a superficie',
        'angulo lateral a 45 graus',
        'camera por cima do ombro, acompanhando a mao',
        'camera na mao, leve oscilacao natural',
    ];

    /** hora do dia / qualidade da luz — igual em TODOS os cortes (continuidade) */
    private const LUZES = [
        'luz da manha entrando pela janela',
        'luz de fim de tarde, dourada',
        'luz difusa de dia nublado',
        'luz branca de teto, ambiente bem iluminado',
        'luz lateral suave com sombra macia',
    ];

    /** cenarios — SO entram quando o cliente nao escolheu cenario nenhum */
    private const CENAS = [
        'cozinha brasileira com luz natural da janela',
        'sala de estar com sofa claro e planta ao fundo',
        'quarto arrumado com luz suave',
        'bancada clara de banheiro',
        'mesa de madeira perto da janela',
        'varanda com plantas e luz natural',
    ];

    /**
     * Log proprio da memoria. O .env do seller.global esta em LOG_LEVEL=error, entao
     * Log::info NAO grava nada — e sem registro nao da pra provar de qual receita um
     * video nasceu. Este arquivo (storage/logs/memoria-prompt.log) e a trilha de
     * auditoria da memoria. Nunca lanca excecao.
     */
    public static function registro(string $msg, array $ctx = []): void
    {
        try {
            $arq   = storage_path('logs/memoria-prompt.log');
            $linha = '[' . date('Y-m-d H:i:s') . '] ' . $msg . ' '
                   . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            @file_put_contents($arq, $linha, FILE_APPEND | LOCK_EX);
            @chmod($arq, 0666);
        } catch (\Throwable $e) {
            // log nunca pode derrubar geracao
        }
    }

    public static function ligada(): bool
    {
        try {
            return (bool) config('services.memoria_prompt.enabled', true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Procura a receita de (produto, estilo, duracao). null = nao tem (ou deu erro,
     * e ai o chamador segue no fluxo atual).
     */
    public static function buscar(string $productKey, string $estilo, int $duracao): ?array
    {
        try {
            if (! self::ligada() || $productKey === '') {
                return null;
            }
            $r = DB::table(self::TABELA)
                ->where('product_key', $productKey)
                ->where('estilo', $estilo)
                ->where('duracao', $duracao)
                ->first();
            if (! $r) {
                return null;
            }
            $beats = json_decode((string) ($r->beats ?? '[]'), true);
            $shots = json_decode((string) ($r->clip_prompts ?? '[]'), true);
            if (! is_array($shots) || ! $shots) {
                return null;
            }

            return [
                'id'              => (int) $r->id,
                'veredito'        => (string) $r->veredito,
                'pipeline_origem' => (int) $r->pipeline_origem,
                'beats'           => is_array($beats) ? array_values($beats) : [],
                'clip_prompts'    => array_values($shots),
                'usos'            => (int) $r->usos,
                'acertos'         => (int) $r->acertos,
            ];
        } catch (\Throwable $e) {
            Log::warning('[SEL-MEMORIA] busca falhou (fail-open, segue prompt novo)', ['err' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Monta a geracao a partir da memoria.
     *
     * @param  array{product_key:string,estilo:string,duracao:int,n:int,lens:array,v:array,user_id:int,beats_novos:array}  $a
     * @return array{narration_beats:array,clip_prompts:array,meta:array}|null  null = nao reusa nada
     */
    public static function montar(array $a): ?array
    {
        try {
            $estilo  = (string) ($a['estilo'] ?? '');
            $N       = (int) ($a['n'] ?? 0);
            $v       = (array) ($a['v'] ?? []);
            $lens    = (array) ($a['lens'] ?? []);
            $novos   = array_values((array) ($a['beats_novos'] ?? []));

            $rec = self::buscar((string) ($a['product_key'] ?? ''), $estilo, (int) ($a['duracao'] ?? 0));
            if (! $rec || $N < 1) {
                self::registro('SEM RECEITA (prompt novo)', [
                    'produto' => (string) ($a['product_key'] ?? ''), 'estilo' => $estilo,
                    'duracao' => (int) ($a['duracao'] ?? 0),
                ]);

                return null;
            }

            // nº de cortes mudou (config de clip_seconds/max_segments mexida): a receita
            // nao encaixa mais nesta geracao. Melhor nao reusar do que emendar errado.
            if (count($rec['clip_prompts']) !== $N) {
                self::registro('RECEITA IGNORADA (nº de cortes mudou)', [
                    'receita' => $rec['id'], 'na_receita' => count($rec['clip_prompts']), 'agora' => $N,
                ]);

                return null;
            }

            // ── A fala do CLIENTE sempre vence. Se ele escreveu roteiro ou escolheu
            //    inicio/meio/fim, a fala nova manda; da receita fica so a estrutura.
            $clienteEscreveu = trim((string) ($v['roteiro_cliente'] ?? '')) !== ''
                || trim((string) ($v['inicio'] ?? '')) !== ''
                || trim((string) ($v['meio']   ?? '')) !== ''
                || trim((string) ($v['fim']    ?? '')) !== '';

            $mesmaFala = self::mesmaFala($rec['beats'], $novos);

            $reusarFala = $rec['veredito'] === 'ok'
                && count($rec['beats']) === $N
                && (! $clienteEscreveu || $mesmaFala);

            $beats = $reusarFala ? $rec['beats'] : $novos;
            $modo  = $reusarFala ? 'total' : 'parcial';

            // ── variacao: o que pode mudar sem perder o que deu certo
            $var = self::sortearVariacao($N, $estilo, $v, (int) ($a['user_id'] ?? 0));

            // ── personagem: "de repente so trocar o personagem"
            $pessoa = '';
            $apres  = trim((string) ($v['apresentadora'] ?? ''));
            if ($apres !== '') {
                $pessoa = 'PESSOA DO VIDEO: ' . mb_substr($apres, 0, 120)
                        . ' — e ESTA pessoa que aparece e fala em todos os cortes. ';
            }

            $prompts = [];
            foreach ($rec['clip_prompts'] as $i => $bruto) {
                $p = (string) $bruto;
                if ($modo === 'parcial') {
                    $dur = (float) ($lens[$i] ?? ($a['duracao'] ?? 8));
                    $p2  = self::trocarFala($p, trim((string) ($beats[$i] ?? '')), $dur);
                    if ($p2 === null) {
                        // formato do prompt guardado nao bate com o de hoje -> nao arrisca
                        self::registro('RECEITA NAO ACEITOU A FALA NOVA (segue prompt novo)', [
                            'receita' => $rec['id'], 'corte' => $i + 1,
                        ]);

                        return null;
                    }
                    $p = $p2;
                }
                $prompts[] = self::variar($p, $i, $var, $pessoa);
            }

            self::registro('REUSO', [
                'receita' => $rec['id'],
                'de_qual_geracao' => $rec['pipeline_origem'],
                'laudo' => $rec['veredito'],
                'reuso' => $modo,
                'cortes' => $N,
                'variacao' => $var['assinatura'],
                'angulos' => $var['angulos'],
                'luz' => $var['luz'],
                'cena' => $var['cena'],
                'personagem' => $apres !== '' ? mb_substr($apres, 0, 40) : null,
            ]);

            Log::info('[SEL-MEMORIA] reusando receita', [
                'receita'         => $rec['id'],
                'de_qual_geracao' => $rec['pipeline_origem'],
                'laudo'           => $rec['veredito'],
                'reuso'           => $modo,
                'cortes'          => $N,
                'usos_antes'      => $rec['usos'],
                'acertos_antes'   => $rec['acertos'],
                'variacao'        => $var['assinatura'],
                'personagem'      => $apres !== '' ? mb_substr($apres, 0, 40) : null,
            ]);

            return [
                'narration_beats' => array_values($beats),
                'clip_prompts'    => $prompts,
                'meta'            => [
                    '_receita_id'       => $rec['id'],
                    '_receita_origem'   => $rec['pipeline_origem'],
                    '_receita_veredito' => $rec['veredito'],
                    '_receita_reuso'    => $modo,
                    '_receita_variacao' => $var['assinatura'],
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('[SEL-MEMORIA] montar falhou (fail-open, segue prompt novo)', ['err' => $e->getMessage()]);

            return null;
        }
    }

    /** marca que a receita foi reaproveitada (nao mexe em updated_at de proposito). */
    public static function registrarUso(int $receitaId, int $pipelineId): void
    {
        try {
            DB::table(self::TABELA)->where('id', $receitaId)->update([
                'usos'       => DB::raw('usos + 1'),
                'ultimo_uso' => now(),
                'updated_at' => DB::raw('updated_at'),
            ]);
            self::registro('USO CONTADO', ['receita' => $receitaId, 'nova_geracao' => $pipelineId]);
            Log::info('[SEL-MEMORIA] receita reaproveitada', [
                'receita' => $receitaId, 'nova_pipeline' => $pipelineId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEL-MEMORIA] nao consegui contar o uso', ['err' => $e->getMessage()]);
        }
    }

    /* ================= internos ================= */

    /** duas listas de fala sao a mesma coisa? (ignora caixa/espaco) */
    private static function mesmaFala(array $a, array $b): bool
    {
        $norm = fn ($x) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $x)));

        return array_map($norm, array_values($a)) === array_map($norm, array_values($b));
    }

    /** o bloco de FALA, EXATAMENTE no formato do buildShotPrompts (SEL-FALAREPETIDA). */
    private static function blocoFala(string $fala, float $dur): string
    {
        if ($fala === '') {
            return 'SEM FALA neste corte: apenas imagem e som ambiente, ninguém fala. ';
        }
        $fim = max(1.0, round($dur - 1.2, 1));

        return 'FALA (áudio nativo, português do Brasil): a pessoa fala EM VOZ ALTA, '
             . 'EXATAMENTE esta frase, UMA ÚNICA VEZ, sem improvisar e sem repetir nenhuma palavra: '
             . '"' . $fala . '". '
             . 'TEMPO: a fala começa em 0,3s e TERMINA até ' . $fim . 's; o resto é só imagem e som ambiente. '
             . 'NÃO repita a frase nem parte dela, NÃO fale nada além dela. ';
    }

    /** troca SO a fala dentro de um prompt guardado. null = formato desconhecido. */
    private static function trocarFala(string $prompt, string $fala, float $dur): ?string
    {
        $novo  = self::blocoFala($fala, $dur);
        $conta = 0;

        if (str_contains($prompt, 'FALA (áudio nativo')) {
            $out = preg_replace_callback(
                '/FALA \(áudio nativo.*?NÃO fale nada além dela\. /us',
                fn () => $novo,
                $prompt,
                1,
                $conta
            );

            return $conta ? (string) $out : null;
        }

        if (str_contains($prompt, 'SEM FALA neste corte')) {
            $out = preg_replace_callback(
                '/SEM FALA neste corte: apenas imagem e som ambiente, ninguém fala\. /u',
                fn () => $novo,
                $prompt,
                1,
                $conta
            );

            return $conta ? (string) $out : null;
        }

        return null;
    }

    /**
     * Aplica a variacao no prompt guardado: angulo (na FRENTE, junto do
     * enquadramento — sobrevive ao corte de 1800 chars do StudioLongVideoJob),
     * personagem, cenario e luz (dentro da ancora de continuidade).
     */
    private static function variar(string $p, int $i, array $var, string $pessoa): string
    {
        $ang   = (string) ($var['angulos'][$i] ?? $var['angulos'][0] ?? '');
        $conta = 0;
        // o angulo entra COLADO no enquadramento do corte (o "CORTE i de N — ..."), que
        // e onde ele significa alguma coisa. Sem ancora de inicio de string de proposito:
        // receita gravada em formato antigo tem esse trecho no MEIO do prompt.
        $out = preg_replace_callback(
            '/(CORTE \d+ de \d+ — )([^.]{3,300})\. /u',
            fn ($m) => $m[1] . $m[2] . ($ang !== '' ? ', ' . $ang : '') . '. ' . $pessoa,
            $p,
            1,
            $conta
        );
        if (! $conta) {
            // formato desconhecido: a variacao vai na FRENTE (nunca deixa de variar —
            // e o que impede dois clientes de receberem o MESMO video).
            $out = $pessoa . ($ang !== '' ? 'ENQUADRAMENTO DESTA GRAVACAO: ' . $ang . '. ' : '') . $p;
        }

        if (! empty($var['cena'])) {
            $out = preg_replace_callback(
                '/MESMA cena em todos os cortes \([^)]*\)/u',
                fn () => 'MESMA cena em todos os cortes (' . $var['cena'] . ')',
                (string) $out
            );
        }

        if (! empty($var['luz'])) {
            $out = preg_replace_callback(
                '/mesma hora do dia\)/u',
                fn () => 'mesma hora do dia, ' . $var['luz'] . ')',
                (string) $out,
                1
            );
        }

        return (string) $out;
    }

    /** sorteia a variacao desta geracao (por cliente + aleatorio). */
    private static function sortearVariacao(int $N, string $estilo, array $v, int $userId): array
    {
        try {
            $mix = abs(crc32($userId . '|' . uniqid('', true) . '|' . random_int(0, 999999)));
        } catch (\Throwable $e) {
            $mix = abs(crc32($userId . '|' . microtime(true)));
        }

        $angulos = [];
        for ($i = 0; $i < $N; $i++) {
            $angulos[] = self::ANGULOS[($mix + $i * 3) % count(self::ANGULOS)];
        }
        $luz = self::LUZES[intdiv($mix, 7) % count(self::LUZES)];

        // cenario so varia quando o cliente NAO escolheu um, e so nos estilos em que a
        // cena e ambiente de casa (showcase e estudio; zero nao tem foto de referencia).
        $cena = null;
        if (trim((string) ($v['cenario'] ?? '')) === '' && in_array($estilo, ['ugc', 'pov'], true)) {
            $cena = self::CENAS[intdiv($mix, 13) % count(self::CENAS)];
        }

        return [
            'angulos'    => $angulos,
            'luz'        => $luz,
            'cena'       => $cena,
            'assinatura' => substr(md5($mix . '|' . implode('|', $angulos) . '|' . $luz . '|' . (string) $cena), 0, 10),
        ];
    }
}
