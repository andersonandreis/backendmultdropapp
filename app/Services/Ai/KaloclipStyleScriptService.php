<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * SEL-381 — Gera roteiro estruturado tipo Kaloclip (10 seções) via GPT.
 * Recebe produto (nome, fotos, preço, categoria) + duração desejada (10/20/40s)
 * e retorna JSON com as 10 seções do padrão viral TikTok Shop UGC:
 *   Style / Scene / Subject / Action Timeline (shots timestampados com [Plano] + fala)
 *   Camera / Framing / Performance / Lighting / Audio (diegetic) / Negative
 *
 * Depois esse JSON é convertido em prompt final Kling 3.0 Omni via toKlingPrompt().
 */
class KaloclipStyleScriptService
{
    /**
     * SEL-411 — ritmo de fala brasileira natural e BEM ARTICULADA, em palavras/segundo.
     *
     * Medido de verdade, nao chutado: o video reprovado pelo Ruan (pipeline 222,
     * kling_1785390675011) mandou 62 palavras pra caber em 11s = 5,64 palavras/s.
     * O Whisper transcreveu aquilo como "resistente ocular", "duas modelinas",
     * "clique na carinha la embaixo" — ou seja, ininteligivel.
     *
     * Locucao publicitaria brasileira clara fica em 2,2-3,0 pal/s. Adotamos 2,2
     * como TETO de projeto (a regra do Ruan e "caber com folga, nao no limite").
     * Conferido contra TTS real: 23 palavras renderizaram em 10,7s = 2,15 pal/s,
     * com transcricao 100% correta.
     */
    /**
     * SEL-RITMO-TIKTOK (14/08) — 2.2 pal/s = 132 palavras por minuto. Isso e ritmo de
     * CONVERSA, nao de anuncio: medi os videos entregues hoje e deu 98 a 120 ppm
     * (#1084 16 palavras/8s, #1082 e #1079 13/8s). Anuncio UGC de TikTok que segura
     * o dedo vive entre 180 e 220 ppm. O Ruan viu na live: "parece que a fala esta
     * lenta". 2.9 pal/s = 174 ppm — sobe o ritmo pra faixa de anuncio SEM ir pro
     * extremo, porque palavra demais faz o Veo cortar a frase no fim (bug ja conhecido).
     */
    private const WORDS_PER_SECOND = 2.9;

    /** Segundos reservados no fim do video pra fala nunca ser cortada no meio. */
    /**
     * SEL-RITMO-TIKTOK (14/08): 1s de cauda muda num video de 8s e 12,5% do anuncio
     * em silencio — e o final e justo onde entra o "clica no carrinho". 0.5s ainda
     * protege a ultima palavra de ser cortada, e devolve meio segundo de fala.
     */
    private const TAIL_SILENCE_SEC = 0.5;

    /**
     * SEL-413 — segundos de respiro no COMECO, antes da primeira palavra.
     * So entra na conta do orcamento do primeiro shot; o teto total (maxWordsFor)
     * continua exatamente como o SEL-411 deixou, pra nao mexer no pt-BR que ja passou.
     */
    private const HEAD_SILENCE_SEC = 0.3;

    /**
     * SEL-413 — piso de ocupacao de um shot. Abaixo disso a locucao acaba antes do
     * corte e o Kling fica parado: e exatamente o buraco que o Ruan ouviu.
     */
    private const SHOT_FILL_FLOOR = 0.85;

    /**
     * SEL-413 — ritmo de fala POR IDIOMA, medido em video real, nao chutado.
     *
     * pt-BR 2,2 pal/s: herdado do SEL-411 (ver WORDS_PER_SECOND). Nao mexer.
     *
     * es-419 2,3 pal/s: medido no render SEL413_es.mp4 (Kling, audio nativo espanhol).
     * Descontando os dois buracos, a locucao real ficou em 2,0 / 2,46 / 2,77 pal/s por
     * frase — media ~2,4. Adotamos 2,3 como teto de projeto pra sobrar folga, seguindo
     * a mesma regra do Ruan: "caber com folga, nao no limite".
     *
     * ATENCAO — estes numeros valem pro KLING (audio nativo do video). Cada motor de voz
     * tem ritmo proprio e NAO pode usar esta tabela:
     *   - ElevenLabs voz Carla (7eUAxNOneHxqfyRS77mW), medido em SEL-413B:
     *     24 palavras pt-BR renderizaram em 8,78s = 2,73 pal/s.
     *   - OpenAI TTS voz nova, medido em SEL-413B: 25 palavras es = 8,02s = 3,12 pal/s.
     * Ou seja: texto dimensionado pelo ritmo do Kling fica CURTO quando lido pela Carla.
     * Pra faixa de audio dublada, dimensione pelo ritmo do motor que vai locutar.
     */
    private const LANGS = [
        'pt-BR' => [
            // SEL-RITMO-TIKTOK-PTBR (14/08) — ESTE e o numero que vale (a constante
            // WORDS_PER_SECOND virou so documentacao). 2.5 pal/s dava 15-18 palavras
            // em 8s; medido nos videos entregues hoje, saiu 98-120 ppm — ritmo de
            // conversa, nao de anuncio. 2.9 pal/s = 21 palavras em 7,5s de fala =
            // ~168 ppm, dentro da faixa de anuncio de TikTok (180-220) sem estufar
            // a frase a ponto do Veo cortar o fim.
            'wps' => 2.9,
            'label' => 'português do Brasil (pt-BR)',
        ],
        'es-419' => [
            'wps' => 2.3,
            'label' => 'espanhol latino-americano neutro (es-419)',
        ],
        // SEL-453 (30/07, Ruan: "porque nao pode selecionar ingles"). O motor JA
        // narra em ingles -- o KlingBrowserService::garanteIdioma tem o ramo 'en'
        // desde o SEL-417. O que faltava era o ROTEIRO em ingles: sem ele o
        // normalizeLang jogava tudo pra pt-BR e o cliente pediria ingles e
        // receberia roteiro em portugues.
        // wps 2.3 e CONSERVADOR, ainda nao medido em audio real como pt e es.
        // Escolhido pra baixo de proposito: texto mais curto sobra silencio no
        // fim, texto mais longo e cortado no meio -- e fala cortada mata o anuncio.
        'en' => [
            'wps' => 2.3,
            'label' => 'ingles americano (en)',
        ],
    ];

    /**
     * SEL-421 — ritmo de fala POR MOTOR DE VOZ, medido em audio real.
     *
     * O bloco acima ja avisava: "estes numeros valem pro KLING (audio nativo).
     * Cada motor de voz tem ritmo proprio e NAO pode usar esta tabela. Pra faixa
     * de audio dublada, dimensione pelo ritmo do motor que vai locutar."
     * O aviso estava certo e nunca foi implementado — o roteiro continuava
     * dimensionado pelo ritmo do Kling mesmo quando quem locutava era o
     * ElevenLabs. Resultado medido em 30/07: fala acabou aos 7,16s de um video
     * de 12,04s. Quatro segundos e oitenta de silencio no fim.
     *
     * voz clonada do Ruan (tuFazJVCwiszby0YDkFk) 3,35 pal/s: medido em 30/07.
     *   E a voz do caminho pt-BR demo. Com 12s isso da ~34 palavras, contra as
     *   24 que o teto do Kling mandava — dai o buraco.
     * Carla (7eUAxNOneHxqfyRS77mW) 2,73 pal/s: medido no SEL-413B.
     * OpenAI 'nova' 3,12 pal/s: medido no SEL-413B.
     */
    private const VOZES = [
        'tuFazJVCwiszby0YDkFk' => 3.35,
        '7eUAxNOneHxqfyRS77mW' => 2.73,
        'nova'                 => 3.12,
    ];

    /**
     * Voz que vai locutar ESTA geracao. null = ninguem dubla, o audio e o nativo
     * do Kling, e ai vale a tabela LANGS.
     */
    private ?string $vozLocutora = null;

    public function __construct(private OpenAiService $openai) {}

    /** Normaliza e valida o codigo de idioma. Default pt-BR (comportamento historico). */
    public function normalizeLang(?string $lang): string
    {
        $l = trim((string) $lang);
        if ($l === '') { return 'pt-BR'; }
        foreach (array_keys(self::LANGS) as $k) {
            if (strcasecmp($k, $l) === 0) { return $k; }
        }
        // aceita 'es', 'pt', 'es-ES', 'pt_BR'...
        $p = strtolower(substr(str_replace('_', '-', $l), 0, 2));

        if ($p === 'es') return 'es-419';
        if ($p === 'en') return 'en';   // SEL-453

        return 'pt-BR';
    }

    /**
     * Palavras por segundo que valem pra ESTA geracao.
     *
     * Se alguem vai dublar (ElevenLabs/OpenAI), manda o ritmo do motor: e ele
     * quem determina quanto tempo o texto ocupa. Sem dublagem, o audio e o
     * nativo do Kling e vale a tabela por idioma.
     */
    public function wordsPerSecond(string $lang = 'pt-BR'): float
    {
        if ($this->vozLocutora !== null && isset(self::VOZES[$this->vozLocutora])) {
            return self::VOZES[$this->vozLocutora];
        }

        return self::LANGS[$this->normalizeLang($lang)]['wps'];
    }

    /** Define quem loculta (id da voz) antes de dimensionar o roteiro. */
    public function definirVozLocutora(?string $voiceId): void
    {
        $this->vozLocutora = $voiceId ?: null;
    }

    /**
     * SEL-411 — teto de palavras da fala, CALCULADO a partir da duracao.
     * Nunca chutar: e (duracao - folga) x palavras por segundo.
     * SEL-413 — ganhou idioma. Sem argumento, resultado identico ao de antes.
     */
    public function maxWordsFor(int $durationSec, string $lang = 'pt-BR'): int
    {
        $falaSec = max(1.0, $durationSec - self::TAIL_SILENCE_SEC);

        return (int) floor($falaSec * $this->wordsPerSecond($lang));
    }

    /**
     * SEL-413 — PISO de palavras. Texto curto demais nao "fica seco": deixa o Kling
     * mudo no meio do video. O piso e o teto sao os dois lados da mesma regra.
     */
    public function minWordsFor(int $durationSec, string $lang = 'pt-BR'): int
    {
        return (int) ceil($this->maxWordsFor($durationSec, $lang) * self::SHOT_FILL_FLOOR);
    }

    /**
     * SEL-413 — ORCAMENTO DE PALAVRAS POR SHOT. Esta e a correcao de verdade.
     *
     * Diagnostico do render reprovado (SEL413_es.mp4): a fala tinha 24 palavras num
     * teto de 24 — o TOTAL estava certo. O que estava errado era a DISTRIBUICAO:
     *   [0.0-4.5] 7 palavras = 1,56 pal/s  <- shot vazio, acabou o texto aos 3,75s
     *   [4.5-8.5] 8 palavras = 2,00 pal/s
     *   [8.5-12 ] 9 palavras = 2,57 pal/s  <- shot estufado
     * Os dois buracos medidos (3,75s->5,00s e 8,25s->8,75s) caem EXATAMENTE nas
     * fronteiras de shot 4,5s e 8,5s. Com Multi-Shot ligado o Kling trata cada shot
     * como um segmento: se o texto do shot acaba antes do corte, ele fica parado.
     *
     * Retorna, por shot: janela util, alvo, minimo e maximo de palavras.
     */
    public function shotWordBudget(array $timeline, int $durationSec, string $lang = 'pt-BR'): array
    {
        $wps = $this->wordsPerSecond($lang);
        $fimFala = max(0.1, $durationSec - self::TAIL_SILENCE_SEC);
        $out = [];
        foreach (array_values($timeline) as $i => $s) {
            $ini = (float) ($s['start_s'] ?? 0);
            $fim = (float) ($s['end_s'] ?? 0);
            // primeiro shot perde o respiro inicial; ultimo shot perde a cauda muda
            $ini = $i === 0 ? $ini + self::HEAD_SILENCE_SEC : $ini;
            $fim = min($fim, $fimFala);
            $janela = max(0.0, $fim - $ini);
            $alvo = $janela * $wps;
            $out[] = [
                'shot' => $i + 1,
                'start_s' => (float) ($s['start_s'] ?? 0),
                'end_s' => (float) ($s['end_s'] ?? 0),
                'janela_fala_s' => round($janela, 2),
                'alvo_palavras' => (int) round($alvo),
                'min_palavras' => (int) floor($alvo * self::SHOT_FILL_FLOOR),
                'max_palavras' => (int) ceil($alvo * 1.10),
            ];
        }

        return $out;
    }

    /**
     * SEL-413 — extrai a fala entre aspas da descricao de um shot.
     * O Kling alinha fala->shot pelo trecho entre aspas, entao e assim que se mede
     * quanto texto cada shot realmente carrega.
     */
    public function speechOfShot(array $shot): string
    {
        $d = (string) ($shot['description'] ?? '');
        preg_match_all('/["\x{201C}\x{201D}\x{2018}\x{2019}\x{27}](.+?)["\x{201C}\x{201D}\x{2018}\x{2019}\x{27}]/u', $d, $m);

        return trim(implode(' ', $m[1] ?? []));
    }

    /**
     * SEL-413 — auditoria de densidade. Roda ANTES de gastar credito de render.
     * Devolve ['ok'=>bool, 'problemas'=>[...], 'shots'=>[...], 'total'=>[...]].
     */
    public function checkDensity(array $script, int $durationSec, string $lang = 'pt-BR'): array
    {
        $lang = $this->normalizeLang($lang);
        $timeline = $script['action_timeline'] ?? [];
        $orc = $this->shotWordBudget($timeline, $durationSec, $lang);
        $problemas = [];
        $shots = [];

        foreach (array_values($timeline) as $i => $s) {
            $fala = $this->speechOfShot($s);
            $n = $this->countWords($fala);
            $b = $orc[$i] ?? null;
            if (! $b) { continue; }
            $dens = $b['janela_fala_s'] > 0 ? $n / $b['janela_fala_s'] : 0.0;
            $status = 'ok';
            if ($n < $b['min_palavras']) {
                $status = 'VAZIO';
                $problemas[] = sprintf(
                    'shot %d [%.1f-%.1fs]: %d palavras para %.1fs (%.2f pal/s) — minimo %d. '
                    . 'A fala acaba antes do corte e o video fica MUDO na virada.',
                    $b['shot'], $b['start_s'], $b['end_s'], $n, $b['janela_fala_s'], $dens, $b['min_palavras']
                );
            } elseif ($n > $b['max_palavras']) {
                $status = 'ESTUFADO';
                $problemas[] = sprintf(
                    'shot %d [%.1f-%.1fs]: %d palavras para %.1fs (%.2f pal/s) — maximo %d. '
                    . 'A locucao atropela e o cliente nao entende.',
                    $b['shot'], $b['start_s'], $b['end_s'], $n, $b['janela_fala_s'], $dens, $b['max_palavras']
                );
            }
            $shots[] = $b + ['palavras' => $n, 'pal_por_s' => round($dens, 2), 'status' => $status, 'fala' => $fala];
        }

        $totFala = $this->countWords((string) ($script['audio_diegetic'] ?? ''));
        $tetoTot = $this->maxWordsFor($durationSec, $lang);
        $pisoTot = $this->minWordsFor($durationSec, $lang);
        if ($totFala > $tetoTot) {
            $problemas[] = "fala total {$totFala} palavras acima do teto {$tetoTot}";
        } elseif ($totFala < $pisoTot) {
            $problemas[] = "fala total {$totFala} palavras abaixo do piso {$pisoTot} — sobra silencio";
        }

        return [
            'ok' => $problemas === [],
            'idioma' => $lang,
            'problemas' => $problemas,
            'shots' => $shots,
            'total' => ['palavras' => $totFala, 'piso' => $pisoTot, 'teto' => $tetoTot],
        ];
    }

    /** Conta palavras de um texto (acentos incluidos). */
    public function countWords(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Gera roteiro estruturado.
     * @param array $product ['name','images','price','category','description']
     * @param int $durationSec 10|20|40
     * @param string $tone 'informativo'|'urgente'|'carinhoso'|'engracado'
     * @param array $opts SEL-413: ['lang'=>'pt-BR'|'es-419', 'shots'=>int]
     *                    Sem $opts o comportamento e identico ao de antes (pt-BR).
     * @return array 10 seções + shots array
     */
    public function generate(array $product, int $durationSec = 10, string $tone = 'informativo', array $opts = []): array
    {
        $lang = $this->normalizeLang($opts['lang'] ?? null);
        // SEL-421: quem loculta define o tamanho do texto, nao o idioma.
        $this->definirVozLocutora($opts['voice_id'] ?? null);
        $numShots = (int) ($opts['shots'] ?? 0) > 0
            ? (int) $opts['shots']
            : $this->numShotsFor($durationSec);
        $productName = $product['name'] ?? 'produto';
        $productPrice = $product['price'] ?? null;
        $category = $product['category'] ?? 'geral';
        $description = $product['description'] ?? '';
        $imagesCount = count($product['images'] ?? []);
        // SEL-411: teto CALCULADO, nao chutado. Ver constante WORDS_PER_SECOND.
        $maxWords = $this->maxWordsFor($durationSec, $lang);
        // SEL-RITMO-E-PISO (15/08): o piso existia (minWordsFor) e era CODIGO MORTO —
        // ninguem chamava. Sem piso, o modelo sempre encolhia e o cliente recebia
        // vídeo com sobra de silencio. Agora o prompt recebe os dois lados da faixa.
        $minWords = $this->minWordsFor($durationSec, $lang);
        $falaSec = max(1, $durationSec - self::TAIL_SILENCE_SEC);

        $systemPrompt = <<<PROMPT
Você é diretor de vídeo UGC para TikTok Shop Brasil. Produz roteiros estruturados no padrão viral BR (formato Kaloclip):
apresentadora feminina brasileira 20-30 anos, cenário aconchegante casa, iluminação natural,
câmera de mão dinâmica, close-ups do produto alternando com plano médio da apresentadora.

REGRAS DURAS (obrigatórias):
- 100% em português brasileiro. Nunca inglês em nenhuma seção.
- **FAIXA DE PALAVRAS DA FALA: entre {$minWords} e {$maxWords} palavras no total.**
  Isso é uma FAIXA, não um teto a evitar: a fala tem {$falaSec}s e o alvo é ocupar esse
  tempo INTEIRO falando. Ficar abaixo de {$minWords} é o pior erro possível — sobra
  silêncio, o vídeo parece arrastado e o cliente sente que não foi dito nada. Mire no
  topo da faixa. Passar de {$maxWords} atropela a locução; ficar abaixo de {$minWords}
  entrega um vídeo vazio. Frase curta e direta, uma ideia por frase — mas ocupe o tempo.
- Escreva como gente fala, não como anúncio escrito: frases curtas, uma ideia por frase.
  Corte adjetivo empilhado ("incrível", "maravilha", "super elegante" na mesma frase é lixo).
- Formato 9:16 vertical (só isso).
- Apresentadora fala DIRETAMENTE pra câmera, rosto voltado pra frente, boca visível.
- Fala SEMPRE encaixa em Plano Médio; voiceover (narração sem rosto) SEMPRE em Close-Up.
- Nunca fala em Close-Up (senão boca some).
- Nunca legenda dentro do vídeo (Kling erra texto).
- CTA final obrigatório ("clica no carrinho laranja embaixo", "corre que vai esgotar", "aproveita").
- Timing do áudio termina ~1s antes do fim do vídeo.

Retorna JSON com as 10 seções abaixo. NADA fora do JSON.
PROMPT;

        $userPrompt = <<<PROMPT
Produto: **{$productName}**
Categoria: {$category}
Preço: {$productPrice}
Descrição: {$description}
Fotos disponíveis: {$imagesCount}

Duração: **{$durationSec}s** dividida em **{$numShots} shots** (alterna Plano Médio ↔ Close-Up).
Tom da apresentadora: **{$tone}**

Devolva JSON com esta estrutura EXATA:
{
  "style": "descrição da vibe (ex: Vídeo UGC vertical autêntico focado em beleza)",
  "scene": "cenário onde grava (quarto/cozinha/etc, sem espelhos)",
  "subject": "descrição detalhada da apresentadora + produto único (cor, forma, embalagem)",
  "action_timeline": [
    {"start_s": 0.0, "end_s": 4.5, "shot": "Plano Médio", "description": "posição + ação + fala EXATA entre aspas"},
    {"start_s": 4.5, "end_s": 8.5, "shot": "Close-Up", "description": "foco no produto (rosto FORA), mãos giram embalagem + narração voiceover"},
    {"start_s": 8.5, "end_s": 12.0, "shot": "Plano Médio", "description": "volta pra apresentadora + CTA/urgência entre aspas"}
  ],
  "camera": "Câmera de mão dinâmica com movimentos sutis, nível dos olhos",
  "transitions": [
    {"de": 1, "para": 2, "movimento": "CORTE SECO: o quadro muda de uma vez pro próximo plano (outro ângulo, outra distância). Nunca dissolve, nunca fade"},
    {"de": 2, "para": 3, "movimento": "ex: pull-back do close abrindo de volta pro plano médio, acompanhando o movimento da mão"}
  ],
  "framing": "Centralizado apresentadora Plano Médio, close-up detalhado no produto",
  "performance": "Pessoa falando ativamente pra câmera, rosto voltado estritamente pra frente, boca sempre bem visível, movimentos naturais de apresentação",
  "lighting_color": "Iluminação suave e difusa vinda de janela, cores quentes vibrantes que realçam o produto",
  "audio_diegetic": "TEXTO CORRIDO da fala completa (Plano Médio 1 + narração Close-Up + Plano Médio 2 CTA). Deve terminar ~1s antes do fim.",
  "audio_timing": "Diálogo contínuo bem ritmado, terminando em {DURATION_MINUS_1}s pra evitar corte brusco",
  "negative": "sem sobreposições, sem flutuação, sem substituição, sem produtos duplicados, sem mãos extras, sem dedos distorcidos, sem espelhos, sem reflexos, sem texto legível, sem locução enquanto o rosto estiver visível e a boca não mexer, sem bocas obstruídas, sem lábios congelados, sem cenas de perfil, sem olhar pra trás"
}

IMPORTANTE:
- **audio_diegetic tem que ter NO MÁXIMO {$maxWords} PALAVRAS.** Conte antes de responder.
  Se passou, corte — não reescreva mais compacto, CORTE informação. Prioridade do que fica:
  (1) gancho nos primeiros 3s, (2) o benefício mais forte, (3) CTA. O resto sai.
- action_timeline deve ter exatamente {$numShots} shots totalizando {$durationSec}s
- audio_diegetic é 1 string única com tudo que ela fala (Plano Médio) + narra (Close-Up)
- {DURATION_MINUS_1} substituir por ({$durationSec}-1)
- Todas as falas EM PORTUGUÊS BRASILEIRO natural, jeito TikTok (informal, direto, gíria leve)
- **TRANSIÇÕES SÃO OBRIGATÓRIAS** e valem tanto quanto os shots. Ruan (29/07): "faltou a
  transição das câmeras, eu gosto quando faz essas transições de movimento". Corte seco entre
  planos deixa o vídeo sem graça. SEL-PROMPT-ORIGEM (13/08, regra do Ruan): o que dá dinâmica
  é o CORTE, não o movimento lento. Cada transição é um CORTE SECO pra outro enquadramento
  (plano médio -> close no rosto -> close nas mãos -> detalhe do produto), com a câmera em
  movimento DENTRO de cada plano. Medido: prompt sem corte = 0 corte em 9 vídeos.
- **NUNCA invente como o produto funciona.** Só descreva interação física que dá pra confirmar
  pela descrição recebida. Ruan (29/07): num vídeo a apresentadora apertava um controle e o
  ventilador ligava — o produto não funciona assim. Na dúvida, mostre o produto (girando na mão,
  em close, na embalagem) em vez de encenar um uso que pode estar errado.
- Duração mínima é 12s. Roteiro não cabe em menos: fala cortada no meio destrói o anúncio.
PROMPT;

        // SEL-413 — espanhol tem prompt proprio (o audio nativo do Kling suporta es,
        // nao suporta pt-BR). O pt-BR acima fica intocado.
        if ($lang === 'es-419') {
            [$systemPrompt, $userPrompt] = $this->promptsEs(
                $product, $durationSec, $numShots, $tone, $maxWords
            );
        }

        // SEL-453: ingles ganha caminho proprio, igual ao espanhol.
        if ($lang === 'en') {
            [$systemPrompt, $userPrompt] = $this->promptsEn(
                $product, $durationSec, $numShots, $tone, $maxWords
            );
        }

        // SEL-429 (Ruan 30/07): "era para dar uma ideia sobre o roteiro do produto,
        // NAO mudar o roteiro". O que o cliente escreveu entra LITERAL e manda no
        // roteiro. Sem client_brief o comportamento e identico ao de antes.
        $clientBrief = trim((string) ($opts['client_brief'] ?? ''));
        if ($clientBrief !== '') {
            $userPrompt .= "\n\n=== PEDIDO LITERAL DO CLIENTE (LEI — obedeca, nao reescreva a ideia dele) ===\n"
                . $clientBrief
                . "\n=== FIM DO PEDIDO ===\n"
                . "Se ele escreveu falas ou roteiro, USE AS PALAVRAS DELE em audio_diegetic — so encurte o\n"
                . "que nao couber no teto de palavras, mantendo o texto dele. Se ele descreveu cena, tom,\n"
                . "angulo ou ordem dos cortes, siga exatamente. NUNCA troque a ideia dele pela sua.";
        }

        // SEL-roteirocoerente (09/08, Ruan "roteiro desconexo + a pessoa recita a
        // selecao"): as ESCOLHAS do cliente (inicio/meio/fim, cenario, apresentadora,
        // camera) sao ANGULO/CONTEXTO pra o LLM ESCREVER o roteiro — NUNCA a fala.
        // A pessoa no video JAMAIS le o nome/descricao da opcao. Isto e o OPOSTO de
        // client_brief (que manda usar as palavras do cliente LITERAIS).
        $briefIn = $opts['brief'] ?? null;
        $briefTxt = is_array($briefIn)
            ? trim(implode("\n- ", array_filter(array_map('strval', $briefIn), fn ($x) => trim($x) !== '')))
            : trim((string) $briefIn);
        $estiloIn = strtolower(trim((string) ($opts['estilo'] ?? '')));
        if ($briefTxt !== '') {
            $vozModo = in_array($estiloIn, ['pov', 'showcase'], true)
                ? "ESTILO **{$estiloIn}**: NAO ha pessoa em cena. A fala e uma VOZ EM OFF (narracao) por cima "
                  . "de imagens do produto (e das maos, no pov). NAO descreva rosto/apresentadora nem gestos de "
                  . "quem fala pra camera na action_timeline — so o produto/maos. O texto e narracao em off."
                : "ESTILO **{$estiloIn}**: a apresentadora fala DIRETO pra camera; a fala e a voz dela em cena.";
            $userPrompt .= "\n\n=== ANGULO / CONTEXTO DESTA GERACAO (escolhas do cliente) ===\n"
                . "As linhas abaixo sao o ANGULO que o cliente escolheu. Use SO como direcao criativa pra voce\n"
                . "ESCREVER o roteiro. E PROIBIDO copiar, citar ou LER o nome/descricao de qualquer opcao como\n"
                . "fala: a pessoa NUNCA diz 'abrindo a embalagem', 'chamando pra comprar no link' nem o rotulo\n"
                . "de nenhuma escolha.\n"
                . "- " . $briefTxt . "\n\n"
                . "ESCREVA UMA UNICA fala natural de criador brasileiro, com COMECO -> MEIO -> FIM conectados:\n"
                . "1) COMECO (gancho, primeiros ~3s): para o scroll com dor/pergunta/curiosidade, no angulo escolhido.\n"
                . "2) MEIO: entrega o valor REAL do produto usando o NOME e a descricao do produto.\n"
                . "3) FIM: chamada pra acao natural.\n"
                . "Frases CURTAS e CONECTADAS (uma puxa a outra), como gente fala. Nada de recitar opcao, nada\n"
                . "de frase solta sem nexo. audio_diegetic e essa fala inteira, comeco-meio-fim.\n"
                . $vozModo . "\n=== FIM DO ANGULO ===";
        }

        try {
            $decoded = $this->poolChatJson([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ], 8000, 0.7);
            if (!$decoded || !isset($decoded['style'])) throw new \RuntimeException('GPT retornou JSON inválido');

            // SEL-411 — o teto nao pode depender da boa vontade do GPT. Se ele estourou,
            // reescrevemos UMA vez pedindo corte. Se ainda assim estourar, seguimos com o
            // texto encurtado mecanicamente: locucao atropelada e defeito que o Ruan reprova,
            // texto curto demais no maximo fica seco.
            $fala = (string) ($decoded['audio_diegetic'] ?? '');
            $n = $this->countWords($fala);
            if ($n > $maxWords) {
                Log::warning('KaloclipStyleScript: fala acima do teto, pedindo corte', [
                    'palavras' => $n, 'teto' => $maxWords, 'produto' => $productName,
                ]);
                $decoded['audio_diegetic'] = $this->shrinkSpeech($fala, $maxWords, $lang);
                $decoded['_sel411_fala_original'] = $fala;
            }
            $decoded['_sel411_palavras'] = $this->countWords((string) $decoded['audio_diegetic']);
            $decoded['_sel411_teto'] = $maxWords;

            // SEL-413 — idioma viaja junto com o roteiro: toKlingPrompt() le daqui.
            $decoded['_lang'] = $lang;

            // SEL-413 — auditoria de densidade POR SHOT. Nao aborta a geracao (o roteiro
            // ainda serve pra revisao humana), mas deixa o defeito registrado e visivel
            // em vez de so aparecer depois, no video mudo, com o credito ja gasto.
            $densidade = $this->checkDensity($decoded, $durationSec, $lang);
            $decoded['_sel413_densidade'] = $densidade;
            if (! $densidade['ok']) {
                Log::warning('SEL-413: densidade de fala fora do orcamento', [
                    'idioma' => $lang, 'produto' => $productName,
                    'problemas' => $densidade['problemas'],
                ]);
            }

            return $decoded;
        } catch (\Throwable $e) {
            Log::error('KaloclipStyleScript falhou', ['err' => $e->getMessage(), 'product' => $productName]);
            throw $e;
        }
    }

    /**
     * SEL-411 — encurta a fala pro teto. Tenta uma reescrita via GPT (mantem sentido);
     * se falhar, corta por frase inteira — nunca no meio de uma frase, que era exatamente
     * o defeito que o Ruan ouviu ("termina em 'nao perca a justa preguica'").
     */
    private function shrinkSpeech(string $fala, int $maxWords, string $lang = 'pt-BR'): string
    {
        // Mirar a faixa alta do teto: cortar demais deixa silencio sobrando no fim.
        $minWords = (int) max(1, floor($maxWords * 0.8));
        $idioma = $this->normalizeLang($lang) === 'es-419'
            ? 'espanhol latino-americano neutro'
            : 'português brasileiro';

        try {
            $r = $this->poolChatJson([
                ['role' => 'system', 'content' =>
                    "Você encurta falas de anúncio em {$idioma}. Devolve JSON {\"fala\":\"...\"}. "
                    . 'A fala encurtada tem que continuar NO MESMO IDIOMA do texto recebido. '
                    . 'Mantém o gancho inicial e o CTA final. Corta adjetivo e informação secundária. '
                    . 'Frases curtas, jeito de conversa. NUNCA ultrapasse o limite de palavras.'],
                ['role' => 'user', 'content' =>
                    "Encurte para entre {$minWords} e {$maxWords} palavras (conte antes de responder). "
                    . "Cortar demais deixa o vídeo com silêncio sobrando, então use bem essa faixa:\n\n{$fala}"],
            ], 500, 0.4);
            $nova = trim((string) ($r['fala'] ?? ''));
            if ($nova !== '' && $this->countWords($nova) <= $maxWords) {
                return $nova;
            }
        } catch (\Throwable $e) {
            Log::warning('SEL-411 shrinkSpeech: GPT falhou, corte mecânico', ['err' => $e->getMessage()]);
        }

        // Fallback mecânico: acumula frases inteiras até encostar no teto.
        $frases = preg_split('/(?<=[.!?])\s+/u', trim($fala), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        $tot = 0;
        foreach ($frases as $f) {
            $c = $this->countWords($f);
            if ($tot + $c > $maxWords) break;
            $out[] = $f;
            $tot += $c;
        }
        // Se nem a primeira frase coube, corta na palavra mas fecha com pontuação.
        if (!$out) {
            $p = preg_split('/\s+/u', trim($fala), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return rtrim(implode(' ', array_slice($p, 0, $maxWords)), ' ,;:') . '!';
        }

        return implode(' ', $out);
    }

    /**
     * SEL-413 — janelas padrao de shot. Pra 12s/3 shots usa a divisao ja aprovada
     * pelo Ruan (4,5 / 4,0 / 3,5 — o ultimo shot e mais curto porque perde 1s de cauda muda).
     */
    public function standardWindows(int $durationSec, int $numShots): array
    {
        if ($durationSec === 12 && $numShots === 3) {
            return [[0.0, 4.5], [4.5, 8.5], [8.5, 12.0]];
        }
        $w = [];
        $passo = $durationSec / max(1, $numShots);
        for ($i = 0; $i < $numShots; $i++) {
            $w[] = [round($i * $passo, 2), round(($i + 1) * $passo, 2)];
        }

        return $w;
    }

    /**
     * SEL-413 — prompts em ESPANHOL, com orcamento de palavras POR SHOT.
     *
     * Por que existe: o audio nativo do Kling suporta espanhol e NAO suporta pt-BR.
     * O fluxo aprovado pelo Ruan e gerar o video em espanhol (boca sincronizada com o
     * espanhol) e entregar junto a faixa de audio em portugues; a sincronia labial é
     * feita depois no CapCut.
     *
     * @return array{0:string,1:string} [systemPrompt, userPrompt]
     */
    /**
     * SEL-453 — roteiro em ingles. Espelha promptsEs: mesmo orcamento por toma,
     * mesma regra de a voz nunca calar, mesma verificacao no fim.
     */
    private function promptsEn(
        array $product, int $durationSec, int $numShots, string $tone, int $maxWords
    ): array {
        $nome = $product['name'] ?? 'product';
        $preco = $product['price'] ?? null;
        $cat = $product['category'] ?? 'general';
        $desc = $product['description'] ?? '';
        $minWords = $this->minWordsFor($durationSec, 'en');
        $fimFala = $durationSec - self::TAIL_SILENCE_SEC;

        $janelas = $this->standardWindows($durationSec, $numShots);
        $tl = array_map(fn ($j) => ['start_s' => $j[0], 'end_s' => $j[1]], $janelas);
        $orc = $this->shotWordBudget($tl, $durationSec, 'en');
        $linhasOrc = '';
        $exemploTl = [];
        foreach ($orc as $b) {
            $linhasOrc .= sprintf(
                "  - Shot %d [%.1fs - %.1fs]: voice window %.1fs -> %d words "
                . "(min %d, max %d)\n",
                $b['shot'], $b['start_s'], $b['end_s'], $b['janela_fala_s'],
                $b['alvo_palavras'], $b['min_palavras'], $b['max_palavras']
            );
            $plano = $b['shot'] % 2 === 1 ? 'Medium Shot' : 'Close-up';
            $exemploTl[] = sprintf(
                '    {"start_s": %.1f, "end_s": %.1f, "shot": "%s", "description": '
                . '"physical action + the EXACT line in quotes (%d words)"}',
                $b['start_s'], $b['end_s'], $plano, $b['alvo_palavras']
            );
        }
        $exemploTl = implode(",\n", $exemploTl);

        $system = <<<PROMPT
You are a UGC video director for TikTok Shop in American English. You write structured
viral-style scripts: a female presenter aged 20-30, cozy home setting, natural light,
handheld camera, alternating a medium shot of the presenter with a close-up of the product.

HARD RULES (mandatory):
- 100% in AMERICAN ENGLISH. Never Spanish. Never Portuguese.
- **THE VOICE MUST NEVER GO SILENT.** This is defect number one.
  The video is cut into shots; if a shot runs out of text before its cut, the video goes
  MUTE on the transition and the ad dies. Every shot has its own word budget and it must be
  respected shot by shot, not only in the total.
- The total does not save the distribution: a script with the right total but badly spread
  produces silence anyway. Count the words of EACH shot before answering.
- The sentence must CROSS the cut: a shot never ends on a full stop except the last one.
  End on a comma or an unfinished clause, and the next shot CONTINUES the idea.
- 9:16 vertical only.
- The presenter speaks DIRECTLY to camera, face forward, mouth visible.
- Never subtitles or on-screen text (the model spells it wrong).
- Mandatory closing CTA ("tap the cart", "grab yours before it's gone", "get it now").
- NEVER invent how the product works. If the description does not confirm it, just show
  the product (turning it in the hand, close-up, the packaging).

Return JSON. NOTHING outside the JSON.
PROMPT;

        $user = <<<PROMPT
Product: **{$nome}**
Category: {$cat}
Price: {$preco}
Description: {$desc}

Duration: **{$durationSec}s** across **{$numShots} shots**. The voice starts at 0.3s and ENDS at {$fimFala}s.

**WORD BUDGET PER SHOT (calculated, non-negotiable):**
{$linhasOrc}
Total voice-over: between {$minWords} and {$maxWords} words.

Tone: **{$tone}**

Return JSON with this EXACT structure. Text inside <> are INSTRUCTIONS:
replace them with real content, NEVER copy them literally.
{
  "style": "<visual vibe of the video, e.g. homemade vertical social UGC, fast pace>",
  "scene": "<concrete place where it happens, no mirrors>",
  "subject": "<PHYSICAL DESCRIPTION OF THE WOMAN SPEAKING TO CAMERA: age, hair, clothes, and that she is holding the product. It MUST be a PERSON, not the product alone>",
  "action_timeline": [
{$exemploTl}
  ],
  "camera": "<camera movement, handheld UGC style, at eye level>",
  "transitions": [
    {"de": 1, "para": 2, "movimento": "HARD CUT: the frame switches at once to the next shot (different angle, different distance). Never dissolve, never fade"},
    {"de": 2, "para": 3, "movimento": "HARD CUT: the frame switches at once to the next shot (different angle, different distance). Never dissolve, never fade"}
  ],
  "framing": "presenter centered in medium shot, detailed close-up of the product",
  "performance": "actively speaking to camera, face forward, mouth clearly visible",
  "lighting_color": "soft diffused window light, warm colors that make the product pop",
  "audio_diegetic": "FULL RUNNING TEXT of the whole voice-over, exactly the concatenation of the shot lines, in order",
  "audio_timing": "continuous voice with no pauses between shots, starts at 0.3s and ends at {$fimFala}s",
  "negative": "no overlays, no extra hands, no deformed fingers, no mirrors, no reflections, no readable text, no subtitles, no frozen lips, no profile shots, no covered mouth"
}

THE HOOK: the first shot has to hook in under 3 seconds. Open with the customer's PAIN or a
direct question ("Are your knives useless?"), never with generic praise of the product
("this kit is perfect") — that hooks nobody.

MANDATORY CHECK before answering:
1. Count the words of the quoted line of EACH shot and compare to the budget above. If a
   shot came out short, ADD text to that shot — do not move it to another shot.
2. "audio_diegetic" must be exactly the concatenation of the shot lines.
3. Only the last shot ends with a full stop. The others cross the cut: they end on a comma
   or an unfinished idea, and the next shot CONTINUES the sentence.
4. No field may keep the instruction text inside <>.
5. "subject" describes a PERSON (woman), not the product.
PROMPT;

        return [$system, $user];
    }

    private function promptsEs(
        array $product, int $durationSec, int $numShots, string $tone, int $maxWords
    ): array {
        $nome = $product['name'] ?? 'producto';
        $preco = $product['price'] ?? null;
        $cat = $product['category'] ?? 'general';
        $desc = $product['description'] ?? '';
        $minWords = $this->minWordsFor($durationSec, 'es-419');
        $fimFala = $durationSec - self::TAIL_SILENCE_SEC;

        // orcamento por shot, calculado — nunca chutado
        $janelas = $this->standardWindows($durationSec, $numShots);
        $tl = array_map(fn ($j) => ['start_s' => $j[0], 'end_s' => $j[1]], $janelas);
        $orc = $this->shotWordBudget($tl, $durationSec, 'es-419');
        $linhasOrc = '';
        $exemploTl = [];
        foreach ($orc as $b) {
            $linhasOrc .= sprintf(
                "  - Toma %d [%.1fs - %.1fs]: ventana de voz %.1fs -> %d palabras "
                . "(minimo %d, maximo %d)\n",
                $b['shot'], $b['start_s'], $b['end_s'], $b['janela_fala_s'],
                $b['alvo_palavras'], $b['min_palavras'], $b['max_palavras']
            );
            $plano = $b['shot'] % 2 === 1 ? 'Plano Medio' : 'Primer Plano';
            $exemploTl[] = sprintf(
                '    {"start_s": %.1f, "end_s": %.1f, "shot": "%s", "description": '
                . '"accion fisica + la frase EXACTA entre comillas (%d palabras)"}',
                $b['start_s'], $b['end_s'], $plano, $b['alvo_palavras']
            );
        }
        $exemploTl = implode(",\n", $exemploTl);

        $system = <<<PROMPT
Eres director de video UGC para TikTok Shop en espanol latinoamericano. Produces guiones
estructurados al estilo viral: presentadora latina de 20-30 anos, escenario hogareno acogedor,
luz natural, camara en mano, alternando plano medio de la presentadora con primer plano del producto.

REGLAS DURAS (obligatorias):
- 100% en ESPANOL LATINOAMERICANO NEUTRO (es-419). Nunca ingles. Nunca portugues.
  Nunca espanol de Espana: prohibido "vosotros" y prohibido el ceceo.
- **LA VOZ NO PUEDE CALLARSE EN NINGUN MOMENTO.** Este es el defecto numero uno.
  El video se corta en tomas; si el texto de una toma se acaba antes del corte, el video
  queda MUDO en la transicion y el anuncio muere. Cada toma tiene su propio presupuesto
  de palabras y hay que RESPETARLO toma por toma, no solo en el total.
- El total no salva la distribucion: un guion con el total correcto pero mal repartido
  produce silencio igual. Cuenta las palabras de CADA toma antes de responder.
- La frase debe CRUZAR el corte: la toma no termina con punto final salvo la ultima.
  Termina en coma o en frase suspendida, y la toma siguiente CONTINUA la idea.
  Asi el Kling no encuentra un lugar comodo para hacer una pausa.
- Formato 9:16 vertical unicamente.
- La presentadora habla DIRECTAMENTE a la camara, rostro al frente, boca visible.
- Nunca subtitulos ni texto en pantalla (el modelo escribe mal).
- CTA final obligatorio ("toca el carrito", "corre que se agota", "llevatelo ahora").
- NUNCA inventes como funciona el producto. Si la descripcion no lo confirma, solo
  muestra el producto (girandolo en la mano, en primer plano, el empaque).

Devuelve JSON. NADA fuera del JSON.
PROMPT;

        $user = <<<PROMPT
Producto: **{$nome}**
Categoria: {$cat}
Precio: {$preco}
Descripcion: {$desc}

Duracion: **{$durationSec}s** en **{$numShots} tomas**. La voz empieza en 0.3s y TERMINA en {$fimFala}s.

**PRESUPUESTO DE PALABRAS POR TOMA (calculado, no negociable):**
{$linhasOrc}
Total de la locucion: entre {$minWords} y {$maxWords} palabras.

Tono: **{$tone}**

Devuelve JSON con esta estructura EXACTA. Los textos entre <> son INSTRUCCIONES:
reemplazalos por contenido real, NUNCA los copies tal cual.
{
  "style": "<vibra visual del video, ej: UGC casero vertical para redes sociales, ritmo rapido>",
  "scene": "<lugar concreto donde ocurre, sin espejos>",
  "subject": "<DESCRIPCION FISICA DE LA MUJER QUE HABLA A CAMARA: edad, pelo, ropa, y que sostiene el producto. OBLIGATORIO que sea una PERSONA, no el producto solo>",
  "action_timeline": [
{$exemploTl}
  ],
  "camera": "<movimiento de camara, estilo UGC en mano, a la altura de los ojos>",
  "transitions": [
    {"de": 1, "para": 2, "movimiento": "CORTE SECO: el cuadro cambia de golpe al siguiente plano (otro angulo, otra distancia). Nunca disuelve, nunca fade"},
    {"de": 2, "para": 3, "movimiento": "CORTE SECO: el cuadro cambia de golpe al siguiente plano (otro angulo, otra distancia). Nunca disuelve, nunca fade"}
  ],
  "framing": "presentadora centrada en plano medio, primer plano detallado del producto",
  "performance": "habla activamente a la camara, rostro al frente, boca bien visible",
  "lighting_color": "luz suave y difusa de ventana, colores calidos que resaltan el producto",
  "audio_diegetic": "TEXTO CORRIDO de toda la locucion, exactamente la union de las frases de las tomas, en el mismo orden",
  "audio_timing": "voz continua sin pausas entre tomas, empieza en 0.3s y termina en {$fimFala}s",
  "negative": "sin superposiciones, sin manos extra, sin dedos deformes, sin espejos, sin reflejos, sin texto legible, sin subtitulos, sin labios congelados, sin tomas de perfil, sin boca tapada"
}

EL GANCHO: la primera toma tiene que enganchar en menos de 3 segundos. Empieza por el
DOLOR de la clienta o por una pregunta directa ("¿Tus ollas se rayan?"), nunca por un
elogio generico del producto ("este kit es perfecto") — eso no engancha a nadie.

VERIFICACION OBLIGATORIA antes de responder:
1. Cuenta las palabras de la frase entre comillas de CADA toma y comparalas con el
   presupuesto de arriba. Si una toma quedo corta, AGREGA texto a esa toma —
   no lo pases a otra toma.
2. "audio_diegetic" tiene que ser exactamente la union de las frases de las tomas.
3. Solo la ultima toma termina con punto final. Las demas cruzan el corte:
   terminan en coma o en idea suspendida, y la toma siguiente CONTINUA la frase.
4. Ningun campo puede quedar con el texto de instruccion entre <>.
5. "subject" describe a una PERSONA (mujer), no al producto.
PROMPT;

        return [$system, $user];
    }

    private function numShotsFor(int $sec): int
    {
        return match (true) {
            $sec <= 10 => 2,   // 5s + 5s
            $sec <= 20 => 4,   // 5s × 4
            $sec <= 40 => 8,   // 5s × 8
            default => (int) ceil($sec / 5),
        };
    }

    /**
     * SEL-411 — prompt do modo MOSTRUÁRIO (voiceover).
     *
     * Por que existe: o áudio nativo do Kling NÃO suporta português. A documentação
     * oficial lista inglês, chinês, japonês, coreano e espanhol — pt-BR não está lá.
     * Por isso a locução saía atropelada e sem sotaque brasileiro, e nenhum ajuste de
     * prompt resolvia: é limitação do motor, não de redação.
     *
     * Aqui a apresentadora NÃO fala na câmera. Ela mostra o produto. A locução é colada
     * depois (TTS nosso), o que dá controle total de velocidade, voz e sotaque — e sem
     * boca falando não existe lipsync pra quebrar.
     */
    public function toShowcasePrompt(array $script): string
    {
        // SEL-antilogo-studio (09/08, Ruan): tira nome de marca (TikTok etc.) dos campos
        // vindos do GPT antes de montar o prompt -> o modelo NAO desenha a logo (#517).
        foreach (["style","scene","subject","performance","audio_diegetic","negative","framing","lighting_color","product_desc"] as $__f) {
            if (! empty($script[$__f]) && is_string($script[$__f])) {
                $script[$__f] = self::neutralizeBrands($script[$__f]);
            }
        }
        $timeline = '';
        foreach ($script['action_timeline'] ?? [] as $s) {
            // Tira a fala das descrições: no mostruário ninguém fala na câmera.
            $d = (string) ($s['description'] ?? '');
            $d = preg_replace('/["\x{201C}\x{201D}\x{2018}\x{2019}\'](.+?)["\x{201C}\x{201D}\x{2018}\x{2019}\']/u', '', $d);
            $d = preg_replace('/\b(e )?(diz|fala|narra[çc][ãa]o|narra)\s*:?\s*/iu', '', (string) $d);
            $d = trim(preg_replace('/\s{2,}/u', ' ', (string) $d), " \t\n\r\0\x0B-–—:,");
            $timeline .= "[{$s['start_s']}s - {$s['end_s']}s] [{$s['shot']}] {$d}\n";
        }

        return self::neutralizeBrands(implode("\n\n", array_filter([
            'VIDEO SEM DIALOGO. Ninguem fala com a camera. Nenhum personagem pronuncia palavra '
            . 'alguma. A apresentadora APRESENTA o produto com as maos e com expressao, de boca '
            . 'fechada ou sorrindo — nunca articulando fala. A narracao e adicionada depois, fora '
            . 'do video. NAO gere voz. NAO gere fala. NAO gere lip sync.',

            'ESTILO: ' . ($script['style'] ?? ''),
            'CENARIO: ' . ($script['scene'] ?? ''),
            'APRESENTADORA: ' . ($script['subject'] ?? ''),
            "ROTEIRO POR TEMPO (acao fisica, sem fala):\n" . trim($timeline),
            'CAMERA: ' . ($script['camera'] ?? ''),
            $this->transitionsBlock($script),
            'ENQUADRAMENTO: ' . ($script['framing'] ?? ''),
            'ATUACAO: expressiva pelo CORPO e pelo OLHAR, nao pela fala. Sorri, levanta a '
            . 'sobrancelha, aprova com a cabeca, gira o produto na mao, aponta o detalhe. '
            . 'Boca fechada ou sorriso — sem articular palavras, sem simular conversa.',
            'ILUMINACAO/COR: ' . ($script['lighting_color'] ?? ''),
            'AUDIO: apenas som ambiente natural e discreto da cena. SEM VOZ. SEM NARRACAO. '
            . 'SEM MUSICA CANTADA. Nenhuma palavra falada em nenhum idioma.',
            'EVITAR: ' . ($script['negative'] ?? '') . ', sem boca articulando fala, sem lip sync, '
            . 'sem personagem falando, sem voz, sem narracao, sem legendas, sem texto na tela, '
            . 'sem olhar fixo e parado para a camera',
            self::ANTI_LOGO_STUDIO,
        ])));
    }

    /** Bloco de transições compartilhado pelos dois modos de prompt. */
    private function transitionsBlock(array $script): ?string
    {
        $t = $script['transitions'] ?? [];
        if (! is_array($t) || ! $t) { return null; }
        $l = [];
        foreach ($t as $x) {
            $mv = is_array($x) ? ($x['movimento'] ?? '') : (string) $x;
            if (trim((string) $mv) !== '') { $l[] = '- ' . trim((string) $mv); }
        }

        return $l ? "TRANSICOES (cada troca de plano e um CORTE SECO instantaneo; a camera se move DENTRO de cada plano):\n" . implode("\n", $l) : null;
    }

    /**
     * Converte JSON estruturado em prompt único pro Kling 3.0 Omni (text-to-video com Native Audio).
     */
    // SEL-antilogo-studio (09/08, Ruan): a palavra "TikTok" no texto do prompt fazia
    // o Veo/Flow DESENHAR a logo do TikTok dentro do video (bug do video #517, estilo
    // "Video UGC TikTok..."). A palavra vinha do roteiro do GPT (campo style). Aqui a
    // gente (1) proibe desenhar logo e (2) tira nome de marca do texto que vai pro modelo.
    public const ANTI_LOGO_STUDIO = "SEM MARCAS NEM LOGOS: e TERMINANTEMENTE PROIBIDO desenhar, "
        . "renderizar ou sobrepor QUALQUER logotipo, marca, selo, marca dagua, nome ou icone de "
        . "aplicativo, rede social ou marketplace no video. O video mostra APENAS a cena e o "
        . "produto limpos, sem nenhum logo, marca, icone ou texto de marca por cima.";

    /** Tira nome de marca do texto do prompt (senao o modelo desenha a logo). */
    public static function neutralizeBrands(string $prompt): string
    {
        return strtr($prompt, [
            "TikTok Shop"  => "vitrine de vendas online",
            "Tiktok Shop"  => "vitrine de vendas online",
            "TikTok trend" => "tendencia de video vertical",
            "TikTok/Reels" => "vertical para redes sociais",
            "TikTok"       => "video vertical de redes sociais",
            "Tiktok"       => "video vertical de redes sociais",
            "tiktok"       => "video vertical de redes sociais",
        ]);
    }

    /**
     * SEL-PROMPT-ORIGEM (13/08) — prompt do fluxo CURTO reescrito a partir de medicao.
     *
     * O QUE MUDOU E POR QUE (tudo medido no laboratorio, motor Veo 3.1 Lite, 100+ geracoes):
     *
     * 1. CORTE DE CAMERA. Regra do Ruan (13/08): "o que determina a qualidade e a
     *    transicao e o corte de camera; video parado com a pessoa falando devagar
     *    denuncia IA". O prompt ANTIGO nao produzia corte NENHUM: 0 cortes em 4 videos
     *    medidos (deteccao de cena ffmpeg, limiar 0.30). Pior: o roteirista era
     *    instruido a "nunca corta para" e as transicoes viravam "movimento continuo
     *    entre os cortes, nunca corte seco" — a gente PEDIA video parado.
     *    Agora o prompt usa a sintaxe oficial de timestamp do Veo 3.1
     *    ([00:00-00:02] uma linha por plano) + "CORTE SECO". Medido: 4 planos rendem
     *    1,12 corte de media (75% dos videos com pelo menos um), contra 0,00 do antigo.
     *
     * 2. TAMANHO. O prompt antigo chegava ao Flow com ~5.200 caracteres e pedia o
     *    idioma TRES vezes (aqui, no garanteIdioma e no PTBR_LOCK do job). Nao existe
     *    corte de 1.800 no Flow — isso foi MEDIDO: o campo aceitou 5.397 caracteres sem
     *    truncar e uma instrucao plantada no caractere 5.042 foi obedecida no video.
     *    Ou seja, o problema nunca foi truncamento: era repeticao e diluicao. O corpo
     *    agora fica em ~1.500 caracteres.
     *
     * 3. O token "IDIOMA:" fica no bloco de FALA de proposito: e o que faz
     *    KlingBrowserService::garanteIdioma() nao colar um segundo bloco de idioma.
     *    O pt-BR "duro" continua vindo do PTBR_LOCK do KlingBrowserGenerateJob
     *    (chokepoint global) — nao precisa repetir aqui.
     *
     * 4. Anti-logo saiu daqui: o mesmo job ja prepende ANTI_LOGO. Ficaram só as
     *    proibicoes que o Veo realmente erra (letra na tela, mao deformada).
     */
    public function toKlingPrompt(array $script): string
    {
        foreach (["style","scene","subject","performance","audio_diegetic","negative","framing","lighting_color","product_desc"] as $__f) {
            if (! empty($script[$__f]) && is_string($script[$__f])) {
                $script[$__f] = self::neutralizeBrands($script[$__f]);
            }
        }
        if ($this->normalizeLang($script['_lang'] ?? 'pt-BR') === 'es-419') {
            return $this->toKlingPromptEs($script);
        }

        $fala = trim((string) ($script['audio_diegetic'] ?? ''));
        $perf = mb_strtolower((string) ($script['performance'] ?? ''));
        $emOff = str_contains($perf, 'sem apresentador') || str_contains($perf, 'voz em off')
              || str_contains($perf, 'ninguem aparece') || str_contains($perf, 'ninguém aparece');
        $quem  = $emOff ? 'A narracao em off, voz feminina brasileira, diz' : 'A apresentadora brasileira diz';

        $dur    = $this->duracaoDoScript($script);
        $fimVoz = max(2.0, round($dur - 1, 1));

        // SEL-SEM-SOM-PROMPT (14/08, Ruan: "no meu ad selecionei SEM AUDIO e saiu a
        // voz"). Estava certo e o furo era meu: eu tinha colocado o pedido de silencio
        // so no BRIEF do roteiro, e o prompt que o motor le de verdade e montado AQUI —
        // com o bloco de FALA fixo. Ou seja, marcar "sem som" nao silenciava nada.
        // Agora, quando o roteiro vem marcado como mudo, o bloco de fala e trocado por
        // uma proibicao explicita: ninguem fala E ninguem mexe a boca (so "sem
        // narracao" faz o motor entregar gente falando mudo, que fica pior).
        if (! empty($script['sem_som'])) {
            return self::neutralizeBrands(implode("\n\n", array_filter([
                'VIDEO MUDO: NINGUEM FALA e NINGUEM MEXE A BOCA em nenhum momento — boca fechada, '
                . 'sem mimica de fala, sem narracao, sem voz em off, sem legenda. So imagem e som ambiente.',
                (! empty($script['movimentos'])
                    ? 'O QUE A PESSOA FAZ (pedido do cliente): ' . mb_substr((string) $script['movimentos'], 0, 300)
                    : null),
                (! empty($script['product_desc'])
                    ? 'PRODUTO: ' . mb_substr(trim((string) $script['product_desc']), 0, 700)
                      . ' Este e o produto do anuncio: IDENTICO a foto anexada em cor, '
                      . 'forma, marca e pecas, em todos os planos.'
                    : null),
                $this->blocoPlanos($script, $this->duracaoDoScript($script), false),
                'CONTINUIDADE: a MESMA pessoa em todos os planos (mesmo rosto, cabelo e roupa), o MESMO cenario '
                . 'e a MESMA luz do primeiro ao ultimo frame.',
                // SEL-PALAVRA-DO-CLIENTE (16/08): cenario que o cliente SUBIU, dito literal.
                (! empty($script['cenario_foto'])
                ? 'CENARIO DO CLIENTE: o fundo da cena tem que ser o AMBIENTE da foto de '
                  . 'referencia anexada (a ultima imagem). Use esse lugar, com os moveis, as '
                  . 'cores e a luz que aparecem nele. NAO invente outro ambiente, NAO troque o '
                  . 'fundo por estudio nem por cenario generico. O produto continua sendo o '
                  . 'protagonista, dentro DESSE cenario.'
                : null),
                (! empty($script['prompt_colado'])
                    ? 'PEDIDO ESCRITO PELO PROPRIO CLIENTE — RESPEITAR AO PE DA LETRA: '
                      . mb_substr((string) $script['prompt_colado'], 0, 1200)
                    : null),
            ])));
        }

        return self::neutralizeBrands(implode("\n\n", array_filter([
            // 1) FALA — o que sai em audio. Primeiro porque e o que o cliente pediu.
            'FALA (o UNICO audio do video). IDIOMA: portugues do Brasil, sotaque de Sao Paulo/Rio. '
            . $quem . ' UMA UNICA VEZ, exatamente: ' . $fala . ' Comeca a falar no primeiro frame e a voz NAO '
            . 'PARA nos cortes: a frase atravessa a troca de plano, sem pausa e sem trecho mudo. A frase inteira '
            . 'e dita ao longo dos planos e termina por volta de ' . $fimVoz . 's. Nunca repete, nunca inventa '
            . 'outra frase, nunca narra direcao de cena. '
            // SEL-RITMO-TIKTOK (14/08): contar palavras nao basta — o Veo decide o
            // ritmo dele e, sem direcao, entrega locucao de documentario. Aqui o
            // ritmo vai DITO, no vocabulario que o modelo entende.
            . 'RITMO: fala RAPIDA e energica de anuncio de rede social, empolgada, como quem '
            . 'esta animado pra contar uma novidade. NUNCA arrasta as palavras, NUNCA faz '
            . 'pausa dramatica, NUNCA tom de narracao de documentario. Cadencia de conversa '
            . 'acelerada entre amigos.',

            // 2) PRODUTO — defesa contra o modelo inventar outro item.
            (! empty($script['product_desc'])
                ? 'PRODUTO: ' . mb_substr(trim((string) $script['product_desc']), 0, 700)
                  . ' Este e o produto do anuncio: IDENTICO a foto anexada em cor, '
                  . 'forma, marca e pecas, em todos os planos.'
                : null),

            // 2b) O QUE O CLIENTE PEDIU — SEL-PALAVRA-DO-CLIENTE (16/08).
            //
            // Este bloco NAO EXISTIA aqui. O video COM SOM (a maioria) montava o prompt
            // sem uma linha sequer do que o cliente escreveu ou do cenario que ele subiu:
            // essas coisas iam so no `brief`, que e ENTRADA do LLM, e o que chega no motor
            // e a REESCRITA do LLM. Por isso o cliente reclamava que "nao respeita o meu
            // prompt" e "nao usa o cenario que eu subi" — ele estava certo, literalmente
            // nada do texto dele chegava aqui.
            (! empty($script['cenario_foto'])
                ? 'CENARIO DO CLIENTE: o fundo da cena tem que ser o AMBIENTE da foto de '
                  . 'referencia anexada (a ultima imagem). Use esse lugar, com os moveis, as '
                  . 'cores e a luz que aparecem nele. NAO invente outro ambiente, NAO troque o '
                  . 'fundo por estudio nem por cenario generico. O produto continua sendo o '
                  . 'protagonista, dentro DESSE cenario.'
                : null),
            (! empty($script['prompt_colado'])
                ? 'PEDIDO ESCRITO PELO PROPRIO CLIENTE — RESPEITAR AO PE DA LETRA (tem '
                  . 'prioridade sobre qualquer sugestao acima): '
                  . mb_substr((string) $script['prompt_colado'], 0, 1200)
                : null),

            // 3) PLANOS — a prioridade do Ruan. Sintaxe oficial de timestamp do Veo 3.1.
            $this->blocoPlanos($script, $dur, $emOff),

            // 3b) QUEM APARECE E COMO A CAMERA VE — SEL-QUEM-APARECE-VAI-INTEIRO (16/08).
            //
            // Este bloco NAO EXISTIA. `subject` so entrava dentro da CONTINUIDADE, cortado
            // em 120 chars, e `framing` nao entrava em lugar NENHUM deste montador (o
            // bloco "ENQUADRAMENTO:" mora noutro builder, que nao e o que roda).
            // Resultado medido no pedido #1313, gerado de verdade: o POV novo (primeira
            // pessoa com o corpo no quadro, as duas maos, o arco chega->abre->revela, a mao
            // saindo no fim) NAO chegava no motor. Mesma doenca do PRODUTO cortado em 150.
            (! empty($script['subject'])
                ? 'QUEM APARECE: ' . mb_substr(trim((string) $script['subject']), 0, 900)
                : null),
            (! empty($script['framing'])
                ? 'ENQUADRAMENTO: ' . mb_substr(trim((string) $script['framing']), 0, 700)
                : null),

            // 4) CONTINUIDADE — sem isto o modelo troca de pessoa entre os cortes.
            // (nao repete mais o `subject` cortado: ele ja foi inteiro no bloco acima)
            'CONTINUIDADE: a MESMA pessoa em todos os planos (mesmo rosto, cabelo, roupa e voz), o MESMO cenario '
            . 'e a MESMA luz do primeiro ao ultimo frame. Cenario: '
            . mb_substr(trim((string) ($script['scene'] ?? 'ambiente residencial brasileiro, luz natural')), 0, 300) . '.',

            // 5) IMAGEM LIMPA — so os defeitos que o Veo realmente comete.
            'IMAGEM LIMPA: tela sem nenhuma letra, legenda ou marca dagua. Maos inteiras e bem formadas, cinco '
            . 'dedos. Olhos abertos desde o primeiro frame. Nunca dar close em rotulo ou embalagem escrita: '
            . 'mostrar o produto em uso.',

            // 6) ESTILO/REALISMO — enxuto, sem repetir o que ja foi dito.
            'ESTILO: ' . mb_substr(trim((string) ($script['style'] ?? 'UGC brasileiro filmado no celular')), 0, 90)
            . '. Vertical 9:16, pele com poros e textura, sem cara de IA, sem filtro de beleza.',

            'EVITAR: ' . mb_substr(trim((string) ($script['negative'] ?? '')), 0, 110)
            . ', legenda, texto na tela, mao deformada, dedo extra.',
        ])));
    }

    /** Duracao alvo do roteiro (segundos), a partir da timeline. */
    private function duracaoDoScript(array $script): float
    {
        $fim = 0.0;
        foreach ($script['action_timeline'] ?? [] as $s) {
            $fim = max($fim, (float) ($s['end_s'] ?? 0));
        }
        return $fim > 1 ? $fim : 8.0;
    }

    /**
     * SEL-PROMPT-ORIGEM — bloco de PLANOS na sintaxe oficial de timestamp do Veo 3.1.
     *
     * Medido: 2 planos = 0,62 corte/video; 3 planos = 0,75; 4 planos = 1,12;
     * 5 planos = 0,88 (a partir de 5 ele volta a ignorar). Por isso o alvo e 4.
     * Quando a timeline do roteirista tem 3+ tomadas, elas mandam; quando tem 2
     * (caso de 8-10s, que e a maioria), a gente ABRE em 4 janelas com enquadramentos
     * diferentes, que e o que faz o modelo cortar de verdade.
     */
    // SEL-PLANOS-MUDO (14/08): mesmo com o pedido de silencio no topo, a descricao
    // dos planos continuava dizendo "a apresentadora FALANDO pra camera" — e o motor
    // obedece o que esta escrito no plano. Ou seja: eu proibia falar numa linha e
    // mandava falar na seguinte. Achado lendo o prompt do proprio video do Ruan.
    private function blocoPlanos(array $script, float $dur, bool $emOff): ?string
    {
        $tl = array_values(array_filter((array) ($script['action_timeline'] ?? [])));
        $mmss = function (float $s): string {
            $s = max(0, (int) round($s));
            return sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
        };

        $planos = [];
        if (count($tl) >= 3) {
            // SEL-ROTEIRO-CABE-NO-VIDEO (15/08, Ruan: "formato POV esta um lixo").
            //
            // MEDIDO em 141 videos entregues em 24h: 40 (28%) tinham roteiro MAIOR que
            // os 8s que o motor rende — pior caso, roteiro ate 00:12 num arquivo de
            // 8,000s (ffprobe). No pedido 1226 o ultimo plano ("camera descendo ate o
            // close final") simplesmente nunca acontecia, e o video gastava o primeiro
            // quarto num banheiro vazio, sem produto. O cliente recebe a abertura e
            // perde o fecho — que e a parte que vende.
            //
            // Os tempos que o LLM escreve na action_timeline eram usados como vieram; o
            // min(8, duracao) do controller so governa o caminho deterministico. Aqui a
            // timeline e REESCALADA (nao cortada) pro tempo real: a estrutura que o
            // roteirista pensou continua inteira, so comprimida pro que cabe.
            $fimRoteiro = 0.0;
            foreach ($tl as $s) {
                $fimRoteiro = max($fimRoteiro, (float) ($s['end_s'] ?? 0));
            }
            $fator = ($fimRoteiro > 0 && $dur > 0 && $fimRoteiro > $dur) ? ($dur / $fimRoteiro) : 1.0;
            if ($fator < 1.0) {
                \Illuminate\Support\Facades\Log::error('[SEL-ROTEIRO-CABE-NO-VIDEO] roteiro maior que o video — reescalando', [
                    'roteiro_ia_ate' => $fimRoteiro, 'video_real' => $dur, 'fator' => round($fator, 3),
                ]);
            }

            foreach (array_slice($tl, 0, 5) as $s) {
                $d = $this->descricaoSemFala((string) ($s['description'] ?? ''));
                if ($d === '') { continue; }
                $ini = (float) ($s['start_s'] ?? 0) * $fator;
                $fim = (float) ($s['end_s'] ?? 0) * $fator;
                if ($ini >= $dur) { continue; }          // plano que comeca depois do fim nao existe
                $fim = min($fim, (float) $dur);
                $planos[] = '[' . $mmss($ini) . '-' . $mmss($fim) . '] ' . $d;
            }
        } else {
            // 2 tomadas (ou nenhuma) -> abre em 4 janelas com enquadramentos diferentes.
            $a = $this->descricaoSemFala((string) ($tl[0]['description'] ?? ''));
            $b = $this->descricaoSemFala((string) ($tl[1]['description'] ?? $a));

            // SEL-MUDO-EXIBE-O-LOOK: quem o cliente marcou na tela manda nas cenas do meio.
            // Antes os `movimentos` viravam uma frase solta ANTES de um plano fixo que os
            // contradizia — por isso o giro que ele pediu nunca acontecia.
            $sujeitoMudo   = 'a pessoa';
            $movimentoMudo = '';
            if (! empty($script['sem_som']) && ! empty($script['movimentos'])) {
                $movs = is_array($script['movimentos'])
                    ? $script['movimentos']
                    : array_filter(array_map('trim', explode(',', (string) $script['movimentos'])));
                $movimentoMudo = mb_strtolower(trim((string) ($movs[0] ?? '')));
            }
            $quadros = $emOff
                ? [
                    'plano medio do produto no cenario, camera de mao empurrando pra frente. ' . $a,
                    'close no detalhe do produto, camera de mao.',
                    'close nas maos usando o produto, camera baixa e proxima. ' . $b,
                    'plano detalhe do produto em uso, camera descendo ate o close final.',
                ]
                : (! empty($script['sem_som'])
                    // SEL-MUDO-EXIBE-O-LOOK (15/08, aula do Ruan): no mudo as 4 cenas sao de
                    // EXIBICAO, nao de silencio. Antes as duas ultimas eram FIXAS ('close nas
                    // maos usando o produto' + 'close final no produto') e ignoravam o que o
                    // cliente marcou — medido no pipeline 1081: ele pediu "gira o corpo
                    // mostrando o caimento", nao teve giro, e o video TERMINOU em close no
                    // guidao da bicicleta. Pessoa parada e muda, terminando no objeto: o
                    // oposto do que vende roupa. O que vende e o CAIMENTO em movimento, e o
                    // ultimo frame tem que ser a PESSOA com o look.
                    // SEL-MUDO-SEGUE-O-PRODUTO (15/08): o roteiro do mudo acompanha o
                    // que esta sendo vendido. Antes ele falava de ROUPA sempre — e o
                    // pedido 1192 (um RELOGIO) recebeu "gira e a roupa acompanha o
                    // movimento, mostrando o caimento". Direcao que se contradiz;
                    // falhou 7x seguidas enquanto os 3 pedidos anteriores do mesmo
                    // cliente entregaram normal. O caminho da roupa segue identico.
                    ? $this->cenasDoMudo(
                        $this->tipoDoProduto((string) ($script['product_desc'] ?? '')),
                        $sujeitoMudo,
                        $movimentoMudo,
                        $a,
                        $b
                    )
                    : [
                        'plano medio da apresentadora falando pra camera, camera de mao empurrando pra frente. ' . $a,
                        'close no rosto dela reagindo, camera de mao.',
                        'close nas maos dela usando o produto, camera baixa e proxima. ' . $b,
                        'plano detalhe do produto em uso, camera descendo ate o close final.',
                    ]);
            $passo = $dur / 4;
            foreach ($quadros as $i => $q) {
                $planos[] = '[' . $mmss($i * $passo) . '-' . $mmss(($i + 1) * $passo) . '] ' . trim($q);
            }
        }
        if (! $planos) { return null; }

        return "PLANOS (o video TEM que trocar de plano nestes tempos; cada troca e um CORTE SECO instantaneo, "
            . "nunca dissolve, nunca fade):\n" . implode("\n", $planos);
    }

    /**
     * SEL-MUDO-SEGUE-O-PRODUTO (15/08) — que tipo de produto e este, pro roteiro mudo.
     *
     * 'vestivel'  = roupa/calcado: a venda e o CAIMENTO em movimento (regra do Ruan).
     * 'acessorio' = usado no corpo mas sem caimento (relogio, colar, oculos, bolsa).
     * 'objeto'    = nao se veste (bicicleta, eletronico, utensilio).
     *
     * Na duvida cai em 'objeto', que e o roteiro mais neutro: a pessoa apresenta e usa
     * o produto. Errar pra neutro atrasa a venda; errar pra roupa gera contradicao
     * (foi o que travou o 1192 sete vezes).
     */
    private function tipoDoProduto(string $desc): string
    {
        $d = mb_strtolower(trim($desc));
        if ($d === '') { return 'objeto'; }

        $vestivel = ['roupa', 'vestido', 'camisa', 'camiseta', 'blusa', 'calca', 'calça', 'short',
            'saia', 'jaqueta', 'casaco', 'biquini', 'biquíni', 'lingerie', 'conjunto', 'legging',
            'body', 'macacao', 'macacão', 'pijama', 'moletom', 'cropped', 'sutia', 'sutiã',
            'tenis', 'tênis', 'sapato', 'sandalia', 'sandália', 'bota', 'chinelo', 'meia',
            'regata', 'bermuda', 'agasalho', 'blazer', 'colete', 'terno', 'maio', 'maiô',
            'cueca', 'calcinha', 'salto', 'roupao', 'roupão', 'cinta', 'camisola', 'fantasia',
            'uniforme', 'jeans', 'peca de roupa', 'look'];
        foreach ($vestivel as $t) {
            if (mb_strpos($d, $t) !== false) { return 'vestivel'; }
        }

        $acessorio = ['relogio', 'relógio', 'colar', 'brinco', 'pulseira', 'anel', 'oculos',
            'óculos', 'bolsa', 'mochila', 'bone', 'boné', 'chapeu', 'chapéu', 'cinto',
            'carteira', 'gargantilha', 'tiara', 'corrente', 'piercing', 'peruca', 'lenco',
            'lenço', 'echarpe', 'pochete', 'joia', 'jóia', 'bracelete'];
        foreach ($acessorio as $t) {
            if (mb_strpos($d, $t) !== false) { return 'acessorio'; }
        }

        return 'objeto';
    }

    /**
     * SEL-MUDO-SEGUE-O-PRODUTO (15/08) — as 4 cenas do video mudo, por tipo de produto.
     *
     * Em todos os tres o ultimo frame e a PESSOA, nunca um close solto do objeto: foi a
     * correcao que o Ruan pediu depois do pipeline 1081, que terminava no guidao da
     * bicicleta. O que muda de um pro outro e o QUE se exibe no meio.
     */
    private function cenasDoMudo(string $tipo, string $sujeito, string $movimento, string $a, string $b): array
    {
        if ($tipo === 'vestivel') {
            // identico ao que ja rodava: e a regra do Ruan e entrega (7 de 8 pedidos)
            return [
                'corpo inteiro, da cabeca aos pes, ' . $sujeito . ' andando em direcao a camera com atitude, camera de mao na altura do peito recuando devagar. ' . $a,
                ($movimento !== ''
                    ? 'CORTE SECO: corpo inteiro, ' . $movimento . ', a roupa acompanhando o movimento.'
                    : 'CORTE SECO: corpo inteiro, ' . $sujeito . ' GIRA e a roupa acompanha o movimento, mostrando o caimento de lado e por tras.'),
                'CORTE SECO: plano medio da cintura pra cima, ' . $sujeito . ' ajeita a peca no corpo e marca o ritmo com o corpo, camera acompanhando. ' . $b,
                'CORTE SECO: volta pro CORPO INTEIRO, pose final firme olhando pra camera — o ultimo frame e a PESSOA com o look, NUNCA um close do produto.',
            ];
        }

        if ($tipo === 'acessorio') {
            return [
                'plano medio da cintura pra cima, ' . $sujeito . ' entrando em quadro com atitude e o produto bem visivel no corpo, camera de mao recuando devagar. ' . $a,
                ($movimento !== ''
                    ? 'CORTE SECO: ' . $movimento . ', mantendo o produto sempre em quadro.'
                    : 'CORTE SECO: close no produto no corpo, ' . $sujeito . ' virando devagar pra luz correr por cima dele e mostrar o acabamento.'),
                'CORTE SECO: plano medio, ' . $sujeito . ' ajusta o produto e marca o ritmo com o corpo, camera acompanhando. ' . $b,
                'CORTE SECO: volta pro plano medio, pose final firme olhando pra camera — o ultimo frame e a PESSOA usando o produto, NUNCA um close solto do objeto.',
            ];
        }

        return [
            'plano medio, ' . $sujeito . ' entrando em quadro com o produto nas maos e atitude, camera de mao recuando devagar. ' . $a,
            ($movimento !== ''
                ? 'CORTE SECO: ' . $movimento . ', mantendo o produto sempre em quadro.'
                : 'CORTE SECO: close nas maos girando o produto devagar, mostrando os lados e o acabamento.'),
            'CORTE SECO: plano medio, ' . $sujeito . ' usando o produto de verdade, camera acompanhando o movimento. ' . $b,
            'CORTE SECO: volta pro plano medio, ' . $sujeito . ' com o produto em maos olhando pra camera — o ultimo frame e a PESSOA com o produto, NUNCA um close solto do objeto.',
        ];
    }

    /** Tira a fala entre aspas da descricao do plano (senao o modelo recita a direcao). */
    private function descricaoSemFala(string $d): string
    {
        $d = preg_replace('/["\x{201C}\x{201D}\x{2018}\x{2019}](.+?)["\x{201C}\x{201D}\x{2018}\x{2019}]/u', '', $d);
        $d = preg_replace('/\b(e )?(diz|fala|narra[çc][ãa]o|narra)\s*:?\s*/iu', '', (string) $d);
        $d = preg_replace('/^\s*\w+\s*:\s*/u', '', (string) $d);
        return trim(preg_replace('/\s{2,}/u', ' ', (string) $d), " \t\n\r\0\x0B-–—:,");
    }

    /**
     * SEL-413 — prompt Kling em espanhol (audio nativo es-419).
     *
     * A diferenca que importa em relacao ao render reprovado: o bloco de CONTINUIDADE.
     * Sem ele o Kling trata cada toma como um segmento independente e faz uma pausa na
     * virada — foi assim que apareceram os buracos de 1,25s e 0,50s no SEL413_es.mp4.
     */
    private function toKlingPromptEs(array $script): string
    {
        // SEL-antilogo-studio (09/08, Ruan): tira nome de marca (TikTok etc.) dos campos
        // vindos do GPT antes de montar o prompt -> o modelo NAO desenha a logo (#517).
        foreach (["style","scene","subject","performance","audio_diegetic","negative","framing","lighting_color","product_desc"] as $__f) {
            if (! empty($script[$__f]) && is_string($script[$__f])) {
                $script[$__f] = self::neutralizeBrands($script[$__f]);
            }
        }
        $timeline = '';
        foreach ($script['action_timeline'] ?? [] as $s) {
            $timeline .= "[{$s['start_s']}s - {$s['end_s']}s] [{$s['shot']}] {$s['description']}\n";
        }

        $fim = 0.0;
        foreach ($script['action_timeline'] ?? [] as $s) {
            $fim = max($fim, (float) ($s['end_s'] ?? 0));
        }
        $fimVoz = $fim > 1 ? round($fim - 1, 1) : 11;

        return self::neutralizeBrands(implode("\n\n", array_filter([
            'IDIOMA DE LA NARRACION: ESPANOL LATINOAMERICANO NEUTRO (es-419). La presentadora '
            . 'habla en espanol. NO hables ingles. NO hables portugues. NO hables espanol de '
            . 'Espana (nada de "vosotros" ni ceceo). Toda la voz del video es en espanol '
            . 'latinoamericano.',

            'ESTILO: ' . ($script['style'] ?? ''),
            'ESCENARIO: ' . ($script['scene'] ?? ''),
            // SEL-490 Layer C: identidad del producto en el prompt (defensa vs bug del adjunto).
            (! empty($script['product_desc']) ? 'PRODUCTO (obligatorio, mostrar EXACTAMENTE este item): ' . $script['product_desc'] : null),
            'PRESENTADORA: ' . ($script['subject'] ?? ''),
            "GUION POR TIEMPO:\n" . trim($timeline),
            'CAMARA: ' . ($script['camera'] ?? ''),
            (function () use ($script) {
                $t = $script['transitions'] ?? [];
                if (! is_array($t) || ! $t) { return null; }
                $l = [];
                foreach ($t as $x) {
                    $mv = is_array($x) ? ($x['movimiento'] ?? $x['movimento'] ?? '') : (string) $x;
                    if (trim((string) $mv) !== '') { $l[] = '- ' . trim((string) $mv); }
                }

                return $l ? "TRANSICIONES (cada cambio de plano es un CORTE SECO instantaneo; la camara se mueve DENTRO de cada plano):\n"
                    . implode("\n", $l) : null;
            })(),
            'ENCUADRE: ' . ($script['framing'] ?? ''),
            'ACTUACION: ' . ($script['performance'] ?? ''),
            'ILUMINACION/COLOR: ' . ($script['lighting_color'] ?? ''),

            'AUDIO (voz diegetica — SIEMPRE en espanol): "'
            . ($script['audio_diegetic'] ?? '') . '"',

            // ESTE bloco e a correcao do SEL-413.
            'CONTINUIDAD DE LA VOZ (CRITICO): la locucion es UNA SOLA toma de voz continua de '
            . 'principio a fin. La presentadora NO deja de hablar en ningun momento entre 0.3s y '
            . $fimVoz . 's. Los cambios de camara NO interrumpen la voz: la frase sigue sonando '
            . 'por encima del corte. PROHIBIDO silencio en medio del video. PROHIBIDA pausa en la '
            . 'transicion entre tomas. PROHIBIDO que la presentadora se quede quieta y callada '
            . 'esperando el siguiente plano. Si la voz se detiene antes de ' . $fimVoz . 's, el '
            . 'video esta mal.',

            'AUDIO TIMING: la voz empieza en 0.3s y TERMINA en ' . $fimVoz . 's, sin ninguna pausa '
            . 'intermedia. Nunca cortar la frase por la mitad. Solo el ultimo segundo queda sin voz.',

            'EVITAR: ' . ($script['negative'] ?? '') . ', sin silencio intermedio, sin pausas entre '
            . 'tomas, sin labios quietos mientras deberia hablar',

            'ACCENT/DIALECT: Neutral Latin American Spanish (es-419) — Mexico City / Bogota neutral '
            . 'accent. NOT Castilian Spanish from Spain. NOT Portuguese. NOT English.',

            'IDIOMA: espanol latinoamericano neutro (es-419). Voz femenina joven, natural, ritmo de '
            . 'video vertical de redes sociales. PROHIBIDO ingles, portugues y espanol de Espana. PROHIBIDA lectura robotica.',
            self::ANTI_LOGO_STUDIO,
        ])));
    }

    /**
     * SEL-452: chatJson via AiEnginePool (Gemini/DICloak/OpenAI fallback).
     */
    private function poolChatJson(array $messages, int $maxTokens = 2000, float $temperature = 0.7): array
    {
        $messages_ = $messages;
        $lk = array_key_last($messages_);
        if ($lk !== null && isset($messages_[$lk]["content"])) {
            $messages_[$lk]["content"] .= "\n\nIMPORTANTE: responda APENAS com JSON valido puro, sem markdown, sem crase-crase-crase-json, sem prefixo/sufixo. Comece com { e termine com }.";
        }
        $text = app(\App\Services\Ai\AiEnginePool::class)->for("llm")->chat($messages_, $temperature, $maxTokens);
        $text = trim($text);
        if (str_starts_with($text, chr(96).chr(96).chr(96))) {
            $text = preg_replace("/^(?:`{3})(?:json)?\s*|\s*(?:`{3})$/m", "", $text);
            $text = trim($text);
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded) && !empty($decoded)) return $decoded;
        if (preg_match("/\{.*\}/s", $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded) && !empty($decoded)) return $decoded;
        }
        throw new \RuntimeException("pool_chat_json_invalid: " . mb_substr($text, 0, 200));
    }
}
