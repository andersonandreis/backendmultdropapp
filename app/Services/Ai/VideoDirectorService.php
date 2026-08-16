<?php

namespace App\Services\Ai;

use App\Services\Ai\OpenAiService;
use Illuminate\Support\Facades\Log;

/**
 * SEL-333 Fase 1 -- Video Director
 *
 * Analisa produto via Vision GPT-4o-mini e escolhe pipeline de video ideal.
 * Orquestra passos em payloads que o AiVideoPipelineJob executa.
 *
 * Fase 1: 3 pipelines basicos
 *   - roupa         -> showcase_silencioso  (Provador Virtual nao integrado na F1)
 *   - calcado/joia  -> pov
 *   - eletronico    -> video_perfeito
 *   - outros        -> showcase_silencioso  (default seguro)
 *
 * DIVERGENCIA F1: Provador Virtual (OOTDiffusion/IDM-VTON) nao esta integrado.
 * Pipeline de roupa usa showcase_silencioso como Alt 1 da tabela do briefing.
 * Quando Provador for integrado, alterar PIPELINE_MAP roupa_* para
 * "provador_showcase" e implementar buildProvadorPayload().
 *
 * Fase 2 (backlog): auto-review frames via Vision
 * Fase 3 (backlog): aprendizado por video_generation_events
 */
class VideoDirectorService
{
    /** Taxonomia de 15 categorias + outros */
    public const CATEGORIES = [
        'roupa_feminina', 'roupa_masculina', 'roupa_unissex',
        'calcado', 'acessorio_moda', 'joia_relogio',
        'beleza_maquiagem', 'perfume_frag', 'skincare',
        'eletronico_gadget', 'eletroportatil', 'informatica',
        'casa_decoracao', 'cozinha_utensilio', 'pet', 'infantil', 'outros',
    ];

    /**
     * Mapa categoria -> pipeline Fase 1
     * Pipelines disponiveis: showcase_silencioso | pov | video_perfeito
     */
    private const PIPELINE_MAP = [
        'roupa_feminina'    => ['pipeline' => 'showcase_silencioso', 'reason' => 'Showcase visual elegante para roupas femininas — avatar apresenta a peca'],
        'roupa_masculina'   => ['pipeline' => 'showcase_silencioso', 'reason' => 'Showcase para roupas masculinas — look limpo e moderno'],
        'roupa_unissex'     => ['pipeline' => 'showcase_silencioso', 'reason' => 'Showcase para roupa unissex — versatil e clean'],
        'calcado'           => ['pipeline' => 'pov',                 'reason' => 'POV mao/pe e o formato campea para calcados no TikTok Shop'],
        'acessorio_moda'    => ['pipeline' => 'showcase_silencioso', 'reason' => 'Showcase close-up destaca detalhes e acabamento de acessorios'],
        'joia_relogio'      => ['pipeline' => 'pov',                 'reason' => 'POV macro close-up e o formato de luxo para joias e relogios'],
        'beleza_maquiagem'  => ['pipeline' => 'pov',                 'reason' => 'POV mao aplicando e o mais viral para beleza/maquiagem'],
        'perfume_frag'      => ['pipeline' => 'pov',                 'reason' => 'POV reveal do frasco converte muito para perfumes'],
        'skincare'          => ['pipeline' => 'pov',                 'reason' => 'POV demonstrando aplicacao e mais autentico para skincare'],
        'eletronico_gadget' => ['pipeline' => 'video_perfeito',      'reason' => 'Avatar fazendo review e o formato que mais converte para eletronicos'],
        'eletroportatil'    => ['pipeline' => 'video_perfeito',      'reason' => 'Avatar demonstrando uso e ideal para eletrodomesticos'],
        'informatica'       => ['pipeline' => 'video_perfeito',      'reason' => 'Review com avatar e ideal para informatica e gadgets'],
        'casa_decoracao'    => ['pipeline' => 'showcase_silencioso', 'reason' => 'Showcase estetico para decoracao — ambiente aspiracional'],
        'cozinha_utensilio' => ['pipeline' => 'pov',                 'reason' => 'POV demo de uso e o mais eficaz para utensilios de cozinha'],
        'pet'               => ['pipeline' => 'showcase_silencioso', 'reason' => 'Showcase ludico para produtos pet'],
        'infantil'          => ['pipeline' => 'showcase_silencioso', 'reason' => 'Showcase colorido e dinamico para produtos infantis'],
        'outros'            => ['pipeline' => 'showcase_silencioso', 'reason' => 'Showcase e o formato mais versatil para categoria geral'],
    ];

    public function __construct(
        private OpenAiService $openai,
    ) {}

    /**
     * Analisa imagem do produto via Vision GPT-4o-mini.
     * Retorna categoria (taxonomia interna) + atributos do produto.
     *
     * @param string      $imageUrl     URL da imagem principal
     * @param string|null $productTitle Titulo do produto (contexto extra)
     * @param string|null $categoryHint Categoria informada pelo cliente (opcional)
     * @return array{category: string, attributes: array, vision_raw: string}
     */
    /**
     * SEL-PRODUTO-DESCRITO-PELA-FOTO (15/08) — descreve o produto OLHANDO a foto.
     *
     * Por que existe: o slot PRODUTO do prompt vinha do titulo do anuncio, que e
     * texto de busca, nao descricao. "Calça Feminina de Alta Marrante Moda Gringa
     * Elegância e Conforto para Todas as Ocasiões Versátil e Premium" nao diz roxa,
     * nem pantalona, nem acetinada — e o modelo entregou uma jeans preta justa.
     *
     * Aqui volta o oposto: SO o que da pra ver. Cor, formato, material, detalhe que
     * identifica. Sem adjetivo de venda, sem categoria, sem opiniao — porque adjetivo
     * de venda foi exatamente o que encheu o prompt de nada.
     *
     * Devolve string vazia quando a visao falha: o chamador cai no titulo de antes,
     * que e o comportamento de hoje. Nunca piora, na pior das hipoteses empata.
     */
    /** true quando a visao respondeu "NAO": a foto nao mostra o produto (resposta conclusiva) */
    private bool $fotoNaoMostraOProduto = false;

    public function descreveAparencia(string $imageUrl, string $tituloDoAnuncio = ''): string
    {
        $imageUrl = trim($imageUrl);
        if ($imageUrl === '') { return ''; }

        // SEL-VISAO-DE-GRACA: uma foto = uma descricao. A chamada leva ~60-90s porque
        // e um navegador de verdade; sem este cache TODO pedido pagaria esse tempo.
        // SEL-VISAO-CONFERE-O-PRODUTO: o titulo entra na chave porque a resposta
        // DEPENDE dele (a pergunta e "o produto do titulo esta nesta foto?").
        $chave = 'aparencia:' . sha1($imageUrl . '|' . mb_strtolower(trim($tituloDoAnuncio)));

        $guardado = \Illuminate\Support\Facades\Cache::get($chave);
        if (is_string($guardado)) { return $guardado; }

        $this->fotoNaoMostraOProduto = false;
        $desc = $this->descreveAparenciaAgora($imageUrl, $tituloDoAnuncio);

        // SEL-VISAO-FALHA-NAO-GRUDA: tropeco tecnico ganha UMA segunda chance na hora.
        // Quem esta esperando e o pedido do cliente — vale os ~40s a mais pra nao
        // mandar pro video um produto que nao e o dele.
        if ($desc === '' && ! $this->fotoNaoMostraOProduto) {
            Log::error('[SEL-VISAO-DE-GRACA] visao tropecou — tentando mais uma vez antes de desistir');
            $desc = $this->descreveAparenciaAgora($imageUrl, $tituloDoAnuncio);
        }

        if ($desc !== '') {
            \Illuminate\Support\Facades\Cache::put($chave, $desc, now()->addDays(7));

            return $desc;
        }

        // "NAO | mecanico na oficina" e resposta CONCLUSIVA: reperguntar da no mesmo.
        // 12h em vez de 7 dias porque o cliente pode trocar a foto do anuncio.
        if ($this->fotoNaoMostraOProduto) {
            \Illuminate\Support\Facades\Cache::put($chave, '', now()->addHours(12));
        }

        // falha tecnica NAO vira cache: o proximo pedido tenta de novo.
        return '';
    }

    /**
     * SEL-VISAO-DE-GRACA (15/08) — olha a foto pelo NAVEGADOR, sem chave e sem custo.
     *
     * Eu ia pedir chave de visao pro Ruan (OpenAI devolve "openai_not_configured" e
     * GEMINI_API_KEY esta vazia). Ele cortou: "voce ja instalou isso com github". E
     * estava certo — o `gemini_content_worker.js` ja conversa com o Gemini pelo
     * navegador usando a sessao Google que a casa mantem viva pro Veo. Faltava mandar
     * IMAGEM, e e isso que o `gemini_vision_worker.js` faz.
     *
     * Roda na mesma conta Ultra do Ruan, que e o que ele confirmou usar.
     */
    private function descreveAparenciaAgora(string $imageUrl, string $tituloDoAnuncio = ''): string
    {
        $tmp = null;

        try {
            // o worker anexa um ARQUIVO local; a foto vive numa URL nossa
            $bytes = @file_get_contents($imageUrl);
            if ($bytes === false || strlen($bytes) < 500) {
                Log::warning('[SEL-VISAO-DE-GRACA] nao baixei a foto', ['url' => mb_substr($imageUrl, 0, 90)]);

                return '';
            }
            $tmp = sys_get_temp_dir() . '/aparencia_' . sha1($imageUrl) . '.jpg';
            file_put_contents($tmp, $bytes);

            // SEL-VISAO-CONFERE-O-PRODUTO: antes de descrever, ela confere se o produto
            // do anuncio esta MESMO na foto. Foi o kit de ferramentas que ensinou.
            $contexto = trim($tituloDoAnuncio) !== ''
                ? "O cliente vende isto (titulo do anuncio): " . mb_substr(trim($tituloDoAnuncio), 0, 140) . "\n"
                : '';

            $pergunta = $contexto
                . "Olhe a foto e responda em UMA linha, exatamente num destes dois formatos:\n"
                . "SIM | descricao do produto em ate 20 palavras (objeto, COR, formato, material, detalhe que identifica)\n"
                . "NAO | o que a foto mostra, em ate 8 palavras\n\n"
                . "Responda SIM somente se o produto acima estiver VISIVEL na foto. "
                . "Se a foto mostrar so pessoa, loja, logo ou outro objeto, responda NAO. "
                . "Sem adjetivo de venda, sem marca inventada, sem preco. Nada alem dessa linha.";

            $workerJs  = env('GEMINI_VISION_WORKER_JS', '/home/api.seller.global/browser-worker/gemini_vision_worker.js');
            $workerDir = env('GEMINI_BROWSER_WORKER_DIR', '/home/api.seller.global/browser-worker');

            $proc = new \Symfony\Component\Process\Process(
                ['xvfb-run', '-a', '--server-args=-screen 0 1440x900x24', 'node', $workerJs],
                $workerDir,
                [
                    'PLAYWRIGHT_BROWSERS_PATH' => env('PLAYWRIGHT_BROWSERS_PATH', '/opt/ms-playwright'),
                    'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                    'GEMINI_SESSION_PATH' => env('GEMINI_BROWSER_SESSION_PATH', '/home/api.seller.global/storage/kling-browser/google-session.json'),
                ]
            );
            $proc->setTimeout(210);
            $proc->setInput(json_encode(['image_path' => $tmp, 'prompt' => $pergunta]));
            $proc->run();

            // autopsia do processo: sem isso, morte antes do node vira 'erro null'
            $saida    = $proc->getExitCode();
            $stderr   = trim((string) $proc->getErrorOutput());
            $stderrFim = $stderr === '' ? '' : mb_substr($stderr, -280);

            // a ultima linha que comeca com "{" e a resposta (o resto e log)
            $linhas = preg_split('/\r?\n/', trim($proc->getOutput()));
            $json = '';
            for ($i = count($linhas) - 1; $i >= 0; $i--) {
                $l = trim($linhas[$i]);
                if ($l !== '' && $l[0] === '{' && substr($l, -1) === '}') { $json = $l; break; }
            }
            $r = $json ? (json_decode($json, true) ?: []) : [];
            $txt = trim((string) ($r['texto'] ?? ''));
            $txt = trim(preg_replace('/\s+/u', ' ', $txt), " \t\n\r\"'.");

            // SEL-VISAO-CONFERE-O-PRODUTO: sem SIM, a descricao NAO entra no prompt.
            // "NAO | mecanico na oficina" significa que a foto nao mostra o que ele
            // vende — e melhor ficar com o titulo do que empurrar produto errado.
            if (preg_match('/^\s*NAO\s*\|/iu', $txt) || preg_match('/^\s*N\x{00C3}O\s*\|/iu', $txt)) {
                $this->fotoNaoMostraOProduto = true;
                Log::error('[SEL-VISAO-CONFERE-O-PRODUTO] a foto NAO mostra o produto — seguindo com o titulo', [
                    'foto_mostra' => mb_substr(preg_replace('/^[^|]*\|\s*/u', '', $txt), 0, 90),
                    'anuncio'     => mb_substr($tituloDoAnuncio, 0, 70),
                ]);

                return '';
            }
            $txt = trim(preg_replace('/^\s*SIM\s*\|\s*/iu', '', $txt));

            // paragrafo inteiro = ignorou a regra; nao uso (e o mesmo criterio do dia:
            // resposta fora do formato nao entra no prompt do cliente)
            if ($txt === '' || mb_strlen($txt) > 180) {
                Log::error('[SEL-VISAO-DE-GRACA] sem descricao utilizavel — caindo no titulo', [
                    'tamanho'  => mb_strlen($txt),
                    'erro'     => $r['error'] ?? null,
                    'saida'    => $saida,
                    'sem_json' => $json === '',
                    'stderr'   => $stderrFim,
                ]);

                return '';
            }

            Log::error('[SEL-VISAO-DE-GRACA] produto descrito pela foto', ['desc' => mb_substr($txt, 0, 120)]);

            return $txt;
        } catch (\Throwable $e) {
            Log::error('[SEL-VISAO-DE-GRACA] visao por navegador falhou', ['err' => mb_substr($e->getMessage(), 0, 160)]);

            return '';
        } finally {
            if ($tmp && is_file($tmp)) { @unlink($tmp); }
        }
    }

    public function analyzeProduct(string $imageUrl, ?string $productTitle = null, ?string $categoryHint = null): array
    {
        $categoriesStr = implode(' | ', self::CATEGORIES);

        $contextLine = '';
        if ($productTitle) {
            $contextLine .= "Titulo do produto: {$productTitle}\n";
        }
        if ($categoryHint) {
            $contextLine .= "Categoria informada pelo cliente: {$categoryHint}\n";
        }

        $question = "Voce e um especialista em e-commerce brasileiro analisando produto para TikTok Shop.\n"
            . $contextLine
            . "Classifique este produto e retorne JSON valido (apenas JSON, sem markdown, sem texto fora do JSON):\n\n"
            . "{\n"
            . "  \"category\": \"<uma categoria exata da lista: {$categoriesStr}>\",\n"
            . "  \"tem_avatar_faz_sentido\": <true ou false — avatar humano melhora a apresentacao?>,\n"
            . "  \"precisa_provador_virtual\": <true ou false — e roupa/acessorio que ficaria melhor vestido?>,\n"
            . "  \"funciona_no_ambiente\": <true ou false — produto se beneficia de contexto de uso real?>,\n"
            . "  \"e_comestivel\": <true ou false>,\n"
            . "  \"e_liquido\": <true ou false>,\n"
            . "  \"confianca\": <numero de 0.0 a 1.0 — quao certo voce esta da classificacao>,\n"
            . "  \"motivo_breve\": \"<1 frase explicando a escolha de categoria>\"\n"
            . "}";

        try {
            $result = $this->openai->analyzeImage($imageUrl, $question, 'gpt-4o-mini');
            $raw    = $result['analysis'] ?? '';

            $json = $this->extractJson($raw);
            $data = json_decode($json, true) ?? [];

            $category = $data['category'] ?? 'outros';
            if (! in_array($category, self::CATEGORIES, true)) {
                Log::warning('[SEL-333] categoria invalida retornada pelo Vision, usando outros', [
                    'raw_category' => $category,
                ]);
                $category = 'outros';
            }

            return [
                'category'   => $category,
                'attributes' => [
                    'tem_avatar_faz_sentido'   => (bool) ($data['tem_avatar_faz_sentido'] ?? false),
                    'precisa_provador_virtual' => (bool) ($data['precisa_provador_virtual'] ?? false),
                    'funciona_no_ambiente'     => (bool) ($data['funciona_no_ambiente'] ?? false),
                    'e_comestivel'             => (bool) ($data['e_comestivel'] ?? false),
                    'e_liquido'                => (bool) ($data['e_liquido'] ?? false),
                    'confianca'                => (float) ($data['confianca'] ?? 0.7),
                    'motivo_breve'             => (string) ($data['motivo_breve'] ?? ''),
                ],
                'vision_raw' => $raw,
            ];
        } catch (\Throwable $e) {
            Log::error('[SEL-333] falha Vision analyzeProduct', [
                'image_url' => substr($imageUrl, 0, 100),
                'error'     => $e->getMessage(),
            ]);
            // Fallback seguro: showcase e o formato mais generico
            return [
                'category'   => 'outros',
                'attributes' => [
                    'tem_avatar_faz_sentido'   => false,
                    'precisa_provador_virtual' => false,
                    'funciona_no_ambiente'     => false,
                    'e_comestivel'             => false,
                    'e_liquido'                => false,
                    'confianca'                => 0.0,
                    'motivo_breve'             => 'Fallback por erro no Vision',
                ],
                'vision_raw' => '',
            ];
        }
    }

    /**
     * SEL-POV — Detecta se a foto principal do produto JA mostra uma mao humana
     * segurando/tocando o produto (comum em fotos de catalogo tipo "unboxing" ou
     * anuncio). Usado pelo modo POV pra decidir o prompt do primeiro shot:
     *   mao_presente=true  -> instrui o Veo a CONTINUAR a mesma mao da foto
     *   mao_presente=false -> instrui o Veo a INJETAR uma mao realista entrando
     *                         em quadro (a foto so tem o produto sozinho)
     *
     * Vision GPT-4o-mini, mesma chamada barata do analyzeProduct. Fallback seguro
     * em caso de erro: assume mao_presente=false (o prompt de injecao de mao e o
     * caminho mais generico — funciona tanto se a foto ja tiver mao quanto se nao
     * tiver, so custa uma instrucao a mais que o Veo pode ignorar).
     *
     * @param string $imageUrl URL da imagem principal do produto (a MESMA que o
     *                         render do POV efetivamente usa — o pipeline hoje so
     *                         envia esta foto pro Veo, `image_refs` nao chega la).
     * @return array{mao_presente: bool, posicao_mao: string, confianca: float}
     */
    public function detectHand(string $imageUrl): array
    {
        $question = "Voce esta analisando uma foto de produto de e-commerce para decidir "
            . "como gerar um video POV (apenas mao, sem rosto).\n"
            . "Responda APENAS com JSON valido (sem markdown, sem texto fora do JSON):\n\n"
            . "{\n"
            . "  \"mao_presente\": <true ou false — a foto JA mostra uma MAO ou DEDOS humanos "
            . "segurando, tocando ou apontando para o produto?>,\n"
            . "  \"posicao_mao\": \"<se mao_presente=true, descreva em poucas palavras onde/como "
            . "ela segura (ex: 'segurando pela lateral direita'); se false, deixe vazio>\",\n"
            . "  \"confianca\": <numero de 0.0 a 1.0>\n"
            . "}";

        try {
            $result = $this->openai->analyzeImage($imageUrl, $question, 'gpt-4o-mini');
            $raw    = $result['analysis'] ?? '';

            $json = $this->extractJson($raw);
            $data = json_decode($json, true) ?? [];

            return [
                'mao_presente' => (bool) ($data['mao_presente'] ?? false),
                'posicao_mao'  => (string) ($data['posicao_mao'] ?? ''),
                'confianca'    => (float) ($data['confianca'] ?? 0.6),
            ];
        } catch (\Throwable $e) {
            Log::warning('[SEL-POV] falha Vision detectHand, assumindo sem mao (Veo injeta mao)', [
                'image_url' => substr($imageUrl, 0, 100),
                'error'     => $e->getMessage(),
            ]);
            return [
                'mao_presente' => false,
                'posicao_mao'  => '',
                'confianca'    => 0.0,
            ];
        }
    }

    /**
     * Escolhe o pipeline baseado na categoria analisada.
     *
     * @param string $category Categoria retornada pelo analyzeProduct
     * @return array{pipeline: string, reason: string}
     */
    public function choosePipeline(string $category): array
    {
        return self::PIPELINE_MAP[$category] ?? self::PIPELINE_MAP['outros'];
    }

    /**
     * Monta payloads completos para o AiVideoPipelineJob executar.
     * A chave 'director' dentro dos payloads carrega o contexto da analise.
     *
     * @param string $pipeline     Nome do pipeline (showcase_silencioso|pov|video_perfeito)
     * @param array  $input        Dados do request (image_main, image_refs, etc.)
     * @param array  $analysis     Resultado do analyzeProduct
     * @param array  $pipelineInfo Resultado do choosePipeline (reason etc.)
     * @param array  $billing      Billing meta do VideoBillingGate
     * @return array Payloads completos para o Job
     */
    public function buildPipelinePayloads(
        string $pipeline,
        array  $input,
        array  $analysis,
        array  $pipelineInfo,
        array  $billing
    ): array {
        $base = [
            'director' => [
                'pipeline_chosen' => $pipeline,
                'category'        => $analysis['category'],
                'reason'          => $pipelineInfo['reason'] ?? '',
                'attributes'      => $analysis['attributes'],
            ],
            'billing' => $billing,
            'meta'    => [
                'product_key'  => $input['product_key']  ?? null,
                'product_name' => $input['product_name'] ?? null,
                'price'        => $input['price']        ?? null,
                'pitch'        => $input['pitch']        ?? null,
            ],
        ];

        return match ($pipeline) {
            'pov'            => $this->buildPovPayload($input, $base),
            'video_perfeito' => $this->buildPerfectPayload($input, $base),
            default          => $this->buildShowcasePayload($input, $analysis['category'], $base),
        };
    }

    // -------------------------------------------------------------------------
    // Builders por pipeline
    // -------------------------------------------------------------------------

    private function buildShowcasePayload(array $input, string $category, array $base): array
    {
        $vibe      = $this->resolveShowcaseVibe($category);
        $audioSlug = $input['audio_id'] ?? 'chill-lofi-01';

        $audioTrack = \Illuminate\Support\Facades\DB::table('showcase_audio_library')
            ->where('slug', $audioSlug)
            ->where('active', true)
            ->first();

        if (! $audioTrack) {
            $audioTrack = \Illuminate\Support\Facades\DB::table('showcase_audio_library')
                ->where('active', true)
                ->orderBy('id')
                ->first();
        }

        $productImages = $this->resolveProductImages($input);
        $productName   = mb_substr($input['product_name'] ?? 'produto', 0, 60);

        $renderPayload = [
            '_showcase_mode'  => true,
            '_director_mode'  => true,
            '_vibe'           => $vibe,
            '_audio_slug'     => $audioTrack->slug ?? $audioSlug,
            '_audio_path'     => $audioTrack->file_path ?? null,
            'model_name'      => 'kling-v3',
            'mode'            => 'pro',
            'aspect_ratio'    => '9:16',
            'duration'        => '10',
            'image'           => $input['image_main'],
            'elements'        => [[
                'element_id' => 'element_1',
                'images'     => $productImages,
            ]],
            'multi_prompt'    => $this->showcaseShots($vibe, $productName),
            'shot_type'       => 'customize',
            'negative_prompt' => 'talking, speaking, lip movement, mouth open, mouth articulation, saying words, singing, subtitles, text overlay, captions, watermark, low quality, blurry, CGI skin, cartoon, anime',
            'cfg_scale'       => 0.5,
        ];

        return array_merge($base, ['render' => $renderPayload]);
    }

    private function buildPovPayload(array $input, array $base): array
    {
        $scene    = 'neutral clean studio background';
        $lighting = 'soft diffused natural light';
        $variant  = 3; // variante demo (padrao)

        $productImages = $this->resolveProductImages($input);

        $renderPayload = [
            '_pov_mode'       => true,
            '_director_mode'  => true,
            '_variant'        => $variant,
            'model_name'      => 'kling-v3',
            'mode'            => 'pro',
            'aspect_ratio'    => '9:16',
            'duration'        => '10',
            'image'           => $input['image_main'],
            'elements'        => [[
                'element_id' => 'element_1',
                'images'     => $productImages,
            ]],
            'multi_prompt'    => $this->povShots($scene, $lighting, $variant),
            'shot_type'       => 'customize',
            'negative_prompt' => 'face visible, human face, person, avatar, full body, head, CGI skin, cartoon, anime, distorted hands, extra fingers, watermark, low quality',
            'cfg_scale'       => 0.5,
        ];

        return array_merge($base, ['render' => $renderPayload]);
    }

    private function buildPerfectPayload(array $input, array $base): array
    {
        $pitch = mb_substr(
            $input['pitch'] ?? $this->defaultPitch($input['product_name'] ?? 'produto', $input['price'] ?? null),
            0, 300
        );

        $productImages = $this->resolveProductImages($input);
        $avatar        = $this->resolveAvatar($input);
        $elements      = [];

        if ($avatar) {
            $elements[] = ['element_id' => 'element_1', 'images' => [$avatar['url']]];
        }
        $elements[] = [
            'element_id' => $avatar ? 'element_2' : 'element_1',
            'images'     => $productImages,
        ];

        $renderPayload = [
            '_director_mode'  => true,
            'model_name'      => 'kling-v3',
            'mode'            => 'pro',
            'aspect_ratio'    => '9:16',
            'duration'        => '10',
            'image'           => $input['image_main'],
            'elements'        => $elements,
            'multi_prompt'    => [
                ['prompt' => 'Slow dolly-in wide shot, modern Brazilian home, product as hero on clean surface, warm light, ARRI Alexa, teal-orange grade, photorealistic', 'duration' => 3.0],
                ['prompt' => 'Cut to macro close-up of product, camera slowly orbiting, extreme texture detail, shallow depth of field, cinematic bokeh', 'duration' => 3.0],
                ['prompt' => 'Cut to medium shot: person holds product with both hands, speaking enthusiastically to camera, then points to bottom-left TikTok cart, no CGI', 'duration' => 4.0],
            ],
            'shot_type'       => 'customize',
            'negative_prompt' => 'cartoon, anime, CGI skin, plastic skin, distorted hands, extra fingers, warped face, morphing, flickering, low quality, blur, watermark',
            'cfg_scale'       => 0.5,
        ];

        return array_merge($base, [
            'render'  => $renderPayload,
            'voice'   => [
                'engine'   => 'openai',
                'voice_id' => 'nova',
                'text'     => $pitch,
                'format'   => 'mp3',
                'speed'    => 1.0,
            ],
            'lipsync' => [
                'mode'      => 'audio2video',
                'video_url' => '__RENDER_OUTPUT_URL__',
                'audio_url' => '__VOICE_OUTPUT_URL__',
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    private function resolveProductImages(array $input): array
    {
        $images = [$input['image_main']];
        foreach ($input['image_refs'] ?? [] as $ref) {
            $images[] = $ref;
        }
        return array_slice(array_values(array_unique(array_filter($images))), 0, 4);
    }

    private function resolveAvatar(array $input): ?array
    {
        if (! empty($input['avatar_url'])) {
            return ['url' => $input['avatar_url'], 'source' => 'upload'];
        }
        if (! empty($input['avatar_id'])) {
            $avatar = \Illuminate\Support\Facades\DB::table('video_avatars')
                ->where('id', $input['avatar_id'])
                ->where('is_active', true)
                ->first();
            if ($avatar) {
                return ['url' => $avatar->image_url, 'id' => $avatar->id, 'source' => 'pool'];
            }
        }
        // Fallback: avatar salvo do cliente (mesmo padrao do AiVideoPerfectController)
        $userId = auth()->id();
        if ($userId) {
            $client = \Illuminate\Support\Facades\DB::table('clients')->where('user_id', $userId)->first();
            if ($client) {
                $mine = \Illuminate\Support\Facades\DB::table('client_video_avatars')
                    ->where('client_id', $client->id)
                    ->orderByDesc('updated_at')
                    ->first();
                if ($mine) {
                    if (! empty($mine->custom_avatar_url)) {
                        return ['url' => $mine->custom_avatar_url, 'source' => 'client_saved'];
                    }
                    if (! empty($mine->video_avatar_id)) {
                        $pool = \Illuminate\Support\Facades\DB::table('video_avatars')
                            ->where('id', $mine->video_avatar_id)
                            ->where('is_active', true)
                            ->first();
                        if ($pool) {
                            return ['url' => $pool->image_url, 'id' => $pool->id, 'source' => 'client_pool'];
                        }
                    }
                }
            }
        }
        return null;
    }

    private function resolveShowcaseVibe(string $category): string
    {
        return match (true) {
            in_array($category, ['roupa_feminina', 'acessorio_moda', 'joia_relogio', 'beleza_maquiagem', 'skincare'], true) => 'estetico_calmo',
            in_array($category, ['roupa_masculina', 'roupa_unissex', 'eletronico_gadget', 'informatica'], true)             => 'hipnotico_loop',
            in_array($category, ['casa_decoracao', 'cozinha_utensilio', 'pet', 'infantil'], true)                           => 'try_haul_divertido',
            default                                                                                                          => 'estetico_calmo',
        };
    }

    private function defaultPitch(string $productName, ?string $price = null): string
    {
        $short = mb_substr($productName, 0, 60);
        if ($price) {
            return "Olha esse {$short} por apenas R$ {$price}! Aproveita agora, ta no carrinho aqui embaixo!";
        }
        return "Olha esse {$short} incrivel que encontrei! Corre la no carrinho antes que acabe!";
    }

    private function extractJson(string $raw): string
    {
        // Remove markdown code blocks se existir
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $raw, $m)) {
            return $m[1];
        }
        // Extrai primeiro {...} do conteudo
        if (preg_match('/\{[\s\S]+\}/', $raw, $m)) {
            return $m[0];
        }
        return $raw;
    }

    /** Shots POV variante 3 (demo) — espelha logica do AiVideoPerfectController */
    private function povShots(string $scene, string $lighting, int $variant): array
    {
        $hands = 'human hands with natural skin texture and realistic veins';
        $rep   = fn (string $p) => str_replace(['{LIGHT}','{SCENE}','{HANDS}'], [$lighting,$scene,$hands], $p);
        $t     = [
            'first-person view, only {HANDS} visible holding @Element1 product wide shot, smooth confident movement, {LIGHT}, {SCENE}, photorealistic skin, no face, no avatar',
            'macro close-up first-person POV, {HANDS} demonstrating key feature of @Element1 product, {LIGHT}, {SCENE}, extreme texture detail, no face',
            'first-person POV, {HANDS} holding @Element1 product, thumb pointing confidently to lower-left TikTok cart button, {LIGHT}, {SCENE}, no face, photorealistic',
        ];
        return [
            ['prompt' => mb_substr($rep($t[0]), 0, 512), 'duration' => 3.0],
            ['prompt' => mb_substr($rep($t[1]), 0, 512), 'duration' => 3.5],
            ['prompt' => mb_substr($rep($t[2]), 0, 512), 'duration' => 3.5],
        ];
    }

    /** Shots showcase por vibe — espelha logica do AiVideoPerfectController::buildShowcaseShots() */
    private function showcaseShots(string $vibe, string $p): array
    {
        $sets = [
            'estetico_calmo' => [
                ['prompt' => "@Element1 {$p} silent showcase, soft window light, slow movement, neutral tones, clean background, NO SPEAKING, NO LIP MOVEMENT, natural body language, 9:16 TikTok, photorealistic 4K", 'duration' => '4'],
                ['prompt' => "extreme close-up @Element1 {$p} texture, hands gently holding, soft ring light, slow reveal, NO SPEAKING, NO MOUTH MOVEMENT, ASMR aesthetic", 'duration' => '3'],
                ['prompt' => "avatar holding @Element1 {$p} toward camera, calm elegant pose, diffused light, NO SPEAKING, natural smile closed lips, product in full frame", 'duration' => '3'],
            ],
            'try_haul_divertido' => [
                ['prompt' => "avatar revealing @Element1 {$p} with playful try-haul energy, colorful backdrop, NO SPEAKING, excited expression closed mouth, upbeat body language, 9:16 TikTok", 'duration' => '4'],
                ['prompt' => "avatar modeling @Element1 {$p} with fun hand gestures, haul video style, bright lighting, NO LIP MOVEMENT, genuine smile closed lips", 'duration' => '3'],
                ['prompt' => "avatar thumbs up holding @Element1 {$p}, colorful background, NO SPEAKING, happy closed-mouth, pointing to product", 'duration' => '3'],
            ],
            'hipnotico_loop' => [
                ['prompt' => "@Element1 {$p} rotating 360 by avatar, plain gradient background, seamless motion, NO SPEAKING, product center frame, hypnotic loop, studio lighting", 'duration' => '4'],
                ['prompt' => "slow hypnotic spin @Element1 {$p}, avatar hands rotating smoothly, clean gradient bg, NO LIP MOVEMENT, mesmerizing looping motion", 'duration' => '3'],
                ['prompt' => "avatar presenting @Element1 {$p} hypnotic circular motion, 360 reveal, neutral bg bokeh, NO SPEAKING, product in spotlight, TikTok vertical", 'duration' => '3'],
            ],
        ];
        return $sets[$vibe] ?? $sets['estetico_calmo'];
    }
}
