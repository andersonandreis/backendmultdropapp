<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiVideoPipeline;
use App\Models\StudioConversation;
use App\Models\StudioMessage;
use App\Services\Ai\KlingService;
use App\Services\Ai\OpenAiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * SEL-360 — Studio Chat: diretor de vídeo conversacional.
 *
 * Endpoints:
 *   POST /api/v1/studio-chat            — envia mensagem, resposta SSE streaming
 *   POST /api/v1/studio-chat/upload     — faz upload de imagem/vídeo/áudio
 *   GET  /api/v1/studio-chat/generation/{id}/progress — SSE de progresso
 *   POST /api/v1/studio-chat/tts        — gera áudio TTS opt-in para 1 mensagem
 *   GET  /api/v1/studio-chat/conversations — histórico de conversas do usuário
 *
 * WHITE-LABEL TOTAL: zero menção a Kling/OpenAI/BytePlus nas respostas ao cliente.
 */
class StudioChatController extends Controller
{
    private const GEAR_MODEL = [
        'rapido'      => ['model' => 'kling-v1-6', 'mode' => 'std'],
        'recomendado' => ['model' => 'kling-v2-1', 'mode' => 'pro'],
        'cinema'      => ['model' => 'kling-v3',   'mode' => 'pro'],
        'ultra'       => ['model' => 'kling-v2-1-master', 'mode' => 'pro'],
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
Você é o Studio, diretor de vídeo do Seller Global.

=== PRIORIDADE MAXIMA SEL-387 (sobrepoe todas as outras regras) ===
Quando a mensagem do cliente conter contexto de produto TikTok Shop (product_name presente, ou mencionar nome de produto):
1. NAO pergunte duracao (a duracao e sempre 12s no fluxo Kaloclip).
2. Chame IMEDIATAMENTE a tool suggest_viral_carousel com o product_name detectado.
3. Diga EXATAMENTE: "Beleza! Ja peguei as imagens do [nome_produto]. Quer se inspirar num video que ta viralizando agora no TikTok Shop com produtos parecidos? Selecionei 4 pra voce:"
4. Aguarde o cliente escolher um viral ou clicar "Vou criar do zero".
5. NUNCA chame suggest_pipelines quando ha contexto de produto — use suggest_viral_carousel.
Esta e a regra de maior prioridade. Ela overrida regras 7, 9, 11 e 14 abaixo.
=== FIM PRIORIDADE MAXIMA ===

REGRAS DURAS:
1. SEMPRE em português brasileiro, informal e direto. Nunca inglês.
2. NUNCA mencione "Kling", "OpenAI", "BytePlus", "GPT" ou qualquer engine de IA. Se cliente perguntar "que IA vocês usam", responde "IA proprietária do HubAI".
3. **DATA CENTER HUBAI (SEL-381)**: sempre se refira ao processamento como "nosso data center HubAI" / "nosso sistema" / "nossa IA". Ex: "Mandei pro nosso data center processar", "Nosso sistema já pegou seu pedido". O vídeo é FEITO POR NÓS — nunca sugira dependência de terceiros.
4. **TEMPO REALISTA**: ao disparar geração, avise "vai levar cerca de 4 a 6 minutos". Se perguntarem por que demora: "nosso data center está renderizando em alta qualidade — vale a espera". NUNCA "estamos com problema" ou "servidor lento".
5. **ENTREGA COM PROPRIEDADE**: ao entregar o link, "🎬 Pronto! Nosso data center HubAI gerou seu vídeo. Baixa aqui: [link]". Se elogiarem, aceita: "Obrigado! Nossa engine própria de IA tá afiada mesmo 🚀".
6. O cliente é vendedor de TikTok Shop querendo fazer vídeo pra vender.
   Fala como amigo que entende de vídeo, não como robô corporativo.
7. Sempre pergunta ANTES de gerar. NUNCA gera sem aprovação explícita.
8. NUNCA mencione custo, créditos, R$, preço ou estimativa de valor de um vídeo.
   Nem quando o cliente perguntar quanto custa — responde que está incluso no
   pacote de créditos dele e segue o fluxo.
6. Se faltar asset (foto, avatar, vídeo), o sistema gera automaticamente.
   NUNCA pergunte se o cliente "tem mais fotos": as fotos do produto (capa,
   galeria, variações, reviews) já são coletadas automaticamente pelo sistema.
   Use SEMPRE o máximo de imagens disponíveis (até 7 referências) — nunca
   gere com 1 imagem só se existirem mais.
7. Sugere sempre 2-4 opções em cards clicáveis, não texto longo.
8. Se pedido ambíguo, faz UMA pergunta clara, não várias.
9. Nunca inventa feature. Só oferece: Showcase Kaloclip (PADRAO TikTok Shop), Animar Produto, POV Só Mão, Cena com Referências,
   Vídeo do Zero, Vídeo Multi-Cortes, Câmera Cinematográfica, Avatar Apresentando,
   Trocar Rosto, Provador Virtual, Continuar Vídeo, Sincronizar Fala, Efeitos Prontos, Voz Brasileira.
10. Se cliente quiser algo impossível, explica o porquê e sugere alternativa.
11. DURAÇÃO SEMPRE PRIMEIRO (exceto fluxo Kaloclip — nesse caso duração já é 12s): antes de gerar roteiro, PERGUNTA a duração do vídeo
    ({DURACOES}). O roteiro é escrito PARA CABER na duração escolhida,
    nunca ao contrário. Se cliente pedir "faz roteiro" sem falar duração, pergunta:
    "Quanto tempo você quer o vídeo? {DURACOES}?"
    NUNCA chama generate_script antes de ter duration_sec confirmado pelo cliente.
    NUNCA ofereça duração fora da lista {DURACOES} — se o cliente pedir mais que o
    máximo, explica o limite e sugere a maior disponível.
12. HOOK OBRIGATORIO: roteiro DEVE comecar com hook visual (reveal_macro|corte_acao|antes_depois|pergunta_visual|zoom_explosivo) + hook verbal (Para tudo!|Olha isso!|Voce sabia?|Segredo:|Ninguem te conta que...). Se roteiro nao tem hook, regenera.
13. AVATAR EXCLUSIVO: pipelines com rosto (avatar_apresentando|trocar_rosto|showcase_silencioso) requerem avatar exclusivo do cliente. Se nao tiver, oferecer: [Criar avatar agora 30s] [Enviar foto]. NUNCA usar pool compartilhado.
14. CONTEXTO DE PRODUTO (SEL-387 KALOCLIP): se aberto com contexto de produto (produto TikTok Shop), NAO chame suggest_pipelines — chame IMEDIATAMENTE suggest_viral_carousel com o product_name disponivel. Mostre o carousel de 4 virais do TikTok Shop antes de qualquer outra acao. Quantidade de fotos impacta sugestao de modo apenas no fluxo 'criar do zero'.
15. PROATIVO: antes de gerar, explica em 1-2 frases o que vai fazer e pergunta
    as preferências do cliente: duração (regra 11), qualidade (HD ultrarrealista?)
    e dinâmica do vídeo (calmo, energético, ASMR). Sugere a melhor opção
    em vez de esperar o cliente adivinhar.
16. POV TEM DOIS CAMINHOS: (a) cliente mandou foto JÁ com o produto na mão →
    segue direto pra animação dessa foto; (b) cliente só tem foto do produto →
    o sistema MONTA a cena com a foto REAL do produto + referências automáticas
    (galeria, reviews, variações). NUNCA diga que precisa de foto com a mão nem
    peça pro cliente tirar foto segurando — o caminho (b) resolve sozinho.
17. TROCAR A MODELO É ENCADEADO: primeiro o sistema troca a pessoa NA IMAGEM,
    depois ANIMA a imagem trocada — o resultado final entregue é VÍDEO.
    Não pergunte se o cliente quer animar depois da troca: já faz parte.

18. AUTO-RETRY (self-heal): mensagem comecando com [AUTO-RETRY] e o SISTEMA
    avisando que a geracao falhou — nao e o cliente. Explica pro cliente em 1
    frase que deu um erro e que voce ja esta tentando de novo de outro jeito,
    ajusta os parametros (gear mais estavel ex. recomendado, prompt mais
    simples) e chama execute_generation IMEDIATAMENTE, sem pedir confirmacao.
    REUSE as MESMAS fotos e o mesmo produto da geracao que falhou — eles ja
    estao no historico desta conversa; NUNCA peca a foto de novo no retry.
    NUNCA repita exatamente os mesmos parametros que falharam e NUNCA deixe
    o erro sem resposta no chat.

19. TEXTO DO CLIENTE E LEI (SEL-429): se o cliente escreveu a ideia, o roteiro ou a
    fala dele, USE AS PALAVRAS DELE. Nunca reescreva, resuma nem "melhore" o que ele
    digitou. Sugestao serve de ponto de partida, nunca de substituicao. Ao chamar
    execute_generation, copie o texto dele LITERAL no campo client_brief.

TOM: "Show!", "Bora!", "Perfeito!", "Ótimo!", "Fechou!"
Emoji: 1 por mensagem no máximo.
Frases curtas, 1-2 por resposta.
Assinatura: -Studio 🎬

SEL-387 — FLUXO KALOCLIP (PADRAO PRINCIPAL a partir de agora):
Quando o cliente chega com produto TikTok Shop (via URL ?product= ou escolhendo catalogo):

TURNO 1 OBRIGATORIO (execute imediatamente ao receber contexto de produto):
Diga EXATAMENTE:
"Beleza! Ja peguei as imagens do <nome_produto>. Quer se inspirar num video que ta viralizando agora no TikTok Shop com produtos parecidos? Selecionei 4 pra voce:"
+ emita o widget viral_carousel (4 virais do banco)
+ adicione botao "Vou criar do zero" como opcao abaixo do carousel.

TURNO 2 (quando cliente clicar num viral):
Diga EXATAMENTE:
"Otima escolha. Vou usar a estrutura desse video e adaptar pro seu produto. Gerar agora?"
+ mostre botao "Gerar agora".

QUANDO CLIENTE CLICAR "Gerar agora" ou confirmar:
- Use pipeline showcase_kaloclip (12s, 3 cortes internos, PT-BR, 9:16, com audio)
- Gear padrao: recomendado
- duration_sec: 12 (SEMPRE 12 no fluxo Kaloclip)
- aspect_ratio: 9:16 (SEMPRE)
- Mostre spinner + "Nosso data center HubAI esta gerando seu video de 12 segundos com 3 cortes dinamicos. Vai levar de 4 a 8 minutos."

REGRAS DURAS SEL-387:
- Duracao PADRAO no fluxo Kaloclip = 12s (3 cortes internos de 4s cada)
- NUNCA mostrar custo, creditos ou qualquer valor monetario
- NUNCA citar "Kling" — "nosso data center HubAI"
- 9:16 vertical SEMPRE
- PT-BR SEMPRE

CATÁLOGO DE FUNÇÕES (use internamente pra decidir qual pipeline disparar):
- showcase_kaloclip: NOVO padrao — produto TikTok Shop + 12s + 3 cortes + PT-BR + 9:16 + audio. Pipeline principal SEL-387.
- animar_produto: 1 foto do produto → video com movimento sutil. Gear: Rapido/Recomendado/Cinema/Ultra.
- pov_so_mao: POV com mao segurando o produto REAL (foto do cliente ou catalogo + refs), 9:16, 3 cortes. Sem foto com mao, o sistema monta a cena sozinho.
- cena_com_referencias: 2-7 fotos → cena nova anti-slideshow. Gear Rapido obrigatorio (multi-image2video kling-v1-6).
- video_do_zero: so descricao em texto → video. Gear Recomendado/Cinema.
- video_multi_cortes: 1 foto + 3 shots diferentes (macro/medium/CTA) num render. Gear Cinema.
- camera_cinematografica: pan/tilt/zoom/orbit sobre a foto. Gear Recomendado+.
- avatar_apresentando: avatar + produto → avatar mostrando. Gear Recomendado+.
- trocar_rosto: video alvo + rosto → mesmo video rosto trocado. (Aviso ToS obrigatorio)
- provador_virtual: foto pessoa + foto roupa/acessorio → troca na imagem e ANIMA → video da pessoa vestindo.
- continuar_video: video 5s + descricao → +5s continuacao.
- sincronizar_fala: video + roteiro/audio → boca sincronizada.
- efeitos_prontos: templates virais (unbox, cheers, teleport, countdown).
- voz_brasileira: roteiro em texto → narracao em voz PT-BR.
PROMPT;

    private const TOOLS = [
        [
            'type' => 'function',
            'function' => [
                'name' => 'analyze_uploaded_image',
                'description' => 'Analisa imagem enviada pelo cliente. Identifica produto, categoria, atributos e sugere pipelines.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'image_url' => ['type' => 'string'],
                    ],
                    'required' => ['image_url'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'suggest_pipelines',
                'description' => 'Sugere pipelines de vídeo baseado no intent do cliente e assets disponíveis.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'intent'   => ['type' => 'string'],
                        'assets'   => ['type' => 'array', 'items' => ['type' => 'string']],
                        'category' => ['type' => 'string'],
                    ],
                    'required' => ['intent'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'generate_script',
                'description' => 'Gera roteiro de venda viral pra TikTok Shop em PT-BR.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_name' => ['type' => 'string'],
                        'product_desc' => ['type' => 'string'],
                        'tone'         => ['type' => 'string', 'enum' => ['energetico','suave','asmr','hard_sell','ugc']],
                        'duration_sec' => ['type' => 'integer'],
                    ],
                    'required' => ['product_name', 'duration_sec'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'execute_generation',
                'description' => 'Dispara a geração após aprovação explícita do cliente. Só chamar quando o cliente confirmar com "gerar", "bora", "aprovar", etc.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'pipeline'           => ['type' => 'string', 'enum' => ['showcase_kaloclip','animar_produto','pov_so_mao','cena_com_referencias','video_do_zero','video_multi_cortes','camera_cinematografica','avatar_apresentando','trocar_rosto','provador_virtual','continuar_video','sincronizar_fala','efeitos_prontos','voz_brasileira']],
                        'gear'               => ['type' => 'string', 'enum' => ['rapido','recomendado','cinema','ultra']],
                        'duration_sec'       => ['type' => 'integer'],
                        'image_url'          => ['type' => 'string'],
                        'image_refs'         => ['type' => 'array', 'items' => ['type' => 'string']],
                        'prompt'             => ['type' => 'string'],
                        'aspect_ratio'       => ['type' => 'string', 'enum' => ['9:16','16:9','1:1']],
                        'generate_hand_image'=> ['type' => 'boolean'],
                        'avatar_url'         => ['type' => 'string'],
                        'camera_preset'      => ['type' => 'string', 'enum' => ['pan_left','pan_right','tilt_up','tilt_down','zoom_in','zoom_out','orbit_left','orbit_right']],
                        'face_url'           => ['type' => 'string'],
                        'target_video_url'   => ['type' => 'string'],
                        'person_url'         => ['type' => 'string'],
                        'cloth_url'          => ['type' => 'string'],
                        'source_video_url'   => ['type' => 'string'],
                        'audio_url'          => ['type' => 'string'],
                        'tts_text'           => ['type' => 'string'],
                        'effect_key'         => ['type' => 'string', 'enum' => ['unbox_2026','cheers','teleport','countdown']],
                        'voice'              => ['type' => 'string', 'enum' => ['nova','shimmer','alloy','echo','fable','onyx']],
                        'tos_confirmed'      => ['type' => 'boolean'],
                        // SEL-429: COPIE LITERALMENTE o que o cliente escreveu sobre a ideia/roteiro.
                        // Nunca resuma, traduza nem reescreva — o texto dele e obedecido como esta.
                        'client_brief'       => ['type' => 'string', 'description' => 'Texto LITERAL do cliente sobre a ideia/roteiro/cena que ele quer. Copie palavra por palavra, NUNCA reescreva nem resuma.'],
                    ],
                    'required' => ['pipeline', 'gear', 'duration_sec'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'suggest_viral_carousel',
                'description' => 'SEL-387: Busca 4 videos virais do TikTok Shop para o carousel de inspiracao. Chamar no TURNO 1 quando ha contexto de produto (product_name, product_key ou product_id disponivel). Retorna widget viral_carousel para o front renderizar.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_name' => ['type' => 'string', 'description' => 'Nome do produto para filtrar virais relacionados'],
                        'product_key'  => ['type' => 'string', 'description' => 'Chave unica do produto TikTok Shop'],
                        'product_id'   => ['type' => 'integer', 'description' => 'ID do client_product'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'check_celebrity',
                'description' => 'Verifica se o video/imagem contem rosto de celebridade ou figura publica. Usar ANTES de trocar_rosto.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'media_url' => ['type' => 'string'],
                    ],
                    'required' => ['media_url'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_effect_templates',
                'description' => 'Lista os templates de efeitos prontos disponiveis para o cliente escolher.',
                // properties vazio serializa como [] e a OpenAI rejeita com 400 — precisa de ao menos 1 prop
                'parameters' => ['type' => 'object', 'properties' => [
                    'category' => ['type' => 'string', 'description' => 'Categoria opcional para filtrar os efeitos.'],
                ]],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_avatars_pool',
                'description' => 'Lista os avatares disponiveis no pool do Studio para o pipeline avatar_apresentando.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => ['type' => 'string', 'description' => 'Filtro opcional: feminino|masculino|neutro'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'create_generation_plan',
                'description' => 'Cria um plano DAG de geracao multi-etapa e mostra ao cliente para aprovacao antes de disparar.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'intent'  => ['type' => 'string'],
                        'assets'  => ['type' => 'array', 'items' => ['type' => 'string']],
                        'config'  => ['type' => 'object'],
                    ],
                    'required' => ['intent'],
                ],
            ],
        ],
    ];

    public function __construct(
        private OpenAiService $openai,
        private KlingService  $kling,
    ) {}

    /**
     * POST /api/v1/studio-chat
     * Envia mensagem para o Studio. Resposta via SSE streaming.
     */
    public function chat(Request $request)
    {
        $v = $request->validate([
            'conversation_id' => 'nullable|string|max:36',
            'message'         => 'nullable|string|max:4000|required_without:attachments',
            'attachments'     => 'nullable|array|max:8', // SEL-428 (Ruan 30/07): produto com foto real trazia so 5 anexos; carrossel/extractImages ja usam teto 8 no resto do fluxo — alinhado aqui
            'attachments.*'   => 'string|max:2000',
        ]);

        // SEL-364 27/07: cliente pode mandar SO a foto (sem texto) - antes o
        // required derrubava a request em 302 redirect + CORS (Accept: text/event-stream).
        $v['message'] = trim((string) ($v['message'] ?? ''));
        if ($v['message'] === '') {
            $v['message'] = 'Enviei a foto do produto. Analisa e me guia.';
        }

        $user     = $request->user();
        $tenantId = $user->tenant_id ?? null;

        $conv = ($v['conversation_id'] ?? null)
            ? StudioConversation::where('uuid', $v['conversation_id'])
                ->where('user_id', $user->id)
                ->firstOrFail()
            : StudioConversation::create([
                'user_id'   => $user->id,
                'tenant_id' => $tenantId,
                'status'    => 'active',
            ]);

        StudioMessage::create([
            'conversation_id' => $conv->id,
            'role'            => 'user',
            'content'         => $v['message'],
            'attachments'     => $v['attachments'] ?? null,
        ]);

        // SEL-364c: pegar as ULTIMAS 20 msgs, nao as primeiras. Com orderBy asc +
        // limit, conversas com >20 msgs congelavam o historico e o modelo nunca
        // via as mensagens novas (loop infinito de confirmacao — caso Adriano conv 91).
        $history = StudioMessage::where('conversation_id', $conv->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->values()
            ->map(fn($m) => ['role' => $m->role === 'tool' ? 'assistant' : $m->role, 'content' => $m->content])
            ->toArray();

        $messages = [['role' => 'system', 'content' => $this->buildSystemPrompt()], ...$history];

        // P2 SEL-361: reforco regra 11 — injeta em CADA turno pra ignorar drift de historico
        $messages[] = [
            'role'    => 'system',
            'content' => 'REFORCO REGRA 11: olhe o historico. Se o cliente JA informou a duracao do video (ex: "15s", "quero 20 segundos") em QUALQUER mensagem, NAO pergunte a duracao de novo — siga direto pro proximo passo. Se ele AINDA NAO informou, pergunte agora (' . $this->durationsLabel() . ') antes de gerar roteiro. Duracoes disponiveis HOJE: ' . $this->durationsLabel() . ' — NUNCA ofereca fora dessa lista. NUNCA gere roteiro sem duration_sec confirmado. ATENCAO TOTAL AO HISTORICO: NUNCA re-pergunte nada que o cliente ja respondeu. Se o roteiro ja foi gerado e o cliente aprovou/confirmou (sim, ok, bora, pode gerar, aprovo), chame execute_generation IMEDIATAMENTE com os parametros ja coletados — NAO peca confirmacao de novo.',
        ];

        // SEL-440 (30/07, Ruan: "depois que seleciona o produto as perguntas tem
        // que ser relacionadas ao produto e pensa em coisa pra ele selecionar e
        // mexer: inicio, meio e fim do video" + "tem que perguntar"). Com produto
        // conhecido o Studio conduz por TRES perguntas, uma de cada vez, sobre as
        // tres partes do video -- e nunca pergunta cenario/enquadramento/estilo,
        // que e trabalho nosso, nao decisao do cliente.
        if ($regra = $this->regraEstruturaDoVideo($conv)) {
            $messages[] = ['role' => 'system', 'content' => $regra];
        }

        // SEL-436: se o cliente escreveu o proprio roteiro, o cabimento e medido
        // por codigo ANTES do modelo falar -- e o modelo recebe o numero pronto.
        // Deixar isso a cargo do modelo faria ele chutar "cabe" e o audio estourar.
        $cabimento = $this->analisarCabimento(
            $v['message'],
            $this->clientConfirmedDuration($conv) ?? null,
            $conv->context['lang'] ?? 'pt-BR'
        );
        // SEL-436b (30/07): o sseEvent estava AQUI, fora do response()->stream().
        // Dava echo antes de existir resposta -> 503 em TODA mensagem com 12+
        // palavras, ou seja, o Studio inteiro quebrado pra qualquer frase normal.
        // O recado pro modelo pode ser montado aqui; o widget so pode ser emitido
        // DENTRO do stream.
        if ($cabimento) {
            $messages[] = ['role' => 'system', 'content' => $this->recadoCabimento($cabimento)];
            Log::info('[SEL-436] cabimento do roteiro do cliente', $cabimento);
        }

        // SEL-365: plano com integracao inclusa -> chat oferece agendamento com
        // funcionario em vez de guiar setup manual de marketplace.
        if ($schedNote = $this->integrationSchedulingNote($user)) {
            $messages[] = ['role' => 'system', 'content' => $schedNote];
        }

        // SEL-364c: atencao garantida por codigo — cliente aprovou e ja existe roteiro
        // gerado sem geracao disparada? Forca execute_generation no primeiro round em
        // vez de confiar no modelo (caso Adriano: 13 aprovacoes ignoradas).
        $forceTool = null;
        $userTxt   = mb_strtolower(trim($v['message']));
        $isApproval = preg_match('/^(sim|ok|okay|certo|bora|beleza|show|isso|dale|vai|manda|faz|gera|confirmo|aprovo|aprovado|pode gerar|pode sim|pode|vamos|vamo|blz|top|fechou|perfeito)[!.…\s]*$/u', $userTxt)
            || preg_match('/\b(aprovo|pode gerar|bora gerar|gera o video|gera o vídeo|confirmo|manda ver|pode fazer)\b/u', $userTxt);
        if ($isApproval) {
            $hasScript = StudioMessage::where('conversation_id', $conv->id)
                ->where('tool_calls', 'like', '%generate_script%')->exists();
            $hasExec = StudioMessage::where('conversation_id', $conv->id)
                ->where('tool_calls', 'like', '%execute_generation%')->exists();
            if ($hasScript && !$hasExec) {
                $forceTool = 'execute_generation';
            }
        }

        // SEL-387 fix: se mensagem contém contexto de produto (primeiro turno pós-catalog),
        // FORCE suggest_viral_carousel — antes o GPT escrevia [widget viral_carousel] como texto.
        if ($forceTool === null) {
            $rawUserTxt = (string) ($v['message'] ?? '');
            $hasProductCtx = (stripos($rawUserTxt, '[CONTEXTO DO PRODUTO]') !== false)
                || preg_match('/Quero criar um v[ií]deo do produto/iu', $rawUserTxt);
            if ($hasProductCtx) {
                $hasCarousel = StudioMessage::where('conversation_id', $conv->id)
                    ->where('tool_calls', 'like', '%suggest_viral_carousel%')->exists();
                if (!$hasCarousel) {
                    $forceTool = 'suggest_viral_carousel';
                }
            }
        }

        // Injetar imagens no último turno DE USER — o reforço da regra 11 (system)
        // vem depois do histórico; anexar imagem em role=system dá 400 na OpenAI.
        if (!empty($v['attachments'])) {
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (($messages[$i]['role'] ?? null) !== 'user') continue;
                if (!is_string($messages[$i]['content'])) break;
                $txt = $messages[$i]['content']
                    . "\n\n[URLs dos anexos enviados: " . implode(' , ', $v['attachments']) . ']';
                $parts = [['type' => 'text', 'text' => $txt]];
                foreach ($v['attachments'] as $url) {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url, 'detail' => 'low']];
                }
                $messages[$i]['content'] = $parts;
                break;
            }
        }

        return response()->stream(function () use ($messages, $conv, $user, $forceTool, $cabimento) {
            $this->streamChat($messages, $conv, $user, $forceTool, $cabimento);
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function streamChat(array $messages, StudioConversation $conv, $user, ?string $forceTool = null, ?array $cabimento = null): void
    {
        $key = config('services.openai.api_key');
        $this->sseEvent('meta', ['conversation_id' => $conv->uuid]);

        // SEL-436b: agora sim — dentro do stream, com a resposta ja aberta.
        if (!empty($cabimento)) {
            $this->sseEvent('ui_widget', ['type' => 'script_fit', 'data' => $cabimento]);
        }

        try {
            // SEL-364: loop de tool-rounds — depois de executar as tools o resultado
            // volta pro modelo comentar e guiar o proximo passo. Antes era single-pass:
            // o stream morria mudo logo apos o tool_result (Studio "nao proativo").
            $fullText        = '';
            $widgetsToSend   = [];
            $allToolCalls    = [];
            $lastToolMessage = null;

            for ($round = 0; $round < 4; $round++) {
                $body = [
                    'model'       => 'gpt-4o-mini',
                    'messages'    => $messages,
                    'tools'       => self::TOOLS,
                    'tool_choice' => ($round === 0 && $forceTool)
                        ? ['type' => 'function', 'function' => ['name' => $forceTool]]
                        : 'auto',
                    'stream'      => true,
                    'max_tokens'  => 800,
                    'temperature' => 0.7,
                ];

                $ch = curl_init('https://api.openai.com/v1/chat/completions');
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $key,
                        'Content-Type: application/json',
                        'Accept: text/event-stream',
                    ],
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode($body),
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => 60,
                ]);

                $buffer       = '';
                $rawBody      = '';
                $roundText    = '';
                $toolCalls    = [];
                $finishReason = null;

                curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$buffer, &$rawBody, &$roundText, &$toolCalls, &$finishReason) {
                    if (strlen($rawBody) < 2000) $rawBody .= $chunk;
                    $buffer .= $chunk;
                    $lines   = explode("\n", $buffer);
                    $buffer  = array_pop($lines);

                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (!str_starts_with($line, 'data: ')) continue;
                        $data = substr($line, 6);
                        if ($data === '[DONE]') { $finishReason = $finishReason ?? 'stop'; continue; }
                        $obj = json_decode($data, true);
                        if (!$obj) continue;

                        $delta  = $obj['choices'][0]['delta'] ?? [];
                        $reason = $obj['choices'][0]['finish_reason'] ?? null;
                        if ($reason) $finishReason = $reason;

                        if (!empty($delta['content'])) {
                            $roundText .= $delta['content'];
                            $this->sseEvent('delta', ['text' => $delta['content']]);
                        }

                        if (!empty($delta['tool_calls'])) {
                            foreach ($delta['tool_calls'] as $tc) {
                                $i = $tc['index'];
                                if (!isset($toolCalls[$i])) {
                                    $toolCalls[$i] = ['id' => $tc['id'] ?? '', 'name' => '', 'args' => ''];
                                }
                                if (!empty($tc['function']['name'])) $toolCalls[$i]['name'] .= $tc['function']['name'];
                                if (!empty($tc['function']['arguments'])) $toolCalls[$i]['args'] .= $tc['function']['arguments'];
                            }
                        }
                    }
                    return strlen($chunk);
                });

                curl_exec($ch);
                $curlErr  = curl_error($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($curlErr) {
                    $this->sseEvent('error', ['message' => 'Erro de conexão com o motor de IA. Tente novamente.']);
                    return;
                }

                // HTTP error da OpenAI não vem em linhas "data:" — sem isso o stream termina VAZIO e silencioso
                if ($httpCode >= 400) {
                    Log::error('[SEL-360 StudioChat] OpenAI HTTP error', ['status' => $httpCode, 'body' => substr($rawBody, 0, 1000)]);
                    $this->sseEvent('error', ['message' => 'O motor de IA recusou a solicitação. Tenta de novo em instantes!']);
                    return;
                }

                if ($roundText !== '') {
                    $fullText .= ($fullText !== '' ? "\n\n" : '') . $roundText;
                }

                if (empty($toolCalls)) break;

                $assistantToolMsg = [
                    'role'       => 'assistant',
                    'content'    => $roundText !== '' ? $roundText : null,
                    'tool_calls' => [],
                ];
                $toolResultMsgs = [];

                foreach ($toolCalls as $i => $tc) {
                    $args   = json_decode($tc['args'], true) ?? [];
                    $result = $this->handleToolCall($tc['name'], $args, $conv, $user);
                    $this->sseEvent('tool_result', ['tool' => $tc['name'], 'result' => $result]);
                    if (!empty($result['ui_widget'])) {
                        $widgetsToSend[] = $result['ui_widget'];
                        $this->sseEvent('ui_widget', $result['ui_widget']);
                    }
                    if (!empty($result['message'])) {
                        $lastToolMessage = $result['message'];
                    }

                    $callId = $tc['id'] !== '' ? $tc['id'] : ('call_r' . $round . '_' . $i);
                    $assistantToolMsg['tool_calls'][] = [
                        'id'       => $callId,
                        'type'     => 'function',
                        'function' => ['name' => $tc['name'], 'arguments' => $tc['args'] !== '' ? $tc['args'] : '{}'],
                    ];
                    $resultForModel = $result;
                    unset($resultForModel['ui_widget']);
                    $toolResultMsgs[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $callId,
                        'content'      => json_encode($resultForModel, JSON_UNESCAPED_UNICODE),
                    ];
                    $allToolCalls[] = $tc;
                }

                $messages[] = $assistantToolMsg;
                foreach ($toolResultMsgs as $m) $messages[] = $m;
            }

            // Fallback: modelo terminou mudo mas alguma tool trouxe mensagem pro cliente
            if ($fullText === '' && !empty($lastToolMessage)) {
                $fullText = $lastToolMessage;
                $this->sseEvent('delta', ['text' => $fullText]);
            }

            // SEL-429 (Ruan 30/07): "nas perguntas que voce fizer ja venham alguns
            // botoes pra ele clicar, senao ele fica perdido". As sugestoes nascem
            // AQUI, junto com a pergunta — quem acabou de perguntar sabe quais
            // respostas cabem. Derivar no front por palavra-chave seria o codigo
            // adivinhando. Opcional por design: sem chips a pergunta aparece normal.
            $chips = $this->gerarSugestoes($fullText, $conv);
            if (!empty($chips)) {
                $chipWidget = ['type' => 'suggest_chips', 'data' => ['chips' => $chips]];
                $widgetsToSend[] = $chipWidget;
                $this->sseEvent('ui_widget', $chipWidget);
            }

            // Salvar mensagem assistente
            if ($fullText || !empty($allToolCalls)) {
                $savedMsg = StudioMessage::create([
                    'conversation_id' => $conv->id,
                    'role'            => 'assistant',
                    'content'         => $fullText ?: '',
                    'tool_calls'      => !empty($allToolCalls) ? array_values($allToolCalls) : null,
                    'ui_widget'       => !empty($widgetsToSend) ? $widgetsToSend : null,
                ]);
                $this->sseEvent('done', ['message_id' => $savedMsg->id]);
            }

        } catch (Throwable $e) {
            Log::error('[SEL-360 StudioChat] stream error', ['error' => $e->getMessage()]);
            $this->sseEvent('error', ['message' => 'Algo deu errado. Tenta de novo!']);
        }

        echo "data: [DONE]\n\n";
        ob_flush();
        flush();
    }

    private function handleToolCall(string $name, array $args, StudioConversation $conv, $user): array
    {
        return match($name) {
            'suggest_viral_carousel'  => $this->toolSuggestViralCarousel($args, $conv),
            'analyze_uploaded_image' => $this->toolAnalyzeImage($args, $conv),
            'suggest_pipelines'      => $this->toolSuggestPipelines($args),
            'generate_script'        => $this->toolGenerateScript($args, $conv),
            'execute_generation'     => $this->toolExecuteGeneration($args, $conv, $user),
            'check_celebrity'        => $this->toolCheckCelebrity($args),
            'list_effect_templates'  => $this->toolListEffectTemplates(),
            'list_avatars_pool'      => $this->toolListAvatarsPool($args),
            'create_generation_plan' => $this->toolCreateGenerationPlan($args, $conv),
            default                  => ['error' => 'tool_not_found'],
        };
    }

    private function toolAnalyzeImage(array $args, StudioConversation $conv): array
    {
        $imageUrl = $args['image_url'] ?? null;

        // SEL-364: o modelo nao enxerga a URL das imagens (viram tokens de visao)
        // e alucina o arg image_url -> 400 na OpenAI. Se o arg nao corresponder a
        // um anexo real da conversa, usa o anexo de imagem mais recente.
        $allAttach = StudioMessage::where('conversation_id', $conv->id)
            ->where('role', 'user')
            ->whereNotNull('attachments')
            ->orderByDesc('id')
            ->pluck('attachments')
            ->flatMap(fn ($a) => is_string($a) ? (array) json_decode($a, true) : (array) $a)
            ->filter(fn ($u) => is_string($u) && preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $u))
            ->values();
        if ($allAttach->isNotEmpty() && (!$imageUrl || !$allAttach->contains($imageUrl))) {
            $imageUrl = $allAttach->first();
        }
        if (!$imageUrl) return ['error' => 'image_url_required'];

        try {
            $res = $this->openai->analyzeImage($imageUrl, 'Analise este produto para vídeo TikTok Shop. Retorne JSON com: product_name (string), category (string), attributes (array of strings), has_person (bool), has_hand (bool), suggested_pipelines (array of strings: animar_produto|pov_so_mao|cena_com_referencias). Em PT-BR.');

            $ctx = $conv->context ?? [];
            $ctx['analyzed_image'] = $imageUrl;
            $ctx['product_info']   = $res;
            $conv->update(['context' => $ctx]);

            // SEL-433: o nome sai da analise da foto -- guarda pra pergunta
            // seguinte falar do produto certo, com a foto certa.
            $this->lembrarProduto($conv, is_array($res) ? ($res['product_name'] ?? null) : null, $imageUrl);

            return [
                'analysis'  => $res,
                'message'   => null,
                'ui_widget' => ['type' => 'image_analyzed', 'data' => $res],
            ];
        } catch (Throwable $e) {
            Log::warning('[SEL-360] analyze_image failed', ['err' => $e->getMessage()]);
            return ['error' => $e->getMessage(), 'message' => 'Não consegui analisar a imagem, mas pode me contar o que é o produto!'];
        }
    }

    private function toolSuggestPipelines(array $args): array
    {
        $intent   = $args['intent'] ?? '';
        $assets   = $args['assets'] ?? [];
        $category = $args['category'] ?? '';

        $suggestions = [];

        if (!empty($assets)) {
            $suggestions[] = ['id' => 'animar_produto', 'label' => 'Animar Produto', 'desc' => 'Foto vira vídeo com movimento sutil', 'icon' => 'sparkles', 'rec' => false];
        }

        if (count($assets) >= 2) {
            $suggestions[] = ['id' => 'cena_com_referencias', 'label' => 'Cena com Referências', 'desc' => '2-7 fotos → cena nova ao vivo', 'icon' => 'layout-grid', 'rec' => true];
        }

        $suggestions[] = ['id' => 'pov_so_mao', 'label' => 'POV — Só Mão', 'desc' => 'Mão demonstrando o produto, sem rosto', 'icon' => 'hand', 'rec' => false];

        if (str_contains($intent, 'avatar') || str_contains($intent, 'pessoa') || str_contains($intent, 'model')) {
            $suggestions[] = ['id' => 'avatar_apresentando', 'label' => 'Avatar Apresentando', 'desc' => 'Pessoa virtual mostrando seu produto', 'icon' => 'user', 'rec' => false];
        }

        return [
            'suggestions' => $suggestions,
            'ui_widget'   => ['type' => 'pipeline_cards', 'data' => $suggestions],
        ];
    }

    /**
     * SEL-387 — Busca 4 virais do TikTok Shop pro carousel de inspiracao Kaloclip.
     * Chama o endpoint interno e retorna widget viral_carousel pra o front renderizar.
     */
    private function toolSuggestViralCarousel(array $args, StudioConversation $conv): array
    {
        $productName = $args['product_name'] ?? null;
        $productKey  = $args['product_key']  ?? null;
        $productId   = $args['product_id']   ?? null;

        // SEL-433: o nome chegava aqui e era descartado -- so os virais eram
        // salvos. Era essa a origem da pergunta generica.
        $this->lembrarProduto($conv, $productName);

        // Salva no contexto da conversa
        $ctx = $conv->context ?? [];

        // Busca virais direto no banco (evita HTTP loopback)
        try {
            $query = \App\Models\TiktokViralVideo::query()
                ->whereNotNull('cover_url')
                ->where('cover_url', '!=', '')
                ->orderByDesc('viral_score')
                ->limit(4);

            // SEL-434 (30/07, Ruan ao vivo: "nao e a mesma foto"). Dois defeitos
            // aqui, os dois calados:
            //  1) so aceitava o filtro se achasse 4+ relacionados; com 1, 2 ou 3
            //     ele trocava pelos 4 mais vistos do site INTEIRO e o titulo
            //     continuava com o nome do produto do cliente. Vitrine mentindo.
            //  2) casava por palavra generica: "Afiador Eletrico" pegava
            //     "Eletrico" e trazia aquecedor, lixador de pes e depilador.
            // Agora: palavra generica nao vale como chave, aproveita quantos
            // relacionados existirem, e o que for enchimento vai MARCADO.
            $relacionadosIds = [];
            if ($productName) {
                $genericas = [
                    'eletrico', 'eletrica', 'profissional', 'original', 'portatil',
                    'automatico', 'automatica', 'multifuncional', 'recarregavel',
                    'inteligente', 'universal', 'premium', 'unissex', 'feminino',
                    'masculino', 'infantil', 'kit', 'conjunto', 'para', 'com', 'sem',
                ];
                $semAcento = fn ($t) => mb_strtolower(preg_replace(
                    ['/[\x{00E0}-\x{00E5}]/u','/[\x{00E8}-\x{00EB}]/u','/[\x{00EC}-\x{00EF}]/u','/[\x{00F2}-\x{00F6}]/u','/[\x{00F9}-\x{00FC}]/u','/[\x{00E7}]/u'],
                    ['a','e','i','o','u','c'],
                    (string) $t
                ));
                $name  = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $productName);
                $words = array_values(array_filter(
                    preg_split('/\s+/', trim($name)),
                    fn ($w) => mb_strlen($w) >= 4 && ! in_array($semAcento($w), $genericas, true)
                ));
                $words = array_slice($words, 0, 3);

                if (! empty($words)) {
                    $relacionados = (clone $query)->where(function ($q) use ($words) {
                        foreach ($words as $w) {
                            $q->orWhere('caption', 'like', '%' . $w . '%')
                              ->orWhere('search_term', 'like', '%' . $w . '%')
                              ->orWhere('detected_product_title', 'like', '%' . $w . '%');
                        }
                    })->get(['id']);
                    $relacionadosIds = $relacionados->pluck('id')->all();
                }

                Log::info('[SEL-434] virais relacionados ao produto', [
                    'produto'      => $productName,
                    'chaves'       => $words ?? [],
                    'relacionados' => count($relacionadosIds),
                ]);

                if (! empty($relacionadosIds)) {
                    $query = (clone $query)->whereIn('id', $relacionadosIds);
                }
            }

            $videos = $query->get([
                'id', 'caption', 'cover_url', 'views', 'likes',
                'creator_name', 'creator_handle', 'detected_product_title',
            ]);

            $appUrl = rtrim(config('app.url', 'https://api.seller.global'), '/');

            $virals = $videos->map(function ($v) use ($appUrl, $relacionadosIds) {
                $thumb = $v->cover_url;
                if (empty($thumb) || !str_contains($thumb, 'api.seller.global')) {
                    $thumb = "{$appUrl}/storage/tt-media/viralvid_{$v->id}.jpg";
                }
                $caption = $v->caption ?? '';
                $hook = mb_strlen($caption) > 80 ? mb_substr($caption, 0, 80) . '...' : $caption;
                if (empty(trim($hook))) {
                    $hook = $v->detected_product_title ?? 'Video viral TikTok Shop';
                }
                $views = (int) $v->views;
                $viewsFmt = $views >= 1_000_000
                    ? number_format($views / 1_000_000, 1) . 'M'
                    : ($views >= 1_000 ? number_format($views / 1_000, 0) . 'K' : (string) $views);
                return [
                    'id'        => $v->id,
                    'title'     => $v->detected_product_title ?? 'Video viral TikTok Shop',
                    'thumbnail' => $thumb,
                    'hook'      => $hook,
                    'views'     => $views,
                    'views_fmt' => $viewsFmt,
                    'shop'      => $v->creator_name ?? $v->creator_handle ?? 'TikTok Shop',
                    'duration'  => 12,
                    // SEL-434: false = nao tem relacao com o produto do cliente
                    'relacionado' => in_array($v->id, $relacionadosIds, true),
                ];
            })->values()->all();

            // Salva virais no contexto da conversa para o turno 2
            $ctx['viral_suggestions'] = $virals;
            $conv->update(['context' => $ctx]);

            \Illuminate\Support\Facades\Log::info('[StudioChat] toolSuggestViralCarousel', [
                'product_name' => $productName,
                'count'        => count($virals),
            ]);

            $qtdRelacionados = count(array_filter($virals, fn ($v) => $v['relacionado'] ?? false));
            $recado = $qtdRelacionados > 0
                ? "Carousel gerado: {$qtdRelacionados} viral(is) REALMENTE do mesmo tipo de produto."
                : 'ATENCAO: nao existe viral do mesmo tipo de produto na base. Os videos mostrados sao'
                  . ' inspiracao geral, de OUTROS produtos. Diga isso ao cliente com todas as letras e'
                  . ' ofereca criar do zero -- nunca apresente esses videos como se fossem do produto dele.';

            return [
                'virals'          => $virals,
                'related_count'   => $qtdRelacionados,
                'message'         => $recado,
                'ui_widget' => [
                    'type' => 'viral_carousel',
                    'data' => [
                        'virals' => $virals,
                        'product_name' => $productName,
                        'related_count' => $qtdRelacionados,
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[StudioChat] toolSuggestViralCarousel failed', ['err' => $e->getMessage()]);
            return [
                'virals'  => [],
                'message' => 'Nao consegui carregar virais agora. Cliente pode criar do zero.',
                'ui_widget' => ['type' => 'viral_carousel', 'data' => ['virals' => [], 'product_name' => $productName]],
            ];
        }
    }

    private function toolGenerateScript(array $args, StudioConversation $conv): array
    {
        // SEL-368: o modelo inventa duration_sec pra pular a pergunta — a duracao
        // vale quando veio do CLIENTE, validada por codigo no historico da conversa.
        $confirmed = $this->clientConfirmedDuration($conv);
        if (! $confirmed) {
            return [
                'error'   => 'duration_missing',
                'message' => 'O cliente ainda nao escolheu a duracao. PERGUNTE antes de gerar o roteiro: "Quanto tempo voce quer o video? ' . $this->durationsLabel() . '?"',
            ];
        }
        $args['duration_sec'] = $confirmed;
        // SEL-364d: clamp na duracao maxima permitida (admin controla no banco)
        $allowed = $this->allowedDurations();
        $maxDur  = max($allowed);
        if ((int) $args['duration_sec'] > $maxDur) {
            $args['duration_sec'] = $maxDur;
            $durationNote = "Duracao ajustada pra {$maxDur}s (maximo disponivel hoje). Avise o cliente disso na resposta.";
        }
        try {
            $result = $this->openai->generateScript(
                $args['product_desc'] ?? $args['product_name'],
                $args['product_name'],
                $args['tone'] ?? 'energetico',
                null, 600,
                $args['duration_sec'] ?? 10
            );
            // SEL-443 (30/07, Ruan: "selecionei lapis e gerou outro"). O roteiro
            // aprovado precisa sobreviver ao turno: sem isso o modelo mandava
            // qualquer texto curto como prompt e o Kling gerava outra coisa.
            // SEL-443b: o roteiro fica amarrado AO PRODUTO. Solto na conversa ele
            // vazava: cliente trocava de produto no meio e o showcase herdava o
            // roteiro do produto anterior -- a mesma familia de defeito que essa
            // base repetiu a noite toda.
            $ctxS = $conv->context ?? [];
            $ctxS['ultimo_roteiro'] = [
                'produto' => (string) ($args['product_name'] ?? ($ctxS['product_name'] ?? '')),
                'texto'   => (string) $result['script'],
            ];
            $conv->update(['context' => $ctxS]);

            $out = [
                'script'    => $result['script'],
                'ui_widget' => ['type' => 'script_preview', 'data' => ['script' => $result['script']]],
            ];
            if (!empty($durationNote)) $out['duration_note'] = $durationNote;
            return $out;
        } catch (Throwable $e) {
            return ['error' => $e->getMessage(), 'message' => 'Não consegui gerar o roteiro. Tenta descrever o produto?'];
        }
    }

    /** SEL-368: duracao so conta se o CLIENTE escreveu (ex.: "15s", "15 segundos", "1 minuto" ou so o numero). */
    private function clientConfirmedDuration(StudioConversation $conv): ?int
    {
        $texts = StudioMessage::where('conversation_id', $conv->id)
            ->where('role', 'user')->orderByDesc('id')->limit(20)->pluck('content');
        foreach ($texts as $t) {
            if (! is_string($t) || $t === '') continue;
            if (preg_match('/\b(\d{1,3})\s*(?:s\b|seg\b|segs\b|segundos?\b)/iu', $t, $m)) {
                $v = (int) $m[1];
                if ($v >= 3 && $v <= 120) return $v;
            }
            if (preg_match('/\b([1-9])\s*(?:min\b|mins\b|minutos?\b)/iu', $t, $m)) {
                return ((int) $m[1]) * 60;
            }
            if (preg_match('/^\s*(\d{1,3})\s*$/', $t, $m)) {
                $v = (int) $m[1];
                if ($v >= 3 && $v <= 120) return $v;
            }
        }
        return null;
    }

    private function toolExecuteGeneration(array $args, StudioConversation $conv, $user): array
    {
        $pipeline  = $args['pipeline'] ?? 'animar_produto';
        $gear      = $args['gear'] ?? 'recomendado';
        // SEL-387: showcase_kaloclip sempre 12s + 9:16 + audio (padrao Kaloclip)
        if ($pipeline === 'showcase_kaloclip') {
            $args['duration_sec'] = 12;
            $args['aspect_ratio'] = '9:16';
            $args['native_audio'] = true;
            // SEL-389 PLANO B: sempre carregar imagem do produto pra image2video Kaloclip
            if (empty($args['image_url'])) {
                [$catCover, $catRefs] = $this->resolveCatalogImage($conv, $user);
                if ($catCover) $args['image_url'] = $catCover;
                if (empty($args['image_refs']) && !empty($catRefs)) $args['image_refs'] = $catRefs;
            }
            // Se prompt vazio, gera via KaloclipStyleScriptService (10 seções)
            // SEL-443: o modelo mandava como prompt a DESCRICAO que o cliente
            // digitou (ex.: "Receitas rapidas e faceis" pra uma caneta de
            // sobrancelha) em vez do roteiro que ele mesmo acabara de gerar e o
            // cliente aprovou. Tres palavras nao sao um roteiro: quando existe
            // roteiro aprovado na conversa, ele manda.
            // SEL-443b: so vale o roteiro do produto que esta sendo gerado agora.
            $guardado = ($conv->context ?? [])['ultimo_roteiro'] ?? null;
            $roteiroAprovado = '';
            if (is_array($guardado)) {
                $produtoAgora = trim((string) ($args['product_name'] ?? (($conv->context ?? [])['product_name'] ?? '')));
                $produtoDoRoteiro = trim((string) ($guardado['produto'] ?? ''));
                $mesmoProduto = $produtoDoRoteiro === '' || $produtoAgora === '' 
                    || mb_strtolower($produtoDoRoteiro) === mb_strtolower($produtoAgora);
                if ($mesmoProduto) {
                    $roteiroAprovado = (string) ($guardado['texto'] ?? '');
                } else {
                    Log::info('[SEL-443b] roteiro guardado e de outro produto -- ignorado', [
                        'conv' => $conv->id, 'guardado' => $produtoDoRoteiro, 'agora' => $produtoAgora,
                    ]);
                }
            } elseif (is_string($guardado)) {
                $roteiroAprovado = $guardado;   // formato antigo, conversas em andamento
            }
            $promptRecebido  = trim((string) ($args['prompt'] ?? ''));
            $palavrasPrompt  = $promptRecebido === '' ? 0 : count(preg_split('/\s+/u', $promptRecebido, -1, PREG_SPLIT_NO_EMPTY));
            if ($roteiroAprovado !== '' && $palavrasPrompt < 25) {
                Log::warning('[SEL-443] prompt curto trocado pelo roteiro aprovado', [
                    'conv' => $conv->id, 'prompt_recebido' => mb_substr($promptRecebido, 0, 80), 'palavras' => $palavrasPrompt,
                ]);
                $args['prompt'] = $roteiroAprovado;
            }

            if (empty($args['prompt'])) {
                try {
                    $productName = $args['product_name'] ?? ($conv->context['product_name'] ?? 'Produto');
                    $productDesc = $args['product_desc'] ?? '';

                    // SEL-387 F4: sem descricao real, a IA inventa como o produto funciona.
                    // Ruan, 29/07: num video a apresentadora apertava um controle remoto e o
                    // ventilador ligava — o produto nao tem controle. A causa era esta linha,
                    // que caia no NOME do produto como descricao: a IA lia
                    // "Ventilador USB 3 Velocidades" duas vezes e preenchia o resto no chute.
                    // O catalogo TEM descricao boa (557 de 645 produtos, media 1.176 chars) —
                    // o PromptPreviewController ja buscava; o chat, nao.
                    $productCategory = $args['product_category'] ?? 'geral';
                    $productPrice    = $args['product_price'] ?? null;
                    $pid = $args['product_id'] ?? ($conv->context['product_id'] ?? null);
                    if ($pid) {
                        try {
                            $cp = \DB::table('client_products')
                                ->leftJoin('products', 'products.id', '=', 'client_products.product_id')
                                ->where('client_products.id', $pid)
                                ->select([
                                    \DB::raw('COALESCE(client_products.custom_title, products.name) as name'),
                                    \DB::raw('COALESCE(client_products.custom_price, products.price) as price'),
                                    // ATENCAO: a coluna e category_id, nao category. O
                                    // PromptPreviewController usa 'products.category' e por isso
                                    // a query dele estoura e cai no catch — a busca de descricao
                                    // dele nunca funcionou de verdade.
                                    'products.category_id',
                                    'products.description',
                                    'products.ai_description',
                                ])->first();
                            if ($cp) {
                                $productName     = $productName !== 'Produto' ? $productName : ($cp->name ?: $productName);
                                $productDesc     = $productDesc ?: (string) ($cp->description ?: $cp->ai_description ?: '');
                                $productCategory = $productCategory !== 'geral' ? $productCategory : ('cat_' . ($cp->category_id ?? 'geral'));
                                $productPrice    = $productPrice ?: $cp->price;
                            }
                        } catch (\Throwable $e) {
                            \Log::warning('[SEL-387 F4] falha ao buscar descricao do produto', ['pid' => $pid, 'err' => $e->getMessage()]);
                        }
                    }
                    // Sem descricao real: avisa o roteirista pra NAO encenar uso do produto.
                    if (trim($productDesc) === '') {
                        $productDesc = 'SEM DESCRICAO DISPONIVEL. Nao encene nenhum uso ou acionamento do produto — '
                                     . 'apenas mostre o produto (na mao, girando, em close, na embalagem).';
                    }
                    // SEL-421: resolve idioma e voz ANTES de escrever o roteiro —
                    // e o mesmo par que o despacharGeracao() vai usar depois.
                    $langDaGeracao = \App\Services\Ai\VideoLanguageService::resolver($user->id, $args['lang'] ?? null);
                    $vozDaGeracao  = \App\Services\Ai\VideoLanguageService::exigeNarracaoExterna($langDaGeracao)
                        ? (config('services.elevenlabs.cloned_voice_id') ?: 'tuFazJVCwiszby0YDkFk')
                        : null;
                    $kaloSvc = app(\App\Services\Ai\KaloclipStyleScriptService::class);
                    $kaloScript = $kaloSvc->generate([
                        'name'        => $productName,
                        'description' => $productDesc,
                        'category'    => $productCategory,
                        'price'       => $productPrice,
                    ], 12, 'energetico', [
                        // SEL-421: o roteiro tem que ser dimensionado pelo ritmo de
                        // QUEM VAI LOCUTAR. Em pt-BR quem loculta e a voz clonada
                        // (3,35 pal/s), nao o audio nativo do Kling (2,2 pal/s) —
                        // dimensionar pelo Kling deixava 4,88s de silencio no fim.
                        'lang'     => $langDaGeracao,
                        'voice_id' => $vozDaGeracao,
                        // SEL-429: a ideia que o cliente digitou vai LITERAL pro roteirista.
                        'client_brief' => $this->briefLiteralDoCliente($conv, $args['client_brief'] ?? null),
                    ]);
                    $args['prompt'] = $kaloSvc->toKlingPrompt($kaloScript);
                    \Log::info('[SEL-387] Kaloclip prompt gerado', ['len' => strlen($args['prompt'] ?? ''), 'product' => $productName]);
                } catch (\Throwable $e) {
                    // Fallback: prompt genérico Kaloclip-style
                    $args['prompt'] = 'Video vertical 9:16, 12 segundos, 3 cortes dinamicos. Produto: ' . ($args['product_name'] ?? 'produto para loja online') . '. Estilo UGC brasileiro, portugues brasileiro nativo, apresentadora feminina jovem, iluminacao natural, cortes rapidos a cada 4 segundos, hook nos primeiros 1.5s, beneficios claros, CTA final. Audio diegetico ambiente. Sem legendas.';
                    \Log::warning('[SEL-387] Kaloclip fallback prompt', ['err' => $e->getMessage()]);
                }
            }
            // Usa video_do_zero como base (text2video com prompt Kaloclip)
            $pipeline = 'video_do_zero';
            $args['pipeline'] = 'video_do_zero';
        }
        $duration  = (int) ($args['duration_sec'] ?? 10);

        // SEL-364d: clamp na duracao maxima do banco (admin controla)
        $maxDur = max($this->allowedDurations());
        if ($duration > $maxDur) $duration = $maxDur;
        // SEL-443: o cliente anexou a foto do produto na PRIMEIRA mensagem e ela
        // nunca chegava aqui -- so se olhava args, analyzed_image e catalogo.
        // Resultado medido no pipeline 259: image_url=null e image_refs=[], ou
        // seja, video gerado as cegas, sem nenhuma referencia do produto.
        $imageUrl  = $args['image_url']
            ?? ($conv->context['analyzed_image'] ?? null)
            ?? ($conv->context['product_image'] ?? null)
            ?? $this->anexosDaConversa($conv)[0] ?? null;
        $imageRefs = $args['image_refs'] ?? [];

        // SEL-364: modelo alucina URLs (ex.: example.com) — só confia em URL que
        // seja anexo real da conversa ou a imagem já analisada. Alucinou? Usa a real.
        $realUrls = StudioMessage::where('conversation_id', $conv->id)
            ->where('role', 'user')
            ->whereNotNull('attachments')
            ->orderByDesc('id')
            ->pluck('attachments')
            ->flatMap(fn ($a) => is_string($a) ? (array) json_decode($a, true) : (array) $a)
            ->filter(fn ($u) => is_string($u) && $u !== '')
            ->values();
        $analyzed = $conv->context['analyzed_image'] ?? null;
        $trusted  = $analyzed ? $realUrls->concat([$analyzed]) : $realUrls;
        $ownHost = $imageUrl && str_starts_with($imageUrl, 'https://api.seller.global/');
        if ($imageUrl && ! $ownHost && ! $trusted->contains($imageUrl)) {
            $imageUrl = $analyzed
                ?: $realUrls->first(fn ($u) => preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $u));
            if (! $imageUrl) {
                [$imageUrl, $catalogRefs] = $this->resolveCatalogImage($conv, $user);
                if (empty($imageRefs) && ! empty($catalogRefs)) {
                    $imageRefs = $catalogRefs;
                }
            }
        }
        if (!empty($imageRefs) && $trusted->isNotEmpty()) {
            $imageRefs = array_values(array_filter((array) $imageRefs, fn ($u) => $trusted->contains($u)));
        }
        // Máximo de referências automático: cliente mandou várias fotos, usa todas.
        if (empty($imageRefs) && $realUrls->count() > 1) {
            $imageRefs = $realUrls
                ->filter(fn ($u) => preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $u))
                ->reject(fn ($u) => $u === $imageUrl)
                ->take(6)
                ->values()
                ->all();
        }
        // SEL-368: ainda sem refs? Produto identificavel no catalogo entrega
        // galeria/reviews/variantes sozinho via ProductReferenceCollector.
        if (empty($imageRefs)) {
            [$catCover, $catRefs] = $this->resolveCatalogImage($conv, $user);
            if (! empty($catRefs)) $imageRefs = $catRefs;
            if (! $imageUrl && $catCover) $imageUrl = $catCover;
        }
        $prompt    = $args['prompt'] ?? null;
        $ratio     = $args['aspect_ratio'] ?? '9:16';
        $genHand   = (bool) ($args['generate_hand_image'] ?? false);

        if (!$imageUrl && !in_array($pipeline, ['video_do_zero', 'voz_brasileira'])) {
            return ['error' => 'image_required', 'message' => 'Preciso de uma foto do produto pra gerar o vídeo!'];
        }

        $gearConfig = self::GEAR_MODEL[$gear] ?? self::GEAR_MODEL['recomendado'];

        try {
            $pipelineModel = AiVideoPipeline::create([
                'user_id'     => $user->id,
                'mode'        => 'studio_' . $pipeline,
                // SEL-461 (31/07): o product_key era md5 da FOTO -- um hash da imagem,
                // nao a identidade do produto. Resultado medido: a juncao com o
                // produto real casava em 0 de 82, e a conversa guardava product_id
                // em 1 de 246 casos. Sem identidade, o selo do video nao consegue
                // saber preco, nota nem vendas do produto -- por isso 83% dos videos
                // so destravavam 6 de 21 modelos.
                // Agora vale o ID de verdade quando ele existe; o md5 da foto vira
                // ultimo recurso, e continua servindo de chave pra quem subiu foto
                // solta sem produto nenhum atras.
                'product_key' => $this->chaveDoProduto($conv, $args, $imageUrl),
                'step'        => 'queued',
                'payloads'    => [
                    'pipeline'          => $pipeline,
                    'gear'              => $gear,
                    'gear_model'        => $gearConfig['model'],
                    'gear_mode'         => $gearConfig['mode'],
                    'duration'          => $duration,
                    'image_url'         => $imageUrl,
                    'image_refs'        => $imageRefs,
                    'prompt'            => $prompt,
                    'aspect_ratio'      => $ratio,
                    'generate_hand'     => $genHand,
                    'avatar_url'        => $args['avatar_url'] ?? null,
                    'camera_preset'     => $args['camera_preset'] ?? 'zoom_in',
                    'face_url'          => $args['face_url'] ?? null,
                    'target_video_url'  => $args['target_video_url'] ?? null,
                    'person_url'        => $args['person_url'] ?? null,
                    'cloth_url'         => $args['cloth_url'] ?? null,
                    'source_video_url'  => $args['source_video_url'] ?? null,
                    'audio_url'         => $args['audio_url'] ?? null,
                    'tts_text'          => $args['tts_text'] ?? null,
                    'effect_key'        => $args['effect_key'] ?? 'unbox_2026',
                    'voice'             => $args['voice'] ?? 'nova',
                    'conv_id'           => $conv->id,
                    'studio_mode'       => true,
                    // SEL-CONVITE: marca render do trial -> KlingBrowserService::resolveQueue
                    // manda pra fila mais baixa (external-video-low). Protege o pagante.
                    '_trial'            => \App\Services\InviteTrialService::isTrialActive($user->id),
                ],
                'dry_run' => (bool) config('services.ai_video.dry_run', false),
            ]);

            $conv->update(['status' => 'generating']);
            $ctx = $conv->context ?? [];
            $ctx['last_pipeline_id'] = $pipelineModel->id;
            $conv->update(['context' => $ctx]);

            // Fase 1: despachar StudioGenerationJob para os 3 pipelines suportados
            // SEL-418: o idioma decide o job. pt-BR (so admin) vai pro caminho
            // com ElevenLabs + lipsync; o resto segue no Studio como sempre.
            $this->despacharGeracao($pipelineModel, $user->id, $args['lang'] ?? null);

            $estSec = $duration * 6 + 30;

            return [
                'pipeline_id'   => $pipelineModel->id,
                'status'        => 'queued',
                'estimated_sec' => $estSec,
                'poll_url'      => url("/api/v1/studio-chat/generation/{$pipelineModel->id}/progress"),
                'ui_widget'     => [
                    'type' => 'generation_started',
                    'data' => [
                        'pipeline_id'   => $pipelineModel->id,
                        'pipeline_name' => $pipeline,
                        'gear'          => $gear,
                        'estimated_sec' => $estSec,
                        'poll_url'      => url("/api/v1/studio-chat/generation/{$pipelineModel->id}/progress"),
                    ],
                ],
            ];
        } catch (Throwable $e) {
            Log::error('[SEL-360] execute_generation failed', ['err' => $e->getMessage()]);
            return ['error' => $e->getMessage(), 'message' => 'Poxa, deu um erro ao iniciar a geração. Tenta de novo?'];
        }
    }

    /**
     * POST /api/v1/studio-chat/upload
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:51200|mimes:jpeg,jpg,png,webp,gif,mp4,mov,mp3,wav,m4a',
        ]);

        $file = $request->file('file');
        $ext  = $file->getClientOriginalExtension();
        $key  = 'studio/' . now()->format('Ymd') . '/' . Str::uuid() . '.' . $ext;

        Storage::disk('public')->put($key, file_get_contents($file->getRealPath()));
        $url = Storage::disk('public')->url($key);

        return response()->json(['url' => $url, 'key' => $key, 'mime' => $file->getMimeType()]);
    }

    /**
     * GET /api/v1/studio-chat/generation/{id}/progress (SSE)
     */
    public function progress(Request $request, int $id)
    {
        $pipeline = AiVideoPipeline::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->stream(function () use ($pipeline) {
            $maxPoll = 100;
            for ($i = 0; $i < $maxPoll; $i++) {
                $pipeline->refresh();
                $step = $pipeline->step;
                $pct  = $this->stepToPercent($step);

                $this->sseEvent('progress', [
                    'step'       => $step,
                    'pct'        => $pct,
                    'label'      => $this->stepLabel($step),
                    'output_url' => $pipeline->output_url,
                    'error'      => $pipeline->error_message,
                ]);

                if (in_array($step, ['done', 'failed'])) {
                    $this->sseEvent('done', [
                        'pipeline_id' => $pipeline->id,
                        'status'      => $step,
                        'output_url'  => $pipeline->output_url,
                        'error'       => $pipeline->error_message,
                    ]);
                    echo "data: [DONE]\n\n";
                    ob_flush(); flush();
                    return;
                }

                sleep(3);
                ob_flush(); flush();
                if (connection_aborted()) return;
            }

            $this->sseEvent('timeout', ['message' => 'Geração está demorando mais que o esperado. Verifique a Galeria em alguns minutos.']);
            echo "data: [DONE]\n\n";
            ob_flush(); flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * POST /api/v1/studio-chat/tts
     */
    public function tts(Request $request)
    {
        $v = $request->validate([
            'message_id' => 'required|integer',
            'text'       => 'required|string|max:1000',
            'voice'      => 'nullable|string|in:nova,shimmer,alloy,echo,fable,onyx',
        ]);

        $msg = StudioMessage::where('id', $v['message_id'])
            ->whereHas('conversation', fn($q) => $q->where('user_id', $request->user()->id))
            ->firstOrFail();

        if ($msg->tts_url) {
            return response()->json(['url' => $msg->tts_url]);
        }

        try {
            $audio = $this->openai->tts($v['text'], $v['voice'] ?? 'nova');
            $key   = 'studio/tts/' . now()->format('Ymd') . '/' . $v['message_id'] . '.mp3';
            $binary = is_array($audio) && !empty($audio["audio_base64"]) ? base64_decode($audio["audio_base64"]) : (is_string($audio) ? $audio : "");
            Storage::disk("public")->put($key, $binary);
            $url   = Storage::disk('public')->url($key);
            $msg->update(['tts_url' => $url]);

            return response()->json(['url' => $url]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'tts_failed', 'message' => 'Não foi possível gerar o áudio.'], 500);
        }
    }

    /**
     * GET /api/v1/studio-chat/conversations
     */
    public function conversations(Request $request)
    {
        $convs = StudioConversation::where('user_id', $request->user()->id)
            ->with(['messages' => fn($q) => $q->orderBy('id')->limit(1)])
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn($c) => [
                'id'         => $c->uuid,
                'status'     => $c->status,
                'created_at' => $c->created_at,
                'preview'    => $c->messages->first()?->content ?? '',
            ]);

        return response()->json(['conversations' => $convs]);
    }


    /**
     * POST /api/v1/studio-chat/custom-prompt
     *
     * SEL-361 Fase E — Modo Prompt Livre.
     * Permite que power users escrevam o prompt Kling diretamente,
     * bypassando o PromptMaster e o Studio Chat conversacional.
     */
    public function customPrompt(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate([
            'prompt'          => 'required|string|max:2000',
            'gear'            => 'nullable|string|in:rapido,recomendado,cinema,ultra',
            'duration_sec'    => 'nullable|integer|in:5,10,15',
            'aspect_ratio'    => 'nullable|string|in:9:16,16:9,1:1',
            'image_url'       => 'nullable|string|max:2048',
            'negative_prompt' => 'nullable|string|max:1000',
        ]);

        $prompt         = $validated['prompt'];
        $gear           = $validated['gear']            ?? 'recomendado';
        $durationSec    = $validated['duration_sec']    ?? 5;
        $aspectRatio    = $validated['aspect_ratio']    ?? '9:16';
        $imageUrl       = $validated['image_url']       ?? null;
        $negativePrompt = $validated['negative_prompt'] ?? null;

        $moderator = app(\App\Services\ContentModerationService::class);
        $modResult  = $moderator->moderate($prompt);
        if ($modResult['flagged']) {
            return response()->json([
                'error'   => 'prompt_blocked',
                'message' => $modResult['reason'],
                'matched' => $modResult['matched'],
            ], 422);
        }

        $user    = $request->user();
        $gearCfg = self::GEAR_MODEL[$gear] ?? self::GEAR_MODEL['recomendado'];
        $model   = $gearCfg['model'];
        $mode    = $gearCfg['mode'];

        $pipeline = \App\Models\AiVideoPipeline::create([
            'user_id'     => $user->id,
            'mode'        => 'custom',
            'product_key' => 'custom_prompt_' . $user->id,
            'step'        => 'queued',
            // SEL-364b: payload no formato que o StudioGenerationJob le (chaves no
            // topo, sem json_encode — o model ja casta array; dupla codificacao
            // deixava o job cego -> defaults + imagem null -> Kling 1201).
            'payloads'    => [
                'pipeline'        => $imageUrl ? 'animar_produto' : 'video_do_zero',
                'prompt'          => $prompt,
                'negative_prompt' => $negativePrompt,
                'gear'            => $gear,
                'gear_model'      => $model,
                'gear_mode'       => $mode,
                'duration'        => $durationSec,
                'aspect_ratio'    => $aspectRatio,
                'image_url'       => $imageUrl,
                'custom_mode'     => true,
            ],
            'dry_run'     => (bool) config('services.ai_video.dry_run', false),
        ]);

        \App\Models\CustomPromptHistory::create([
            'user_id'         => $user->id,
            'pipeline_id'     => $pipeline->id,
            'prompt'          => $prompt,
            'gear'            => $gear,
            'duration_sec'    => $durationSec,
            'aspect_ratio'    => $aspectRatio,
            'model'           => $model,
            'image_url'       => $imageUrl,
            'negative_prompt' => $negativePrompt,
        ]);

        // SEL-418: mesmo roteamento por idioma no caminho do prompt customizado.
        $this->despacharGeracao($pipeline, $user->id, $request->input('lang'));

        $etaSec = $durationSec * 6 + 30;

        \Illuminate\Support\Facades\Log::info('[SEL-361E] custom-prompt dispatched', [
            'user_id'     => $user->id,
            'pipeline_id' => $pipeline->id,
            'gear'        => $gear,
            'duration'    => $durationSec,
        ]);

        return response()->json([
            'pipeline_id' => $pipeline->id,
            'eta_seconds' => $etaSec,
            'poll_url'    => url("/api/v1/studio-chat/generation/{$pipeline->id}/progress"),
        ]);
    }

    /**
     * GET /api/v1/studio-chat/custom-prompt/history
     */
    public function customPromptHistory(\Illuminate\Http\Request $request)
    {
        $user    = $request->user();
        $history = \App\Models\CustomPromptHistory::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'prompt', 'gear', 'duration_sec', 'aspect_ratio', 'created_at']);

        return response()->json(['history' => $history]);
    }

    /**
     * POST /api/v1/studio-chat/improve-prompt
     *
     * SEL-361 Fase E — Prompt Assistant opcional.
     */
    public function improvePrompt(\Illuminate\Http\Request $request)
    {
        $request->validate(['prompt' => 'required|string|max:2000']);
        $originalPrompt = $request->input('prompt');

        $moderator = app(\App\Services\ContentModerationService::class);
        $modResult  = $moderator->moderate($originalPrompt);
        if ($modResult['flagged']) {
            return response()->json([
                'error'   => 'prompt_blocked',
                'message' => $modResult['reason'],
            ], 422);
        }

        try {
            $openai    = app(\App\Services\Ai\OpenAiService::class);
            $systemMsg = 'Voce e um especialista em prompts para geracao de video com IA. '
                . 'Melhore o prompt a seguir para gerar videos mais virais no TikTok Shop. '
                . 'Mantenha a intencao original. Adicione: angulo de camera, iluminacao, '
                . 'movimento de camera, atmosfera visual. Maximo 300 palavras. '
                . 'Responda APENAS com o prompt melhorado, sem explicacoes.';

            $improved = $openai->chat(
                messages: [
                    ['role' => 'system', 'content' => $systemMsg],
                    ['role' => 'user',   'content' => $originalPrompt],
                ],
                model: 'gpt-4o-mini',
                maxTokens: 400,
            );

            return response()->json([
                'original' => $originalPrompt,
                'improved' => trim($improved),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[SEL-361E] improve-prompt failed', ['err' => $e->getMessage()]);
            return response()->json(['error' => 'improve_failed', 'message' => 'Nao foi possivel melhorar o prompt agora.'], 500);
        }
    }


    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * SEL-429 — 2 a 4 respostas curtas pra pergunta que o Studio acabou de fazer.
     * Geradas no backend com o contexto da conversa; falha ou vazio = sem botao.
     */
    /**
     * SEL-432 — duracoes (em segundos) citadas num texto. Usado pra impedir que
     * um botao de sugestao ofereca duracao que a pergunta nao ofereceu.
     */
    private function duracoesCitadas(string $texto): array
    {
        $achados = [];
        if (preg_match_all('/(\d{1,3})\s*(?:s\b|seg\b|segundos?\b)/iu', $texto, $m)) {
            foreach ($m[1] as $n) {
                $n = (int) $n;
                if ($n > 0 && $n <= 300) $achados[] = $n;
            }
        }
        return array_values(array_unique($achados));
    }

    /**
     * SEL-433 (30/07, Ruan: "as perguntas tem que ser baseadas no produto que
     * foi selecionado"). product_name estava NULL em TODAS as conversas: o nome
     * chegava nas tools e era descartado -- so viral_suggestions era salvo.
     * Sem isso a pergunta sai generica e a foto de referencia pode nao ser a do
     * produto escolhido. Grava nome e foto assim que qualquer ponto do fluxo os
     * conhece; nunca sobrescreve com vazio.
     */
    private function lembrarProduto(StudioConversation $conv, ?string $nome, ?string $imagem = null): void
    {
        $nome   = is_string($nome) ? trim($nome) : '';
        $imagem = is_string($imagem) ? trim($imagem) : '';
        if ($nome === '' && $imagem === '') return;

        $ctx = $conv->context ?? [];
        $mudou = false;

        if ($nome !== '' && ($ctx['product_name'] ?? '') !== $nome) {
            $ctx['product_name'] = $nome;
            $mudou = true;
        }
        if ($imagem !== '' && ($ctx['product_image'] ?? '') !== $imagem) {
            $ctx['product_image'] = $imagem;
            $mudou = true;
        }
        if ($mudou) {
            $conv->update(['context' => $ctx]);
            Log::info('[SEL-433] produto lembrado na conversa', [
                'conv' => $conv->id, 'produto' => $ctx['product_name'] ?? null,
            ]);
        }
    }

    /**
     * SEL-440 — conducao por inicio/meio/fim assim que o produto e conhecido.
     */
    private function regraEstruturaDoVideo(StudioConversation $conv): ?string
    {
        $ctx = $conv->context ?? [];
        $produto = $ctx['product_name'] ?? ($ctx['product_info']['product_name'] ?? null);
        if (empty($produto)) return null;

        return "PRODUTO ESCOLHIDO: {$produto}.\n"
            . "CONDUZA O CLIENTE PELAS TRES PARTES DO VIDEO, UMA PERGUNTA DE CADA VEZ, NESTA ORDEM:\n"
            . "1) COMO COMECA (os primeiros 2 segundos): que gancho prende quem esta rolando o feed.\n"
            . "2) O QUE MOSTRA NO MEIO: qual demonstracao do produto convence.\n"
            . "3) COMO TERMINA: qual chamada leva a pessoa pro carrinho.\n"
            . "REGRAS DURAS:\n"
            . "- Uma pergunta por vez. Nao junte as tres numa mensagem so.\n"
            . "- Cada pergunta tem que citar {$produto} e as opcoes tem que servir SO pra ele. "
            . "Opcao que caberia em qualquer produto esta errada -- refaca pensando no que esse produto faz.\n"
            . "- O cliente pode escrever a ideia dele em vez de escolher. Se escrever, a ideia dele e LEI: "
            . "use as palavras dele, nao reescreva.\n"
            . "- PERGUNTE o MOVIMENTO DE CAMERA, com estas opcoes em portugues (SEL-450, pedido do Ruan):\n"
            . "  Zoom no produto / Passar pra esquerda / Passar pra direita / Avancar de frente /\n"
            . "  Avanco suave / Girar em volta do produto. Traduza a escolha assim ao chamar a tool:\n"
            . "  zoom -> zoom_in, esquerda -> pan_left, direita -> pan_right, frente e avanco suave -> zoom_in,\n"
            . "  girar -> orbit_left. Se ele nao escolher, use zoom_in.\n"
            . "- PADRAO DO PRODUTO (nao negociavel): movimento sempre no maximo que der e aproximacao no\n"
            . "  produto. O produto e a estrela do video -- nao o avatar, nao o cenario, nao o texto.\n"
            . "- NUNCA pergunte cenario, ambientacao, iluminacao, estilo visual, trilha ou cor. Isso e\n"
            . "  decisao nossa: escolha o que converte pra esse produto e siga. Perguntar deixa o cliente\n"
            . "  perdido. Movimento de camera e a UNICA coisa visual que se pergunta.\n"
            . "- Ja tiver as tres respostas? Nao pergunte de novo: monte o roteiro e siga pra geracao.";
    }

    /**
     * SEL-443 — todas as fotos que o cliente anexou nesta conversa, na ordem em
     * que chegaram. Fonte de verdade quando nada mais resolveu a imagem.
     */
    /**
     * SEL-461 — identidade do produto pro pipeline, na ordem do que PROVA mais.
     * Nunca por titulo: casar por nome ja fez "Jogo de Panelas" virar qualquer
     * panela nesta base, e foi removido em 30/07.
     */
    private function chaveDoProduto(StudioConversation $conv, array $args, ?string $imageUrl): string
    {
        $ctx = $conv->context ?? [];

        // 1. id do produto TikTok Shop, quando a conversa ou a tool trouxe
        foreach (['product_key', 'product_id', 'external_id'] as $campo) {
            $v = trim((string) ($args[$campo] ?? ($ctx[$campo] ?? '')));
            if ($v !== '' && $v !== '0') {
                Log::info('[SEL-461] product_key resolvido por id', ['campo' => $campo, 'valor' => $v]);
                return $v;
            }
        }

        // 2. id do produto do catalogo do cliente
        $cp = trim((string) ($args['client_product_id'] ?? ($ctx['client_product_id'] ?? '')));
        if ($cp !== '' && $cp !== '0') {
            Log::info('[SEL-461] product_key resolvido por client_product_id', ['valor' => $cp]);
            return 'cp_' . $cp;
        }

        // 3. ultimo recurso: hash da foto. Serve pra quem subiu foto solta, sem
        //    produto atras -- nao e identidade, e so um agrupador estavel.
        if ($imageUrl) {
            Log::info('[SEL-461] product_key caiu no hash da foto (sem id de produto)', [
                'conv' => $conv->id,
            ]);
            return md5($imageUrl);
        }

        return (string) Str::uuid();
    }

    private function anexosDaConversa(StudioConversation $conv): array
    {
        try {
            return StudioMessage::where('conversation_id', $conv->id)
                ->where('role', 'user')
                ->whereNotNull('attachments')
                ->orderBy('id')
                ->pluck('attachments')
                ->flatMap(fn ($a) => is_string($a) ? (array) json_decode($a, true) : (array) $a)
                ->filter(fn ($u) => is_string($u) && preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $u))
                ->unique()->values()->all();
        } catch (Throwable $e) {
            Log::warning('[SEL-443] falha ao ler anexos', ['err' => $e->getMessage()]);
            return [];
        }
    }

    private function gerarSugestoes(string $answerText, StudioConversation $conv): array
    {
        $answerText = trim($answerText);
        if ($answerText === '' || ! str_contains($answerText, '?')) return [];

        try {
            $ctxConv = $conv->context ?? [];
            $produto = $ctxConv['product_name']
                ?? ($ctxConv['product_info']['product_name'] ?? null);
            $ultima  = (string) (StudioMessage::where('conversation_id', $conv->id)
                ->where('role', 'user')->orderByDesc('id')->value('content') ?? '');

            $sys = "Voce gera botoes de resposta rapida num chat de criacao de video pra TikTok Shop.\n"
                 . "Devolva JSON {\"chips\":[\"...\"]} com 2 a 4 RESPOSTAS que o CLIENTE daria pra pergunta do Studio.\n"
                 . "REGRAS DURAS:\n"
                 . "- portugues brasileiro, informal, no maximo 40 caracteres cada\n"
                 . "- sao RESPOSTAS/escolhas do cliente, nunca perguntas\n"
                 . "- as opcoes tem que ser DIFERENTES entre si: nunca repita a mesma resposta com outras palavras\n"
                 . "- especificas do produto e da pergunta; nada de \"sim\", \"ok\", \"quero\", \"tanto faz\"\n"
                 . "- quando o produto for conhecido, as opcoes tem que refletir ELE (uso, publico,\n"
                 . "  beneficio, cenario de uso) -- opcao que serviria pra qualquer produto nao vale\n"
                 . "- se a pergunta for sobre COMO COMECA o video, as opcoes sao gancho de abertura;\n"
                 . "  sobre o MEIO, sao demonstracoes do produto; sobre o FIM, sao chamadas pro carrinho\n"
                 . "- NUNCA cite preco, credito, R\$ ou valor de nada\n"
                 . "- sem emoji, sem aspas dentro do texto\n"
                 . "- se a pergunta ja lista as opcoes (ex: 5s ou 10s?), as respostas so podem ESCOLHER\n"
                 . "  entre essas opcoes; NUNCA invente uma terceira que o sistema nao oferece\n"
                 . "- se a mensagem nao for uma pergunta ao cliente, devolva {\"chips\":[]}";
            $usr = "Produto: " . ($produto ?: 'nao informado') . "\n"
                 . "Ultima mensagem do cliente: " . mb_substr($ultima, 0, 400) . "\n"
                 . "Mensagem do Studio (a pergunta): " . mb_substr($answerText, 0, 800);

            $decoded = $this->openai->chatJson([
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $usr],
            ], 'gpt-4o-mini', 250, 0.5);

            $raw = $decoded['chips'] ?? (is_array($decoded) ? $decoded : []);
            if (! is_array($raw)) return [];

            // SEL-432 (30/07): a pergunta oferecia "5s ou 10s?" e as sugestoes
            // ofereciam 15s e 20s -- duracao que o motor nao gera. O cliente
            // clicava e recebia outra coisa, calado. Instrucao no prompt nao
            // basta: quando a pergunta LISTA duracoes, o codigo descarta chip
            // que cite qualquer duracao fora dessa lista.
            $duracoesDaPergunta = $this->duracoesCitadas($answerText);

            $out = [];
            foreach ($raw as $c) {
                if (! is_string($c)) continue;
                $c = trim(preg_replace('/\s+/u', ' ', $c));
                if ($c === '' || mb_strlen($c) > 60) continue;
                if (! empty($duracoesDaPergunta)) {
                    $doChip = $this->duracoesCitadas($c);
                    $forasteiras = array_diff($doChip, $duracoesDaPergunta);
                    if (! empty($forasteiras)) {
                        Log::info('[SEL-432] chip descartado: duracao nao oferecida', [
                            'chip' => $c, 'pergunta_oferece' => $duracoesDaPergunta, 'chip_pede' => $doChip,
                        ]);
                        continue;
                    }
                }
                $out[] = $c;
                if (count($out) >= 4) break;
            }
            return count($out) >= 2 ? $out : [];
        } catch (Throwable $e) {
            Log::warning('[SEL-429] falha ao gerar sugestoes', ['err' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * SEL-429 — o texto do cliente e LEI. Ruan 30/07: "era para dar uma ideia
     * sobre o roteiro do produto, NAO mudar o roteiro". Antes o roteiro nascia
     * so do nome/descricao do catalogo: a ideia que o cliente digitou sumia.
     * Coletado por CODIGO do historico (nao pelo modelo) pra nao vir parafraseado.
     */
    private function briefLiteralDoCliente(StudioConversation $conv, ?string $override = null): string
    {
        $override = trim((string) $override);
        if ($override !== '') return mb_substr($override, 0, 900);

        $ruido = '/^\s*(sim|nao|n\x{00E3}o|ok|okay|beleza|blz|bora|gera|gerar|gerar agora|pode gerar|manda|aprovo|aprovado|vamos|top|show|isso|perfeito|\d{1,3}\s*(s|seg|segs|segundos?)?)\s*[!.\x{1F300}-\x{1FAFF}]*$/iu';

        $txts = StudioMessage::where('conversation_id', $conv->id)
            ->where('role', 'user')->orderByDesc('id')->limit(12)->pluck('content');

        $sel = [];
        foreach ($txts as $t) {
            $t = trim((string) $t);
            if ($t === '' || mb_strlen($t) < 20) continue;
            if (preg_match($ruido, $t)) continue;
            if (str_starts_with($t, '[AUTO-RETRY]')) continue;
            if (str_starts_with($t, 'Enviei a foto do produto')) continue;
            $sel[] = $t;
            if (count($sel) >= 3) break;
        }
        if (empty($sel)) return '';
        return mb_substr(implode("\n", array_reverse($sel)), 0, 900);
    }

    private function sseEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        ob_flush();
        flush();
    }

    private function stepToPercent(string $step): int
    {
        return match($step) {
            'queued'   => 5,
            'render'   => 30,
            'voice'    => 65,
            'lipsync'  => 80,
            'finalize' => 92,
            'done'     => 100,
            'failed'   => 0,
            default    => 15,
        };
    }

    private function stepLabel(string $step): string
    {
        return match($step) {
            'queued'   => 'Na fila...',
            'render'   => 'Renderizando vídeo',
            'voice'    => 'Gerando voz PT-BR',
            'lipsync'  => 'Sincronizando fala',
            'finalize' => 'Finalizando',
            'done'     => 'Pronto!',
            'failed'   => 'Erro na geração',
            default    => 'Processando',
        };
    }

    private function toolCheckCelebrity(array $args): array
    {
        // Studio usa esta tool pra avisar o cliente ANTES de disparar trocar_rosto
        // A verificacao real acontece no StudioGenerationJob durante a execucao
        return [
            'status'  => 'ok',
            'message' => 'Verificacao de conformidade sera feita no momento da geracao.',
        ];
    }

    private function toolListEffectTemplates(): array
    {
        $templates = [
            ['id' => 'unbox_2026', 'label' => 'Unboxing Viral',   'desc' => 'Maos abrindo caixa + reveal do produto',   'icon' => 'gift'],
            ['id' => 'cheers',     'label' => 'Brinde',           'desc' => 'Produto levantado com confete + celebracao','icon' => 'sparkles'],
            ['id' => 'teleport',   'label' => 'Teletransporte',   'desc' => 'Produto aparece em flash de luz VFX',      'icon' => 'zap'],
            ['id' => 'countdown',  'label' => 'Countdown CTA',    'desc' => '3-2-1 com produto em destaque + CTA',      'icon' => 'timer'],
        ];
        return [
            'templates' => $templates,
            'ui_widget' => ['type' => 'effect_grid', 'data' => $templates],
        ];
    }

    private function toolListAvatarsPool(array $args): array
    {
        // Pool estatico de avatares royalty-free; expandir com avatares custom do cliente futuramente
        $all = [
            ['id' => 'av_f01', 'label' => 'Sofia',   'gender' => 'feminino', 'url' => 'https://api.seller.global/storage/avatars/sofia.png'],
            ['id' => 'av_f02', 'label' => 'Camila',  'gender' => 'feminino', 'url' => 'https://api.seller.global/storage/avatars/camila.png'],
            ['id' => 'av_m01', 'label' => 'Lucas',   'gender' => 'masculino','url' => 'https://api.seller.global/storage/avatars/lucas.png'],
            ['id' => 'av_m02', 'label' => 'Rafael',  'gender' => 'masculino','url' => 'https://api.seller.global/storage/avatars/rafael.png'],
            ['id' => 'av_n01', 'label' => 'Modelo',  'gender' => 'neutro',   'url' => 'https://api.seller.global/storage/avatars/modelo.png'],
        ];
        $filter = $args['category'] ?? null;
        $avatars = $filter ? array_values(array_filter($all, fn($a) => $a['gender'] === $filter)) : $all;
        return [
            'avatars'   => $avatars,
            'ui_widget' => ['type' => 'avatar_grid', 'data' => $avatars],
        ];
    }

    private function toolCreateGenerationPlan(array $args, StudioConversation $conv): array
    {
        $intent = $args['intent'] ?? '';
        $assets = $args['assets'] ?? [];

        // DAG simples: identifica se precisa de TTS antes de lipsync
        $steps = [];
        if (str_contains($intent, 'sincronizar') || str_contains($intent, 'fala') || str_contains($intent, 'voz')) {
            if (empty($args['config']['audio_url'] ?? null)) {
                $steps[] = ['id' => 'step_tts',   'label' => 'Gerar voz PT-BR',   'type' => 'voz_brasileira',    'depends_on' => []];
                $steps[] = ['id' => 'step_video', 'label' => 'Sincronizar fala',  'type' => 'sincronizar_fala', 'depends_on' => ['step_tts']];
            } else {
                $steps[] = ['id' => 'step_video', 'label' => 'Sincronizar fala',  'type' => 'sincronizar_fala', 'depends_on' => []];
            }
        } elseif (str_contains($intent, 'pov') && str_contains($intent, 'mao')) {
            $steps[] = ['id' => 'step_hand',  'label' => 'Gerar imagem de mao', 'type' => 'intermediate_image', 'depends_on' => []];
            $steps[] = ['id' => 'step_video', 'label' => 'Gerar video POV',     'type' => 'pov_so_mao',        'depends_on' => ['step_hand']];
        } else {
            // Pipeline unico
            $steps[] = ['id' => 'step_video', 'label' => 'Gerar video', 'type' => $intent, 'depends_on' => []];
        }

        // SEL-PREVISAO-HONESTA (14/08) — era `count($steps) * 4`: 4 minutos por etapa,
        // numero inventado, nunca medido. O Ruan viu na tela: "esses minutos gerando
        // nao e real, esta muito tempo". Agora a previsao vem do que REALMENTE
        // aconteceu hoje + o tamanho da fila neste instante.
        $plan = ['steps' => $steps, 'total_steps' => count($steps), 'estimated_min' => $this->previsaoHonestaMin()];

        $ctx = $conv->context ?? [];
        $ctx['generation_plan'] = $plan;
        $conv->update(['context' => $ctx]);

        return [
            'plan'      => $plan,
            'message'   => null,
            'ui_widget' => ['type' => 'generation_plan', 'data' => $plan],
        ];
    }

    /**
     * SEL-361 Fase C: constroi SYSTEM_PROMPT base + injeta top learnings do pipeline_learnings.
     * Cache de 1h pra nao bater banco em todo turno.
     */
    /**
     * SEL-364d: duracoes oferecidas ao cliente derivam do banco
     * (video_studio_configs.max_duration_sec dos engines ativos) — admin controla.
     */
    private function allowedDurations($user = null): array
    {
        try {
            $max = (int) \Cache::remember('studio_max_duration', 300, function () {
                return (int) \DB::table('video_studio_configs')
                    ->where('is_active', 1)
                    ->max('max_duration_sec');
            });
        } catch (\Throwable $e) {
            $max = 10;
        }
        // SEL-444 (30/07, Ruan): "nao liberar menos de 10 segundos, pois menos
        // que isso corta a frase". Piso de 10s e regra de produto, nao ajuste
        // fino: a narracao nao cabe e o video sai com a fala cortada no meio.
        if ($max < 10) $max = 10;

        // SEL-30s: com a flag ligada, quem tem plano que libera (Ultra/promo_live_297)
        // passa do teto global de 1 clipe; os demais ficam em 1 clipe (~10s). Flag
        // off -> teto global intacto (comportamento atual).
        $ceiling = $max;
        if ((bool) config('services.sel30s.enabled', false)) {
            $clip = (int) config('services.sel30s.clip_seconds', 10);
            $user = $user ?? request()->user();
            if ($this->planPermiteLongo($user)) {
                $ceiling = max($ceiling, (int) config('services.sel30s.max_seconds', 30));
            } else {
                $ceiling = min($ceiling, $clip);
            }
        }

        $opts = array_values(array_filter([10, 15, 20, 30], fn ($d) => $d <= $ceiling));
        return $opts ?: [10];
    }

    /**
     * SEL-30s — o plano do cliente libera vídeo longo (até 30s)?
     * Default: video_ultra (297) e promo_live_297 (configurável em
     * services.sel30s.plans). super_admin sempre libera (teste do Ruan).
     */
    private function planPermiteLongo($user): bool
    {
        if (! $user) return false;
        try {
            if (($user->role ?? null) === 'super_admin') return true;
        } catch (\Throwable $e) {}
        try {
            $slug = $user?->client?->subscriptions()
                ->with('plan:id,slug')
                ->whereIn('status', ['active', 'trialing'])
                ->latest('id')
                ->first()?->plan?->slug;
        } catch (\Throwable $e) {
            return false;
        }
        return in_array($slug, (array) config('services.sel30s.plans', []), true);
    }

    /**
     * SEL-436 (30/07, Ruan: "quero que os clientes tenha a liberdade de gerar o
     * roteiro e que ali voce vai falar se cabe ou nao nos segundos que eles
     * mandarem"). Nao inventa regua: usa a mesma taxa de fala que o gerador de
     * roteiro ja usa (KaloclipStyleScriptService::wordsPerSecond), senao o chat
     * aprova um texto que depois nao cabe no audio.
     */
    private function analisarCabimento(string $texto, ?int $segundos, string $lang = 'pt-BR'): ?array
    {
        $palavras = preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY);
        $qtd = is_array($palavras) ? count($palavras) : 0;
        if ($qtd < 12) return null;   // frase curta nao e roteiro

        try {
            $taxa = app(\App\Services\Ai\KaloclipStyleScriptService::class)->wordsPerSecond($lang);
        } catch (Throwable $e) {
            $taxa = 2.2;
        }
        if ($taxa <= 0) $taxa = 2.2;

        $permitidas = $this->allowedDurations();
        $alvo = $segundos && in_array((int) $segundos, $permitidas, true) ? (int) $segundos : null;

        $cabeEm = [];
        foreach ($permitidas as $d) {
            $cabeEm[$d] = (int) floor($d * $taxa);
        }

        $segundosNecessarios = (int) ceil($qtd / $taxa);
        $duracaoQueCabe = null;
        foreach ($permitidas as $d) {
            if ($qtd <= $cabeEm[$d]) { $duracaoQueCabe = $d; break; }
        }

        return [
            'palavras'             => $qtd,
            'taxa'                 => $taxa,
            'duracao_alvo'         => $alvo,
            'maximo_no_alvo'       => $alvo ? $cabeEm[$alvo] : null,
            'cabe'                 => $alvo ? ($qtd <= $cabeEm[$alvo]) : null,
            'sobra_palavras'       => $alvo ? max(0, $qtd - $cabeEm[$alvo]) : null,
            'segundos_necessarios' => $segundosNecessarios,
            'duracao_que_cabe'     => $duracaoQueCabe,
            'cabe_em'              => $cabeEm,
        ];
    }

    /**
     * SEL-436 — recado em portugues sobre o cabimento, pro modelo repetir ao
     * cliente. Numero medido, nunca estimado no olho.
     */
    private function recadoCabimento(array $c): string
    {
        $p = $c['palavras'];
        if ($c['duracao_alvo'] && $c['cabe'] === true) {
            return "MEDIDO POR CODIGO: o roteiro que o cliente escreveu tem {$p} palavras e CABE em "
                 . "{$c['duracao_alvo']}s (limite {$c['maximo_no_alvo']} palavras). Diga isso a ele e siga.";
        }
        if ($c['duracao_alvo'] && $c['cabe'] === false) {
            $alt = $c['duracao_que_cabe']
                ? " Se preferir manter o texto inteiro, em {$c['duracao_que_cabe']}s cabe."
                : " Nem a maior duracao disponivel comporta esse texto.";
            return "MEDIDO POR CODIGO: o roteiro do cliente tem {$p} palavras e NAO cabe em "
                 . "{$c['duracao_alvo']}s (cabem no maximo {$c['maximo_no_alvo']}). Precisa cortar "
                 . "{$c['sobra_palavras']} palavras -- o texto falado levaria ~{$c['segundos_necessarios']}s."
                 . $alt . " Diga isso ao cliente com os numeros, ofereca encurtar mantendo a ideia dele,"
                 . " e NUNCA corte por conta propria sem avisar.";
        }
        $lista = [];
        foreach ($c['cabe_em'] as $d => $max) $lista[] = "{$d}s ate {$max} palavras";
        return "MEDIDO POR CODIGO: o roteiro do cliente tem {$p} palavras (~{$c['segundos_necessarios']}s falados). "
             . "Limites: " . implode(', ', $lista) . ". Diga em qual duracao ele cabe e pergunte qual ele quer.";
    }

    private function durationsLabel(): string
    {
        return implode(' / ', array_map(fn ($d) => $d . 's', $this->allowedDurations()));
    }

    /**
     * SEL-365: cliente com integracao inclusa no pacote agenda o setup com um
     * funcionario pelo WhatsApp — o chat NAO guia setup manual de marketplace.
     */
    private function integrationSchedulingNote($user): ?string
    {
        try {
            $planSlug = $user?->client?->subscriptions()
                ->with('plan:id,slug')
                ->whereIn('status', ['active', 'trialing'])
                ->latest('id')
                ->first()?->plan?->slug;
        } catch (\Throwable $e) {
            return null;
        }
        if (!in_array($planSlug, ['drop_start', 'drop_meio', 'drop_top', 'promo_live_297'], true)) {
            return null;
        }
        return 'INTEGRACAO INCLUSA: o pacote deste cliente INCLUI a configuracao das '
            . 'integracoes com marketplaces (Mercado Livre, Shopee, Amazon, TikTok Shop, Bling etc) '
            . 'feita por um funcionario da equipe. Se ele pedir ajuda pra conectar/integrar/configurar '
            . 'marketplace ou ERP, NAO tente guiar o passo a passo tecnico: avisa que isso ja esta '
            . 'incluso no pacote dele e oferece agendar com a equipe pelo WhatsApp 21 96639-5555 '
            . '(https://wa.me/5521966395555) — ele pode mandar o numero de telefone dele que a equipe '
            . 'agenda a configuracao. Fora esse assunto, siga o fluxo normal de video.';
    }

    private function buildSystemPrompt(): string
    {
        $base = str_replace('{DURACOES}', $this->durationsLabel(), self::SYSTEM_PROMPT);

        try {
            $learnings = \Cache::remember('studio_top_learnings', 3600, function () {
                return \DB::table('pipeline_learnings')
                    ->where('win_rate', '>', 0.6)
                    ->orderByDesc('win_rate')
                    ->limit(8)
                    ->get(['pipeline', 'category', 'vibe', 'hook_type', 'win_rate']);
            });

            if ($learnings->isEmpty()) return $base;

            $lines = [];
            foreach ($learnings as $l) {
                $pct = round($l->win_rate * 100);
                $lines[] = "  - [{$l->pipeline}] categoria={$l->category} vibe={$l->vibe} hook={$l->hook_type} => {$pct}% engajamento";
            }
            $block = implode("\n", $lines);

            $injection = "\n\n--- DADOS DE APRENDIZADO (top combinacoes que mais convertem) ---\n"
                . "Quando o cliente pede sugestao, PRIORIZE estas combinacoes pipeline+vibe+hook_type:\n"
                . $block . "\n--- FIM APRENDIZADO ---";

            return $base . $injection;
        } catch (\Throwable $e) {
            return $base; // fallback seguro — nunca quebra o chat
        }
    }

    /**
     * SEL-364: card "me sugere" manda so o NOME do produto (sem anexo) e o
     * modelo alucina image_url (example.com). Resolve a imagem REAL do
     * catalogo pelo nome citado entre aspas nas ultimas mensagens.
     * Retorna [imageUrl|null, refs[]].
     */
    private function resolveCatalogImage(StudioConversation $conv, $user): array
    {
        try {
            $texts = StudioMessage::where('conversation_id', $conv->id)
                ->where('role', 'user')->orderByDesc('id')->limit(6)->pluck('content');
            $name = null;
            foreach ($texts as $t) {
                if (is_string($t) && preg_match('/["\x{201C}\x{201D}]([^"\x{201C}\x{201D}]{5,140})["\x{201C}\x{201D}]/u', $t, $m)) {
                    $name = trim($m[1]);
                    break;
                }
            }
            if (! $name) {
                return [null, []];
            }

            $clientId = $user->client?->id;
            $cp = DB::table('client_products')
                ->join('products', 'products.id', '=', 'client_products.product_id')
                ->when($clientId, fn ($q) => $q->where('client_products.client_id', $clientId))
                ->where(fn ($q) => $q->where('client_products.custom_title', 'like', '%'.$name.'%')
                    ->orWhere('products.name', 'like', '%'.$name.'%'))
                ->select('client_products.id', 'client_products.image_url')
                ->first();
            if (! $cp) {
                return [null, []];
            }

            $refs = [];
            try {
                $collected = app(\App\Services\Ai\ProductReferenceCollector::class)->collect((int) $cp->id);
                $refs = collect($collected['images'] ?? [])->pluck('url')
                    ->filter(fn ($u) => is_string($u) && $u !== '' && $u !== $cp->image_url)
                    ->take(6)->values()->all();
            } catch (Throwable $e) {
                // refs sao bonus — capa sozinha ja resolve
            }

            Log::info('[SEL-364 Studio] imagem resolvida do catalogo', [
                'conv' => $conv->id, 'name' => $name, 'client_product' => $cp->id, 'refs' => count($refs),
            ]);

            $cover = $cp->image_url ?: array_shift($refs);

            return [$cover, array_values($refs)];
        } catch (Throwable $e) {
            Log::warning('[SEL-364 Studio] resolveCatalogImage falhou', ['err' => $e->getMessage()]);
            return [null, []];
        }
    }

    /**
     * SEL-418 — decide QUAL job gera, conforme o idioma.
     *
     * Ruan, 30/07: portugues so pro admin, e em portugues o audio nao pode ser o
     * nativo do Kling (e o que sai macarronico). O caminho do portugues e:
     * gera o video -> narra no ElevenLabs com a voz clonada -> sincroniza o labio.
     *
     * POR QUE ISTO NAO E UMA FLAG: os dois jobs sao caminhos diferentes, nao dois
     * modos do mesmo caminho.
     *   - StudioGenerationJob  = audio NATIVO do Kling. Nao faz narracao nem lipsync.
     *   - AiVideoPipelineJob   = render -> runVoice (ElevenLabs) -> runLipsync.
     * E eles leem o payload em formatos DIFERENTES: o Studio grava as chaves no
     * plano ('prompt', 'image_url'...), e o Pipeline le aninhado
     * ($payloads['render'], $payloads['voice']). Despachar um com o payload do
     * outro faz o render comecar sem prompt nenhum. Por isso aqui a gente
     * remonta o payload antes de trocar de job, em vez de so trocar a chamada.
     *
     * Espanhol (padrao do cliente) continua exatamente como estava: mesmo job,
     * mesmo payload, nada muda pra quem ja usava.
     */
    private function despacharGeracao(\App\Models\AiVideoPipeline $pipelineModel, ?int $userId, ?string $langPedido = null): string
    {
        $L    = \App\Services\Ai\VideoLanguageService::class;
        $lang = $L::resolver($userId, $langPedido);

        if (! $L::exigeNarracaoExterna($lang)) {
            // SEL-CONVITE: geracao do trial vai pra fila 'trial_low' (worker
            // dedicado, prioridade abaixo do pagante). Cliente normal segue 'default'.
            $queue = \App\Services\InviteTrialService::isTrialActive($userId) ? 'trial_low' : 'default';
            \App\Jobs\StudioGenerationJob::dispatch($pipelineModel->id)->onQueue($queue);
            return $lang;
        }

        $p = $pipelineModel->payloads ?? [];

        $p['lang']   = $lang;
        $p['render'] = [
            'model_name'      => $p['gear_model'] ?? 'kling-v3',
            'mode'            => $p['gear_mode'] ?? 'pro',
            'aspect_ratio'    => $p['aspect_ratio'] ?? '9:16',
            'duration'        => $p['duration'] ?? 10,
            'image'           => $p['image_url'] ?? null,
            'prompt'          => $p['prompt'] ?? '',
            'negative_prompt' => $p['negative_prompt'] ?? null,
            'lang'            => $lang,
        ];
        $p['voice'] = [
            // A narracao e o texto falado. Cai no prompt se nao houver roteiro
            // proprio, que e o mesmo default do runVoice.
            'text'                => $p['tts_text'] ?? ($p['prompt'] ?? ''),
            'elevenlabs_voice_id' => config('services.elevenlabs.cloned_voice_id')
                                      ?: 'tuFazJVCwiszby0YDkFk',
        ];

        $pipelineModel->payloads = $p;
        $pipelineModel->save();

        \Illuminate\Support\Facades\Log::info('[SEL-418] portugues demo — roteando pro pipeline com ElevenLabs + lipsync', [
            'pipeline_id' => $pipelineModel->id, 'user_id' => $userId, 'lang' => $lang,
        ]);

        \App\Jobs\AiVideoPipelineJob::dispatch($pipelineModel->id);

        return $lang;
    }

    /**
     * SEL-PREVISAO-HONESTA (14/08) — quantos minutos ATE O VIDEO NA GALERIA.
     *
     * Nao chuta: le a mediana real das entregas de hoje (created_at -> updated_at das
     * pipelines que terminaram com output_url) e multiplica pela profundidade da fila
     * dividida pela capacidade paralela do pool.
     *
     * Medido em 14/08 com 50 entregas: melhor 2,3min · mediana 8,9min · p90 47min ·
     * pior 92,9min. O motor sozinho leva ~2,8min — o resto era fila e retentativa.
     * Por isso a fila TEM que entrar na conta: prometer 4min com 12 pedidos na frente
     * e mentir pro cliente, e mentir e pior que demorar.
     */
    private function previsaoHonestaMin(): int
    {
        try {
            $naoFinais = ['queued', 'render', 'processing', 'voice', 'lipsync', 'queued_wait'];

            // 1) quanto levou de verdade, nas ultimas 30 entregas
            $tempos = \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                ->where('step', 'done')->whereNotNull('output_url')
                ->where('created_at', '>', now()->subHours(6))
                ->orderByDesc('id')->limit(30)
                ->get(['created_at', 'updated_at'])
                ->map(fn ($p) => (strtotime($p->updated_at) - strtotime($p->created_at)) / 60)
                ->filter(fn ($m) => $m > 0 && $m < 180)
                ->sort()->values()->all();

            $mediana = count($tempos) >= 5 ? (float) $tempos[intdiv(count($tempos), 2)] : 9.0;

            // 2) quantos estao na frente agora, e quantos cabem em paralelo
            $fila = \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                ->whereIn('step', $naoFinais)->count();
            $motores = max(1, (int) \App\Models\AiEngine::where('is_active', 1)->count());

            $ondas = (int) ceil(($fila + 1) / $motores);
            $eta   = $mediana * max(1, $ondas);

            // teto de sanidade: nunca prometer menos de 2min nem assustar com mais de 45
            return (int) max(2, min(45, round($eta)));
        } catch (\Throwable $e) {
            return 9; // mediana medida em 14/08 — melhor que 4 chutado
        }
    }
}
