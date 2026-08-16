<?php
// SEL-LOG-QUE-SOME (14/08): LOG_LEVEL=error no .env descarta info/warning.
// Os rastros SEL-* subiram pra error pra existirem quando alguem for conferir.

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiVideoPipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SEL-452 — Studio por OPCOES (substitui a conversa como caminho principal).
 *
 * Ruan 30/07: "coloca as opcoes e tira a conversa com a IA. Cria UGC, POV etc."
 * O cliente escolhe em telas de opcao (estilo, cenario, camera, inicio/meio/fim,
 * duracao, avatar) e a gente COMPOE o prompt de 10 secoes aprovado
 * (KaloclipStyleScriptService::toKlingPrompt) a partir dessas escolhas.
 * Nenhuma etapa passa por LLM conversacional.
 *
 * ============================================================================
 * LEIA ISTO ANTES DE MEXER EM CAMERA  (SEL-452, medido em 30/07)
 * ============================================================================
 * Existe um enum `camera_preset` em StudioChatController (pan_left, pan_right,
 * tilt_up, tilt_down, zoom_in, zoom_out, orbit_left, orbit_right) que vira
 * `camera_control` da API do Kling em StudioGenerationJob::CAMERA_PRESETS.
 * PARECE o caminho certo pra movimento de camera. NAO E — nao em producao.
 *
 * Producao roda KLING_MODE=browser (a API oficial acabou: 1 unidade de 1000,
 * expira 13/08). No modo navegador quem gera e o worker
 * /home/api.seller.global/browser-worker/generate_video.js, que consome
 * exatamente estas chaves:
 *
 *     input.prompt        <- DIGITADO na caixa de texto do Kling
 *     input.image_path    <- UMA imagem so
 *     input.duration
 *     input.aspect_ratio
 *     input.native_audio
 *
 * Nao ha `camera_control`. Nao ha `elements`. Nao ha `multi_shot`. Nao ha
 * `image_refs`. Tudo isso e descartado silenciosamente antes de chegar no
 * motor. Mandar camera_preset daqui seria botao decorativo.
 *
 * Por isso a camera vai por TEXTO, dentro do prompt — que e o unico canal que
 * o motor le, e e como os videos aprovados pelo Ruan sempre funcionaram (o
 * formato de 10 secoes tem secao CAMERA em texto).
 *
 * Mesma razao pela qual a imagem UNICA e a foto do PRODUTO e a apresentadora
 * e descrita em texto: o worker so sobe um arquivo, entao nao da pra compor
 * avatar + produto. Produto e a estrela e nunca pode ser inventado.
 * ============================================================================
 */
class StudioOptionsController extends Controller
{
    /**
     * SEL-ALEATORIO-GENERO (14/08, Ruan: "no avatar, quando o cliente seleciona
     * aleatorio, ele nao tem aleatorio homem ou mulher").
     *
     * Ele achou uma mentira nossa: "Aleatorio" NUNCA foi aleatorio. Sem avatar
     * escolhido, o prompt caia sempre em "apresentadora brasileira" e a narracao
     * pedia "voz feminina" fixa -- ou seja, o cliente que queria um homem nao
     * tinha como pedir, e quem clicava em aleatorio recebia mulher sempre.
     *
     * Valores: 'qualquer' (sorteia de verdade a cada geracao), 'mulher', 'homem'.
     */
    private string $generoApresentador = 'qualquer';

    /** SEL-SEM-SOM-LIGA: o pedido de video mudo desta geracao. */
    private ?bool $pedidoSemSom = null;
    private ?string $pedidoMovimentos = null;
    private ?string $pedidoPromptColado = null;
    /** SEL-PALAVRA-DO-CLIENTE (16/08): a foto de AMBIENTE que o cliente subiu. Guardada
     *  aqui pra ser dita LITERALMENTE no prompt final — antes ela so entrava no brief,
     *  que e entrada do LLM, e o cliente via o video sair em outro cenario. */
    private ?string $pedidoCenarioFoto = null;

    /** Homem ou mulher para ESTA geracao. 'qualquer' sorteia de verdade. */
    private function generoResolvido(): string
    {
        if ($this->generoApresentador === 'homem')  return 'homem';
        if ($this->generoApresentador === 'mulher') return 'mulher';

        return random_int(0, 1) === 1 ? 'homem' : 'mulher';
    }

    /** Como o prompt descreve quem apresenta (concordancia certa em pt-BR). */
    private function textoApresentador(?string $genero = null): string
    {
        $g = $genero ?: $this->generoResolvido();

        return $g === 'homem'
            ? 'apresentador brasileiro autentico, tom espontaneo, filmagem estilo selfie/UGC'
            : 'apresentadora brasileira autentica, tom espontaneo, filmagem estilo selfie/UGC';
    }

    /**
     * Movimentos de camera oferecidos ao cliente, em portugues, com o fragmento
     * que entra no prompt. Espelha src/components/videostudio/CameraPicker.tsx.
     *
     * "Acompanhando a pessoa" NAO esta aqui de proposito: o camera_control do
     * Kling so tem eixo horizontal/vertical/zoom, nao tem eixo de follow, e o
     * modo navegador nem le camera_control. Prefiro cinco opcoes que funcionam
     * a seis com uma mentindo.
     */
    private const CAMERAS = [
        'zoom_produto' => [
            'label'    => 'Zoom no produto',
            'fragment' => 'zoom-in continuo e suave, a camera se aproxima progressivamente do produto ate o close, foco travado no produto',
        ],
        'passar_esquerda' => [
            'label'    => 'Passar pra esquerda',
            'fragment' => 'movimento panoramico horizontal para a esquerda, revelando a cena, terminando com o produto centralizado e em destaque',
        ],
        'passar_direita' => [
            'label'    => 'Passar pra direita',
            'fragment' => 'movimento panoramico horizontal para a direita, revelando a cena, terminando com o produto centralizado e em destaque',
        ],
        'avancar_frente' => [
            'label'    => 'Avançar de frente',
            'fragment' => 'dolly-in frontal, a camera avanca em linha reta em direcao ao produto revelando os detalhes da superficie',
        ],
        'avanco_suave' => [
            'label'    => 'Avanço suave',
            'fragment' => 'aproximacao lenta e cinematografica em direcao ao produto, movimento continuo e estavel, sem tremor',
        ],
        'girar_em_volta' => [
            'label'    => 'Girar em volta',
            'fragment' => 'movimento orbital ao redor do produto, mantendo o produto centralizado no quadro o tempo todo, revelando os angulos',
        ],
    ];

    /** Duracoes REAIS por estilo. Piso de 10s em tudo (Ruan 30/07: menos que
     *  isso corta a frase). Ver allowedDurations() no StudioChatController. */
    // SEL-SO-10-OU-30 (16/08, Ruan ao vivo): "eu pedi so pra voce botar de volta o
    // botao de 30 segundos com tres cenas. So isso! Tu meteu 15 segundo, 20, que loucura."
    // O motivo NAO e estetico, e storytelling: a historia precisa de tres pontos —
    // inicio, meio e fim. Em 10s a IA encaixa os tres num clipe so ("olha esse produto"
    // / "e assim, assado" / "carrinho laranja embaixo"). Em 30s cada ato vira um CLIPE
    // INTEIRO — um pro gancho, um pra historia, um pro pitch de venda — e o cliente
    // junta os tres num video so. 15s e 20s nao cabem em tres atos nem em um: sao
    // tamanhos que nao servem a nenhuma das duas ideias. Palavras dele: "ou e 10
    // segundos ou 30 segundos, pelo menos por enquanto".
    private const DURACOES = [
        'ugc'               => [10, 30],
        'pov'               => [10],
        'showcase'          => [10],
        'trocar_personagem' => [10],
        'zero'              => [10],
    ];

    /** Limites REAIS de upload, medidos no codigo — nao numero bonito.
     *  foto: StudioChatController::upload  -> max:51200 KB, mimes jpeg,jpg,png,webp,gif
     *  audio: StudioPrepareController::uploadAudio -> max:20480 KB, mimes mp3,wav,m4a,ogg,webm */
    private const LIMITES = [
        'foto' => [
            'max_mb'      => 50,
            'formatos'    => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
            'proporcao'   => '9:16 (em pe)',
            'min_largura' => 720,
            'min_altura'  => 1280,
        ],
        'audio' => [
            'max_mb'   => 20,
            'formatos' => ['mp3', 'wav', 'm4a', 'ogg', 'webm'],
            'uso'      => 'roteiro',
        ],
    ];

    /**
     * GET /api/v1/studio-options/catalog
     * Catalogo unico das opcoes. A tela le daqui pra nao divergir do backend.
     */
    public function catalog()
    {
        $cameras = [];
        foreach (self::CAMERAS as $id => $c) {
            $cameras[] = ['id' => $id, 'label' => $c['label']];
        }

        // SEL-30s: a tela so oferece durações longas (>1 clipe) pra quem o plano
        // libera, e só com a flag ligada. Flag off -> catalogo intacto.
        $duracoes = self::DURACOES;
        if ((bool) config('services.sel30s.enabled', false) && ! $this->planPermiteLongo(request()->user())) {
            $clip = (int) config('services.sel30s.clip_seconds', 10);
            $duracoes = array_map(
                fn ($arr) => array_values(array_filter($arr, fn ($d) => $d <= $clip)) ?: [$clip],
                $duracoes
            );
        }

        return response()->json([
            'cameras'          => $cameras,
            'camera_padrao'    => 'zoom_produto',
            'duracoes'         => $duracoes,
            'limites'          => self::LIMITES,
        ]);
    }

    /**
     * POST /api/v1/studio-options/option-bank
     *
     * INF-030 (07/08) — item 1 do briefing. O front (StudioOptions.tsx) tem
     * campo livre em CADA gancho (abertura/meio/final) e no cenário, além dos
     * botões prontos. Este endpoint só COLETA o texto que o cliente escreve —
     * vira o banco de opções (video_option_bank) que um dia vira botão novo
     * (curadoria manual; este endpoint NAO cria botão automático, só grava).
     * Nunca bloqueia a geração: front chama em fire-and-forget.
     */
    public function optionBank(Request $request)
    {
        $v = $request->validate([
            'tipo'  => 'required|string|in:abertura,meio,final,cenario',
            'texto' => 'required|string|max:300',
        ]);

        $user = $request->user();

        \Illuminate\Support\Facades\DB::table('video_option_bank')->insert([
            'tipo'       => $v['tipo'],
            'texto'      => trim($v['texto']),
            'user_id'    => $user?->id,
            'client_id'  => optional($user?->client)->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/v1/studio-options/favorites
     *
     * INF-030 (07/08) — pedido extra do Ruan via main: favoritar modelos/
     * formatos (estilo/gancho/avatar/cenario/formato) pra reusar rápido.
     * Lista os favoritos do usuário logado.
     */
    public function favoritesIndex(Request $request)
    {
        $rows = \Illuminate\Support\Facades\DB::table('video_favorites')
            ->where('user_id', $request->user()?->id)
            ->orderByDesc('id')
            ->get(['id', 'tipo', 'valor', 'label', 'created_at']);

        return response()->json(['favoritos' => $rows]);
    }

    /**
     * POST /api/v1/studio-options/favorites
     *
     * Toggle idempotente: se já existe (mesmo user+tipo+valor), remove
     * (desfavorita); senão, cria (favorita). `valor` é o dado bruto
     * (JSON.stringify feito no front) — cada `tipo` decide o shape:
     *   estilo   -> "ugc" | "pov" | "showcase" | "zero" | "empresa"
     *   formato  -> "10" | "15" | "20" | "30" (segundos) OU id de câmera
     *   gancho   -> {"sub":"abertura|meio|final","texto":"..."}
     *   avatar   -> {"id":1,"url":"...","nome":"..."}
     *   cenario  -> {"texto":"..."} ou id da cena da grade
     */
    public function favoritesToggle(Request $request)
    {
        $v = $request->validate([
            'tipo'  => 'required|string|in:estilo,gancho,avatar,cenario,formato',
            'valor' => 'required|string|max:255',
            'label' => 'nullable|string|max:160',
        ]);

        $user = $request->user();
        $db = \Illuminate\Support\Facades\DB::table('video_favorites');

        $existing = (clone $db)
            ->where('user_id', $user?->id)
            ->where('tipo', $v['tipo'])
            ->where('valor', $v['valor'])
            ->first();

        if ($existing) {
            $db->where('id', $existing->id)->delete();
            return response()->json(['ok' => true, 'favorited' => false]);
        }

        $db->insert([
            'tipo'       => $v['tipo'],
            'valor'      => $v['valor'],
            'label'      => $v['label'] ?? null,
            'user_id'    => $user?->id,
            'client_id'  => optional($user?->client)->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'favorited' => true]);
    }

    /**
     * POST /api/v1/studio-options/generate
     * Recebe as escolhas e dispara. Sem conversa, sem LLM de dialogo.
     */
    public function generate(Request $request)
    {
        if (! config('services.ai_video.generation_enabled', true)) {
            return response()->json([
                'error'   => 'video_generation_disabled',
                'message' => 'Geracao de video temporariamente indisponivel. Voltamos em breve.',
            ], 503);
        }

        // SEL-CUSTOMPRODUCT (10/08): cliente sobe o PROPRIO produto (imagens + titulo +
        // descricao) em vez de escolher um produto-em-alta do Kalodata. Quando
        // custom_product vier no payload, produto_nome/imagem deixam de ser
        // obrigatorios (sao derivados do custom_product logo abaixo) — o resto do
        // fluxo (prompt, referencias de imagem, pipeline) e o MESMO de sempre.
        $hasCustomProduct = is_array($request->input('custom_product'))
            && ! empty($request->input('custom_product'));

        $v = $request->validate([
            'estilo'          => 'required|string|in:ugc,pov,showcase,zero,trocar_personagem',
            // SEL-TROCAPERSONAGEM: os 3 caminhos do menu novo.
            //   mesma_fala -> sobe um video, a gente OUVE a fala e refaz com outro personagem
            //   roupa      -> veste a roupa no personagem e ele so mostra o corpo (sem fala)
            //   so_rosto   -> troca so o rosto de um video que ja existe
            'modo_troca'      => 'nullable|string|in:mesma_fala,roupa,so_rosto,continuar',
            // SEL-ESPELHO (14/08, Ruan): no video de roupa existe um padrao proprio —
            // a pessoa se grava no espelho, de celular na mao. Sem o celular, fica
            // 'espelho sem camera' e o video nao tem nexo.
            'espelho'         => 'nullable|boolean',
            // SEL-SEM-SOM (14/08, Ruan): "tem que ter a opcao de NAO querer som. Sem
            // som o personagem so apresenta SEM MEXER A BOCA; no POV nao fala nada. E
            // ai abre outro menu com os MOVIMENTOS que ele vai fazer, e deixa colar o
            // prompt dele." O prompt colado nao tem limite de caracteres na tela --
            // aqui o teto e generoso so pra evitar abuso (o motor le ~1800 mesmo).
            'com_som'         => 'nullable|boolean',
            // SEL-PAISAGEM-LIBERADA (15/08): sem esta regra o campo nem chegava no
            // \$v — o seletor do Estudio mandava e o validate() descartava calado,
            // e o conserto la embaixo nunca teria efeito nenhum. Falha silenciosa.
            'aspect_ratio'    => 'nullable|string|in:9:16,16:9',
            'movimentos'      => 'nullable|array|max:12',
            'movimentos.*'    => 'string|max:120',
            'prompt_colado'   => 'nullable|string|max:8000',
            'fala_parte2'     => 'nullable|string|max:300',
            'video_ref_url'   => 'nullable|url|max:2048',
            'manter_cenario'  => 'nullable|boolean',
            // SEL-GANCHO (14/08, Ruan): marcando isso, o video NAO pode ter fim.
            'tera_parte2'     => 'nullable|boolean',
            'person_url'      => 'nullable|url|max:2048',
            'cloth_url'       => 'nullable|url|max:2048',
            'face_url'        => 'nullable|url|max:2048',
            'produto_nome'    => $hasCustomProduct ? 'nullable|string|max:500' : 'required|string|max:500',
            // SEL-TROCAPERSONAGEM: aqui a imagem vem do FRAME do video que o cliente
            // subiu (SEL-FRAMEREF), nao de um produto do catalogo. Exigir 'imagem'
            // fazia o cliente tomar 422 mesmo tendo mandado o video — medido na 1a
            // chamada real depois de ligar o frame.
            'imagem'          => $hasCustomProduct ? 'nullable|url|max:2048' : 'required_unless:estilo,zero,trocar_personagem|nullable|url|max:2048',
            'image_urls'      => 'nullable|array|max:12',
            'image_urls.*'    => 'url|max:2048',
            'produto_preco'   => 'nullable|numeric|min:0',
            'produto_categoria' => 'nullable|string|max:100',
            'cenario'         => 'nullable|string|max:300',
            'camera'          => 'nullable|string|max:32',
            // SEL-VOZ-NOSSA-NO-VIDEO (16/08): 'so narracao' = sem a voz do motor, com a NOSSA por cima.
            'narracao'        => 'nullable|boolean',
            'inicio'          => 'nullable|string|max:2000',
            'meio'            => 'nullable|string|max:2000',
            'fim'             => 'nullable|string|max:2000',
            'duracao'         => 'required|integer',
            'apresentadora'   => 'nullable|string|max:200',
            // SEL-ALEATORIO-GENERO: so vale quando NAO ha avatar escolhido
            'apresentador_genero' => 'nullable|in:qualquer,mulher,homem',
            // SEL-MEUS-CENARIOS (14/08): foto do cenario do proprio cliente
            'cenario_imagem'  => 'nullable|url|max:2048',
            // SEL-QUANTAS-CENAS (14/08, Ruan ao vivo): 1 ou 3 cenas. Com 3, o video
            // vira historia -- um corte pro inicio (gancho), um pro meio e um pro fim,
            // cada um com o texto e o CENARIO dele, mantendo a mesma pessoa e o mesmo
            // produto.
            'n_cenas'                => 'nullable|integer|in:1,3',
            'cenas'                  => 'nullable|array|max:3',
            'cenas.*.papel'          => 'nullable|string|in:inicio,meio,fim',
            'cenas.*.prompt'         => 'nullable|string|max:600',
            'cenas.*.cenario'        => 'nullable|string|max:300',
            'cenas.*.cenario_imagem' => 'nullable|url|max:2048',
            'roteiro_cliente' => 'nullable|string|max:1200',
            // SEL-CUSTOMPRODUCT: produto proprio do cliente (upload). images[] SAO as
            // referencias image2video/multiImageToVideo — TODAS entram, nao so a 1a.
            'custom_product'                => 'nullable|array',
            'custom_product.images'         => $hasCustomProduct ? 'required|array|min:1|max:10' : 'nullable|array',
            'custom_product.images.*'       => 'url|max:2048',
            'custom_product.title'          => $hasCustomProduct ? 'required|string|max:500' : 'nullable|string|max:500',
            'custom_product.description'    => 'nullable|string|max:2000',
            // SEL-CUSTOMPRODUCT (avatar): cliente seleciona um avatar (AvatarShelf/
            // AvatarPicker/MeuAvatarUpload) pra ser a PESSOA que aparece no video.
            // avatar_id = id de client_video_avatars (lista que /videostudio/avatars
            // ja devolve em `mine_all`, escopada por client) — caminho recomendado.
            // avatar_url = URL direta (aceito, mas so aplica o check anti-strike de
            // exclusividade quando da pra casar com uma linha do proprio cliente).
            'avatar_id'  => 'nullable|integer',
            'avatar_url' => 'nullable|url|max:2048',
        ]);

        // ── SEL-CUSTOMPRODUCT: normaliza pro MESMO formato que o fluxo produto-em-alta
        //    ja usa (produto_nome/imagem/image_urls) — dali pra frente o resto do metodo
        //    (prompt, refs de imagem, pipeline) nao sabe nem precisa saber que o produto
        //    e "customizado". $descricaoCustom so existe pra alimentar o prompt (LLM +
        //    fallback deterministico), nunca vira fala literal.
        $descricaoCustom = '';
        if ($hasCustomProduct) {
            $cp = $v['custom_product'];
            $cpImages = array_values(array_unique(array_filter($cp['images'] ?? [])));
            $v['produto_nome'] = trim((string) ($v['produto_nome'] ?? '')) !== ''
                ? $v['produto_nome']
                : mb_substr(trim((string) ($cp['title'] ?? '')), 0, 500);
            // image_urls e o campo que o bloco SEL-460 (mais abaixo) ja le pra pegar
            // TODAS as imagens como referencia — junta com o que o front ja tiver mandado.
            $v['image_urls'] = array_values(array_unique(array_filter(array_merge(
                $v['image_urls'] ?? [],
                $cpImages
            ))));
            $v['imagem'] = ($v['imagem'] ?? null) ?: ($v['image_urls'][0] ?? null);
            $descricaoCustom = trim((string) ($cp['description'] ?? ''));
            // guarda em $v tambem — buildProductDesc($v)/buildDeterministicScript($v,...)
            // recebem $v por parametro e usam isto pro slot PRODUTO do prompt no
            // caminho de FALLBACK deterministico (sem LLM). Ver buildProductDesc() abaixo.
            $v['custom_product_description'] = $descricaoCustom;
            Log::error('[SEL-CUSTOMPRODUCT] produto proprio do cliente', [
                'titulo'      => mb_substr($v['produto_nome'], 0, 80),
                'n_imagens'   => count($v['image_urls']),
                'tem_desc'    => $descricaoCustom !== '',
            ]);
        }

        $estilo  = $v['estilo'];

        // SEL-SO10S (12/08, ordem do Ruan: "esquece videos acima de 10s, remove isso").
        //
        // POR QUE (medido, 30 dias, so pedido de cliente):
        //      8s    23 pedidos ->  19 entregues   83%   1 corte
        //     10s   611 pedidos -> 200 entregues   33%   2 cortes  <- 81% do volume
        //     15s    58 pedidos ->  13 entregues   22%   2 cortes
        //     30s    19 pedidos ->   3 entregues   16%   4 cortes
        // Video acima de 10s exige varios cortes, cada corte reserva um motor, e com a
        // frota disputada o relogio do video inteiro estoura antes do ultimo corte sair.
        // Teto duro no BACKEND (o cadeado do front sozinho nao segura chamada direta).
        // SEL-30S-DESTRAVA (16/08, ordem do Ruan ao vivo, repetida: "o botao 30s
        // esta no lugar errado, ele ja tinha que esta do lado dos 10s"). Este teto era
        // `min(10, ...)`: com a duracao cortada em 10, a condicao do caminho longo
        // (`$duracao > $limiteParaPartir`) NUNCA era verdadeira. Uma linha desligava o
        // recurso inteiro — a flag SEL30S_ENABLED ja estava ligada, DURACOES[ugc] ja
        // trazia [10,15,20,30] e o StudioLongVideoJob ja existia pronto.
        // O teto some, mas nada fica aberto de graca: quem filtra e o CATALOGO por plano
        // (catalog() + planPermiteLongo) e o clamp logo abaixo, que so aceita duracao da
        // lista do tipo escolhido. RISCO CONHECIDO E MEDIDO: em 30 dias, pedido de
        // cliente com 30s entregou 3 de 19 (16%) contra 33% do 10s. O Ruan foi avisado
        // e mandou destravar assim mesmo.
        $duracao = min(30, max(1, (int) $v['duracao']));

        $user    = $request->user();

        // SEL-GERENTE (09/08): afiliado sob gerente sem liberacao explicita nao gera
        // video. So bloqueia quem tem registro de afiliado com manager_id preenchido
        // E video_gen_authorized=false — passa reto pra qualquer outro caso (nao
        // interfere no fluxo normal de video).
        $gateGerente = \App\Services\AffiliateManagerService::checkVideoGenAllowed($user?->id);
        if (! $gateGerente['ok']) {
            return response()->json([
                'error'   => 'video_gen_nao_autorizado',
                'message' => $gateGerente['message'],
            ], 403);
        }

        // SEL-1porvez (Ruan 09/08): 1 video ATIVO por cliente. Enquanto tiver um
        // gerando (queued/render/lipsync/voice), NAO inicia outro -> evita o cliente
        // ficar gerando em serie e colapsar a fila (mil videos x mil users). O front
        // trava o botao; isto e a trava server-side (impossivel burlar). Ruan isento.
        if (($user->role ?? null) !== 'super_admin' && $user) {
            // SEL-destrava (Ruan 12/08, cliente presa AO VIVO): pipeline que morreu
            // (ex: despacho nao chegou no Flow -> render_task_id vazio) NAO pode mais
            // prender o cliente pra sempre. Ignora os 'ativos' que ja passaram do tempo:
            //   - sem render_task_id e parado ha > 4min  = nunca chegou no motor
            //   - com task mas parado ha > 25min          = morreu no meio
            $temAtivo = \App\Models\AiVideoPipeline::where('user_id', $user->id)
                ->whereIn('step', ['queued', 'render', 'lipsync', 'voice'])
                ->where(function ($q) {
                    $q->where(function ($q2) {
                        $q2->whereNull('render_task_id')->orWhere('render_task_id', '');
                    })->where('updated_at', '>', now()->subMinutes(4));
                })
                ->orWhere(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->whereIn('step', ['queued', 'render', 'lipsync', 'voice'])
                      ->whereNotNull('render_task_id')->where('render_task_id', '<>', '')
                      ->where('updated_at', '>', now()->subMinutes(25));
                })
                ->exists();
            if ($temAtivo) {
                return response()->json([
                    'error'   => 'video_em_andamento',
                    'message' => 'Voce ja tem um video sendo gerado. Aguarde ele ficar pronto '
                        . '- a gente te avisa por e-mail e push, e ele aparece na sua galeria. '
                        . 'So depois pode gerar outro. :)',
                ], 429);
            }
        }

        // ── SEL-490: LIMITE POR PLANO (server-side, atômico) — ANTES de montar
        //    prompt/imagens e de enfileirar. Impossível burlar pelo front: recarregar,
        //    request direto na API, dois logins, aba nova e requests paralelos batem
        //    todos no MESMO contador (lock MariaDB por user_id). Free bloqueado; Start
        //    1 vídeo/24h; Pro/Ultra ilimitado (Ultra com prioridade). ─────────────
        $gate = \App\Services\Ai\VideoPlanLimitService::checkAndReserve($user?->id, $request);
        if (! $gate['ok']) {
            return response()->json(array_filter([
                'error'       => $gate['motivo'],
                'message'     => $gate['message'],
                'retry_after' => $gate['retry_after'] ?? null,
            ], fn ($x) => $x !== null && $x !== ''), $gate['status'] ?? 403);
        }
        $reservationId = $gate['reservation_id'] ?? null;
        $ehPrioridade  = (bool) ($gate['priority'] ?? false);
        $qualityTier   = (string) ($gate['tier'] ?? 'lite');

        // SEL-TROCAPERSONAGEM: caminho proprio. Sai daqui de proposito — o fluxo de baixo
        // monta roteiro do zero, e aqui a fala e o cenario VEM DE FORA (do video que o
        // cliente subiu) ou nem existe (roupa). Misturar os dois so criaria if aninhado.
        if ($estilo === 'trocar_personagem') {
            return $this->gerarTrocaPersonagem($request, $v, $user, $reservationId, $ehPrioridade, $qualityTier);
        }

        // Piso de 10s + teto REAL por estilo. Fora disso e 422 com o motivo escrito.
        $permitidas = self::DURACOES[$estilo] ?? [10];
        // SEL-30s: quando a flag esta ligada, so plano que libera (Ultra/promo_live_297)
        // ve durações acima de 1 clipe (~10s). Flag off -> lista intacta (fluxo atual).
        if ((bool) config('services.sel30s.enabled', false) && ! $this->planPermiteLongo($user)) {
            $clip = (int) config('services.sel30s.clip_seconds', 10);
            $permitidas = array_values(array_filter($permitidas, fn ($d) => $d <= $clip)) ?: [$clip];
        }
        // SEL-DURACAO-CLAMP (14/08) — ACHADO ao reproduzir a queixa "nao consigo gerar":
        // a tela oferecia 10s, o backend so aceitava 8s pro plano do cliente, e a
        // resposta era 422 duracao_invalida. O cliente clicava, a RESERVA era feita e
        // o pedido morria — welington.miranda tentou 9 VEZES em 9 minutos e nao saiu
        // nenhum video (9 reservas fantasma, 0 pipeline).
        //
        // Recusar por causa de um numero que a NOSSA tela ofereceu e culpar o cliente
        // pelo nosso descompasso. Agora, em vez de 422, a gente ENCAIXA na duracao
        // permitida mais proxima e gera. O aviso de "~8s" ja existe na tela desde hoje
        // de manha, entao ninguem e surpreendido.
        if (! in_array($duracao, $permitidas, true)) {
            $original = $duracao;
            $duracao  = collect($permitidas)
                ->sortBy(fn ($d) => abs($d - $original))
                ->first() ?? (int) $permitidas[0];
            Log::error('[SEL-DURACAO-CLAMP] duracao ajustada em vez de recusar', [
                'user' => $user?->id, 'estilo' => $estilo,
                'pedida' => $original, 'usada' => $duracao, 'permitidas' => $permitidas,
            ]);
        }
        if (false) {
            return response()->json([
                'error'   => 'duracao_invalida',
                'message' => 'Nesse formato as duracoes disponiveis sao: '
                           . implode('s, ', $permitidas) . 's.',
                'permitidas' => $permitidas,
            ], 422);
        }

        // ── Camera: por TEXTO (ver bloco no topo do arquivo) ────────────────
        $cameraId  = $v['camera'] ?? 'zoom_produto';
        $camera    = self::CAMERAS[$cameraId] ?? self::CAMERAS['zoom_produto'];
        // Regra dura do produto (Ruan): movimento no maximo que der + aproximacao
        // no produto. O produto e a estrela — nao o avatar, nao o cenario.
        $cameraTexto = $camera['fragment']
            . '. Camera sempre em movimento, nunca parada. O produto e o assunto '
            . 'principal de todos os planos e termina o video em close, ocupando '
            . 'a maior parte do quadro.';

        // ── Brief do cliente: as escolhas viram instrucao pro roteirista ────
        $brief = [];
        if (! empty($v['cenario']))  $brief[] = 'Cenario escolhido pelo cliente: ' . $v['cenario'];
        if (! empty($v['inicio']))   $brief[] = 'Comeco do video: ' . $v['inicio'];
        if (! empty($v['meio']))     $brief[] = 'Meio do video: ' . $v['meio'];
        if (! empty($v['fim']))      $brief[] = 'Final do video: ' . $v['fim'];
        // SEL-ALEATORIO-GENERO: o genero so manda quando o cliente NAO escolheu
        // um rosto salvo -- avatar escolhido ja define quem apresenta.
        $this->generoApresentador = (string) ($v['apresentador_genero'] ?? 'qualquer');
        // SEL-SEM-SOM-LIGA: guarda o pedido de mudo pra ele chegar no prompt final.
        $this->pedidoSemSom = array_key_exists('com_som', $v) ? ($v['com_som'] === false) : null;
        $this->pedidoMovimentos = ! empty($v['movimentos']) && is_array($v['movimentos'])
            ? implode('; ', array_slice($v['movimentos'], 0, 12)) : null;
        $this->pedidoPromptColado = ! empty($v['prompt_colado']) ? trim((string) $v['prompt_colado']) : null;
        $generoDaVez = $this->generoResolvido();
        if (empty($v['apresentadora']) && empty($v['avatar_url']) && empty($v['avatar_id'])) {
            $brief[] = 'Quem apresenta: ' . ($generoDaVez === 'homem'
                ? 'um apresentador HOMEM brasileiro'
                : 'uma apresentadora MULHER brasileira');
        }
        // SEL-MEUS-CENARIOS: a foto do cenario do cliente vira REFERENCIA de verdade
        // (entra no image_urls, que e o que o motor recebe) e e dita no brief, pra
        // nao virar campo bonito que nao muda nada no video.
        if (! empty($v['cenario_imagem'])) {
            // SEL-PALAVRA-DO-CLIENTE (16/08): guarda pro prompt final (ver enrich).
            $this->pedidoCenarioFoto = trim((string) $v['cenario_imagem']);
            // SEL-PALAVRA-DO-CLIENTE-DE-VERDADE (16/08): semeia com a foto do PRODUTO antes
            // de anexar o cenario. O front so manda `image_urls` quando ha MAIS DE UMA foto;
            // com uma foto so, este merge produzia uma lista contendo APENAS o cenario — e
            // dali pra frente a referencia do video virava a sala do cliente, sem o produto
            // dele dentro. (Medido pela auditoria: "foto do PRODUTO entrou? false".)
            $semente = ! empty($v['image_urls']) ? $v['image_urls'] : array_filter([$v['imagem'] ?? null]);
            $v['image_urls'] = array_values(array_unique(array_filter(array_merge(
                $semente,
                [$v['cenario_imagem']]
            ))));
            $brief[] = 'Cenario: use o AMBIENTE da ultima foto de referencia como fundo da cena (o produto continua sendo o protagonista)';
        }
        // SEL-SEM-SOM: silencio de verdade -- nao basta "sem narracao", tem que
        // proibir a BOCA de se mexer, senao o motor entrega alguem falando mudo, que
        // e pior que falar.
        if (array_key_exists('com_som', $v) && $v['com_som'] === false) {
            $brief[] = 'VIDEO SEM SOM: NINGUEM FALA e NINGUEM MEXE A BOCA em nenhum momento '
                     . '(boca fechada, sem mimica de fala, sem narracao, sem legenda). So imagem '
                     . 'e som ambiente -- a musica entra depois, por conta do cliente';
            if (! empty($v['movimentos']) && is_array($v['movimentos'])) {
                $brief[] = 'O QUE A PESSOA FAZ (o cliente escolheu): ' . implode('; ', array_map(
                    fn ($m) => trim((string) $m),
                    array_slice($v['movimentos'], 0, 12)
                ));
            }
        }
        if (! empty($v['prompt_colado'])) {
            $brief[] = 'PROMPT ESCRITO PELO PROPRIO CLIENTE (respeitar ao maximo): ' . trim($v['prompt_colado']);
        }
        if (! empty($v['apresentadora'])) $brief[] = 'Quem apresenta: ' . $v['apresentadora'];
        // Audio do cliente entra como ROTEIRO (a narracao e a voz do sistema).
        if (! empty($v['roteiro_cliente'])) {
            $brief[] = 'Use as palavras do proprio cliente como base da fala: "'
                     . mb_substr($v['roteiro_cliente'], 0, 600) . '"';
        }
        $brief[] = 'Movimento de camera pedido: ' . $camera['label'];

        $produto = [
            'name'        => $v['produto_nome'],
            'price'       => $v['produto_preco'] ?? null,
            'category'    => $v['produto_categoria'] ?? 'geral',
            // SEL-CUSTOMPRODUCT: quando o front mandou image_urls (galeria Kalodata OU
            // as fotos que o cliente subiu do proprio produto), reflete a contagem REAL
            // aqui — o prompt (KaloclipStyleScriptService::generate) usa isso so como
            // contexto ("Fotos disponiveis: N"), nao muda quais imagens viram referencia
            // de fato (isso e o bloco SEL-460/486b mais abaixo).
            'images'      => ! empty($v['image_urls']) ? $v['image_urls'] : array_filter([$v['imagem'] ?? null]),
            // SEL-CUSTOMPRODUCT: descricao do produto proprio do cliente — o "cerebro"
            // (KaloclipStyleScriptService::generate) ja tem suporte a este campo desde
            // sempre (ver Descrição: {$description} no prompt), so faltava alguem
            // preencher. No fluxo produto-em-alta continua vazio, comportamento intacto.
            'description' => $descricaoCustom,
        ];

        // ── SEL-463: Roteiro DETERMINISTICO montado pelo backend (SEM LLM externo).
        //    Decisao Ruan 06/08 17:20: "o mesmo que vai organizar ja cria o prompt".
        //    Regras aprendidas SEL-459 embutidas em codigo. LLM externa vira upgrade
        //    opcional, nao dependencia critica. Sem GPT/Gemini/Claude/OpenAI.
        $svc = app(\App\Services\Ai\KaloclipStyleScriptService::class);
        $script = null;
        // SEL (08/08 Ruan): COERENCIA prompt<->video. O roteiro (e o prompt)
        // TEM que ser montado pra MESMA duracao que o motor realmente rende.
        // Omni Flash rende ~8s -> roteiro/fala cabem em 8s e NAO cortam. O piso
        // de 10s do cliente e garantido DEPOIS, no KlingBrowserGenerateJob
        // (segura o ultimo frame ate 10s). Ou seja: prompt = segundos reais,
        // nunca a duracao inflada. $duracaoPrompt e a UNICA fonte de verdade da
        // duracao usada em TODO lugar do prompt.
        $segReais = 8; // duracao real de render do Omni Flash (conta Pro)
        $duracaoPrompt = min($segReais, $duracao);

        // SEL-POV: so pra estilo=pov -- detecta se a foto do produto JA mostra uma
        // mao segurando/tocando (Vision GPT-4o-mini via VideoDirectorService, ja
        // usado pelo endpoint /video-pov). Fallback seguro (assume sem mao) se o
        // Vision falhar -- nesse caso o prompt pede pra "injetar" mao, que e o
        // caminho mais generico (funciona tanto se a foto ja tiver mao quanto se
        // nao tiver). Roda ANTES do build do prompt pra poder influenciar o texto
        // de SUBJECT/ATUACAO que descreve a mao.
        $maoJaPresente = null;
        if ($estilo === 'pov' && ! empty($v['imagem'])) {
            try {
                $handInfo = app(\App\Services\Ai\VideoDirectorService::class)->detectHand($v['imagem']);
                $maoJaPresente = $handInfo['mao_presente'] ?? false;
            } catch (\Throwable $e) {
                Log::warning('[SEL-POV] detectHand falhou, seguindo com mao=null (prompt generico)', ['err' => $e->getMessage()]);
            }
        }

        // ── SEL-roteirocoerente (09/08, Ruan "roteiro desconexo + a pessoa recita a
        //    selecao"): PRIMARIO = o LLM ESCREVE o roteiro (comeco-meio-fim conectado),
        //    usando as escolhas do cliente ($brief) como ANGULO/contexto — nunca como
        //    fala. Devolve audio_diegetic COERENTE + action_timeline prontos. Se o LLM
        //    cair (excecao/JSON invalido/quota), FALLBACK no builder DETERMINISTICO
        //    provado (bloco abaixo, intacto). ────────────────────────────────────
        $tone   = 'informativo';
        // ── SEL-GANCHO (14/08, aula do Ruan) ────────────────────────────────────
        // "quando ele tiver fazendo o video, tem que ter no final uma opcao informando
        //  que o video vai ter a parte 2. Ai voce ja consegue criar algo pra ele
        //  continuar no outro. Entao o video nao tem que ter um fim, ele para no meio."
        //
        // Sem isso, continuar um video e remendo: o primeiro fecha com "corre no link" e
        // nao existe continuacao natural. Marcando, o roteiro termina em LOOP ABERTO.
        $teraParte2 = (bool) ($v['tera_parte2'] ?? false);

        $prompt = null;
        try {
            // SEL-PALAVRA-DO-CLIENTE-DE-VERDADE (16/08): `brief` e embrulhado pelo servico
            // com a instrucao "E PROIBIDO copiar, citar ou LER o nome/descricao de qualquer
            // opcao como fala" — serve pras ESCOLHAS de menu, e nao pro texto que o cliente
            // digitou. Mandar o pedido dele por ali era entregar a palavra do cliente e
            // proibir o roteirista de usa-la. O slot certo ja existia e so o chat usava:
            // `client_brief` ("PEDIDO LITERAL DO CLIENTE (LEI — obedeca, nao reescreva)").
            $pedidoLiteral = trim(
                (string) ($this->pedidoPromptColado ?? '')
                . "\n" . (string) ($v['roteiro_cliente'] ?? '')
            );

            $llm = $svc->generate($produto, $duracaoPrompt, $tone, array_filter([
                'lang'         => 'pt-BR',
                'brief'        => $brief,   // escolhas de MENU = angulo, nao fala
                'estilo'       => $estilo,  // zero/ugc = fala direto; pov/showcase = voz em off
                'client_brief' => $pedidoLiteral !== '' ? $pedidoLiteral : null,
            ]));
            if (empty($llm['audio_diegetic']) || empty($llm['action_timeline'])) {
                throw new \RuntimeException('LLM devolveu roteiro sem fala/timeline');
            }
            // Aplica SO os campos VISUAIS por estilo (subject/framing/negative/camera/
            // produto), PRESERVANDO o audio_diegetic COERENTE do LLM. NAO chama
            // enrichScriptForPrompt (ele reconstruiria a fala a partir das sections e
            // apagaria a narracao do LLM).
            $script = $this->aplicarVisualPorEstilo($llm, $estilo, $v, $cameraTexto, $maoJaPresente);
            // SEL-PALAVRA-DO-CLIENTE-DE-VERDADE (16/08): ESTA linha faltava. Sem ela, o
            // caminho do LLM — que e por onde passam ~86% dos pedidos — montava o prompt
            // sem uma palavra do cliente e sem o cenario que ele subiu.
            $script = $this->carimbarPedidoDoCliente($script);
            $prompt = $svc->toKlingPrompt($script);
            Log::error('[SEL-roteirocoerente] roteiro via LLM', [
                'estilo' => $estilo,
                'fala'   => mb_substr((string) ($script['audio_diegetic'] ?? ''), 0, 140),
            ]);
        } catch (\Throwable $eLlm) {
            Log::warning('[SEL-roteirocoerente] LLM indisponivel, fallback deterministico', ['err' => $eLlm->getMessage()]);
        }

        if ($prompt !== null) {
            // roteiro do LLM ja montou o prompt — nada a fazer no bloco determinístico.
        } else
        try {
            $script = $this->buildDeterministicScript($v, $duracaoPrompt, $cameraTexto, $brief);
            $script = $this->enrichScriptForPrompt($script, $estilo, $duracaoPrompt, $maoJaPresente);
            $prompt = $svc->toKlingPrompt($script);
        } catch (\Throwable $e) {
            Log::error('[SEL-463] builder deterministico falhou (BUG grave, nao devia acontecer)', ['err' => $e->getMessage()]);
            // FALLBACK ESTATICO: monta script minimo sem depender de LLM.
            // Motivo: tunnel Cloudflare -> DICloak ChatGPT SN 7 caiu; Gemini free tier esgotou;
            // OpenAI sem credito. Isso desbloqueia cliente enquanto tunnel volta.
            $script = [
                'lang'    => 'pt-BR',
                'scene'   => $v['cenario'] ?? 'ambiente residencial iluminado',
                'camera'  => $cameraTexto,
                'sections' => [
                    ['section' => 'abertura',   'dialogue' => 'Olha só que produto é esse, gente!', 'visual_intent' => 'apresentando o ' . mb_substr($v['produto_nome'], 0, 60)],
                    ['section' => 'meio',       'dialogue' => 'Ó como é fácil de usar, olha.',       'visual_intent' => 'usando o produto de verdade, passo a passo'],
                    ['section' => 'fechamento', 'dialogue' => 'Garante o seu agora, vale muito!',    'visual_intent' => 'chamando pra comprar no link'],
                ],
                'performance' => 'atriz falando direto pra camera, autentica, tom UGC brasileiro',
                'presentation' => 'video vertical 9:16 estilo UGC brasileiro autentico',
                'product_desc' => $this->buildProductDesc($v), // SEL-490 Layer C
            ];
            if (! empty($v['cenario'])) $script['scene'] = $v['cenario'];
            try {
                $script = $this->enrichScriptForPrompt($script, $estilo, $duracaoPrompt, $maoJaPresente);
                $prompt = $svc->toKlingPrompt($script);
            } catch (\Throwable $ee) {
                $prompt = "PRODUTO: {$v['produto_nome']}
CENARIO: {$script['scene']}
CAMERA: {$cameraTexto}
DURACAO: {$duracaoPrompt}s
ESTILO: UGC brasileiro autentico, video vertical 9:16, camera na mao";
            }
        }

        // ── SEL-486b (hotfix em producao, NAO commitado): trazer o MAXIMO de
        //    imagens de referencia do produto (ate 7, teto do Kling) e NORMALIZAR
        //    cada uma (webp->jpg, URL sem extensao, segue 302) antes do Kling.
        //    Antes so ia 1 foto (animar_produto). Fail-open: erro -> 1 imagem. ──
        // SEL-460 Bug1: se o frontend mandou image_urls[] (galeria Kalodata), usa diretamente.
        $refUrls = [];
        $frontImageUrls = array_values(array_filter(is_array($v['image_urls'] ?? null) ? $v['image_urls'] : []));
        if (count($frontImageUrls) > 0) {
            $refUrls = array_slice($frontImageUrls, 0, 7);
            Log::error('[SEL-460] usando image_urls do frontend', ['count' => count($refUrls)]);
        } elseif (! empty($v['imagem'])) {
            $refUrls[] = $v['imagem'];
            try {
                $cp = \App\Models\ClientProduct::where('image_url', $v['imagem'])->first();
                if ($cp) {
                    $col = app(\App\Services\Ai\ProductReferenceCollector::class)->collect((int) $cp->id);
                    foreach (($col['images'] ?? []) as $im) {
                        if (! empty($im['url'])) { $refUrls[] = $im['url']; }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[maisimagens] collect falhou', ['err' => $e->getMessage()]);
            }
            $refUrls = array_slice(array_values(array_unique(array_filter($refUrls))), 0, 7);
        }
        $refsNorm = [];
        $t0 = microtime(true);
        foreach ($refUrls as $ru) {
            if (microtime(true) - $t0 > 25) { break; } // orcamento de tempo do request
            $n = $this->normalizarRef($ru);
            if ($n) { $refsNorm[] = $n; }
        }
        // ══ SEL-FOTO-OU-NADA (16/08) ═════════════════════════════════════════════
        //
        // Aqui morava um fail-open: quando NENHUMA foto baixava, o codigo guardava a
        // URL quebrada e seguia como se tivesse foto. Dai pra frente cada camada
        // acreditava na anterior — o tipo virava 'animar_produto', o adapter nao achava
        // arquivo nenhum, e o motor recebia so TEXTO. Resultado que chegou no cliente:
        // video de OUTRO produto, entregue como se fosse o dele.
        //   #1332 e #1330 (16/08): entregues com image_path inexistente e image_refs
        //   vazio. O cliente pediu uma pulseira. A foto vinha de um host que esta fora
        //   do ar (curl na raiz = http 000, com e sem referer: nao e hotlink, e a
        //   maquina que nao responde).
        //
        // Agora o pedido PARA antes de gerar, com recado claro. Vale a pena trocar um
        // video errado por um recado certo: o errado o cliente so descobre depois de
        // esperar, e ainda acha que a fabrica inteira e ruim.
        //
        // O alvo e SO a URL remota que nao desceu. Foto nossa (arquivo/storage) mantem
        // o fail-open de sempre, e estilo sem foto nem passa por aqui.
        if (empty($refsNorm) && ! empty($v['imagem'])) {
            $ehRemota = (bool) preg_match('#^https?://#i', (string) $v['imagem']);
            $ehNossa  = str_contains((string) $v['imagem'], 'api.seller.global')
                     || str_contains((string) $v['imagem'], '/storage/');

            if ($ehRemota && ! $ehNossa) {
                Log::error('[SEL-FOTO-OU-NADA] a foto do produto nao desceu — pedido recusado ANTES de gerar', [
                    'url'         => mb_substr((string) $v['imagem'], 0, 160),
                    'quantas_ref' => count($refUrls),
                    'user'        => $request->user()?->id,
                    'porque'      => 'sem esta foto o motor inventaria outro produto e nos entregariamos o erro',
                ]);

                return response()->json([
                    'ok'      => false,
                    'message' => 'Nao conseguimos baixar a foto deste produto — o site onde ela esta hospedada '
                        . 'nao respondeu. Como sem a foto o video sairia de outro produto, preferimos parar aqui. '
                        . 'Envie a foto do produto direto do seu computador e geramos na hora.',
                    'code'    => 'foto_indisponivel',
                ], 422);
            }

            $refsNorm = [$v['imagem']]; // foto nossa: fail-open de sempre
        }
        $imgPrincipal = $refsNorm[0] ?? ($v['imagem'] ?? null);
        $imgRefs      = array_slice($refsNorm, 1);
        $temMulti     = count($refsNorm) >= 2;

        // ── image2video (1 imagem) DERIVA o formato da foto -> se a foto nao for
        //    9:16, o video sai quadrado/paisagem. Forca 1080x1920 com fundo
        //    desfocado (blur-fill, igual "modo desfoque"). No multi (omni_refs) a
        //    tela omni ja fixa 9:16, entao so mexe no caso single. ──────────────
        if (! $temMulti && ! empty($v['imagem'])) {
            $origCover = $refUrls[0] ?? $v['imagem'];
            $c916 = $this->normalizarRef($origCover, true);
            if ($c916) { $imgPrincipal = $c916; $refsNorm = [$c916]; $imgRefs = []; }
        }

        // ── Pipeline. cena_com_referencias (multiImageToVideo, ate 7) quando ha
        //    >=2 imagens boas; senao animar_produto (image2video, 1 foto). ──────
        $pipelineType = ($estilo === 'zero' || empty($v['imagem']))
            ? 'video_do_zero'
            : ($temMulti ? 'cena_com_referencias' : 'animar_produto');
        Log::info('[maisimagens] refs normalizadas', ['total' => count($refsNorm), 'pipeline' => $pipelineType]);

        // ── SEL-CUSTOMPRODUCT (avatar): quando o cliente ESCOLHEU um avatar pra
        //    esta geracao (opt-in — sem avatar_id/avatar_url nada muda, fluxo atual
        //    intacto), a foto do avatar vira a REFERENCIA DE PESSOA do video, junto
        //    com o produto. So faz sentido em 'ugc' (apresentadora fala pra camera);
        //    pov (so maos) e showcase (sem pessoa) NUNCA usam avatar — a regra dura
        //    do estilo (aplicarVisualPorEstilo/enrichScriptForPrompt) ja proibe rosto
        //    nesses dois, entao um avatar ali seria contraditorio com o proprio prompt.
        //    Pipeline usado e o MESMO 'avatar_apresentando' que o Modo A (AiVideoPerfect)
        //    ja usa hoje — StudioGenerationJob:L292 (Kling image2video com elements
        //    avatar+subject). Precisa de $imgPrincipal (produto) pra fazer sentido.
        $avatarUrl = null;
        if ($estilo === 'ugc' && $pipelineType !== 'video_do_zero' && ! empty($imgPrincipal)) {
            $avatarIdIn  = $v['avatar_id']  ?? null;
            $avatarUrlIn = $v['avatar_url'] ?? null;
            $avatarSource = null; // source da linha em client_video_avatars, quando resolvivel
            $clientId = optional($user?->client)->id;

            if ($avatarIdIn && $clientId) {
                // caminho recomendado: id da lista `mine_all` (/videostudio/avatars),
                // escopado por client_id — impossivel usar avatar de outro cliente.
                $row = \Illuminate\Support\Facades\DB::table('client_video_avatars')
                    ->where('id', $avatarIdIn)
                    ->where('client_id', $clientId)
                    ->where('is_active', 1)
                    ->first();
                if ($row) {
                    $avatarSource = $row->source ?? 'pool_shared';
                    $avatarUrl = $row->custom_avatar_url ?: (
                        $row->video_avatar_id
                            ? \Illuminate\Support\Facades\DB::table('video_avatars')->where('id', $row->video_avatar_id)->value('image_url')
                            : null
                    );
                } else {
                    Log::warning('[SEL-CUSTOMPRODUCT][avatar] avatar_id nao encontrado ou nao pertence ao cliente', ['avatar_id' => $avatarIdIn, 'client_id' => $clientId]);
                }
            } elseif ($avatarUrlIn && $clientId) {
                // fallback: URL direta. Tenta achar a fonte (pra aplicar o mesmo check
                // anti-strike) casando com uma linha do PROPRIO cliente; se nao achar,
                // segue sem o check (mesma postura ja adotada pra `imagem`/`image_urls`
                // no restante deste metodo, que tambem aceitam URL crua sem dono).
                $avatarUrl = $avatarUrlIn;
                $row = \Illuminate\Support\Facades\DB::table('client_video_avatars')
                    ->where('client_id', $clientId)
                    ->where('is_active', 1)
                    ->where('custom_avatar_url', $avatarUrlIn)
                    ->first();
                $avatarSource = $row->source ?? null;
            }

            // Anti-strike (mesma regra do avatar_apresentando em outros fluxos): avatar
            // de pool_shared (compartilhado entre clientes) NAO pode virar rosto fixo
            // do video — risco de strike no TikTok por avatar repetido. So bloqueia
            // quando da pra confirmar a fonte; URL nao rastreavel passa (fail-open,
            // igual ao resto do metodo).
            if ($avatarUrl && $avatarSource === 'pool_shared') {
                Log::warning('[SEL-CUSTOMPRODUCT][avatar] avatar pool_shared recusado (anti-strike)', ['client_id' => $clientId]);
                $avatarUrl = null;
            }

            if ($avatarUrl) {
                $pipelineType = 'avatar_apresentando';
                Log::error('[SEL-CUSTOMPRODUCT][avatar] avatar aplicado como pessoa do video', [
                    'avatar_url' => $avatarUrl,
                    'source'     => $avatarSource,
                ]);
            }
        }

        // ── SEL-30s: vídeo longo (> 1 clipe) costurando N shots de ~10s numa peça
        //    editada com cortes de câmera na batida da narração. Só desvia quando a
        //    FLAG esta ON, a duração passa de 1 clipe, existe foto do produto pra
        //    semear os shots e o PLANO libera. Senão cai no fluxo curto abaixo,
        //    intacto. Fail-open: qualquer erro aqui volta pro fluxo curto. ────────
        // ── SEL-MEMORIA (12/08, ideia do Ruan: "os prompts que deram certo a gente
        //    reutiliza; de repente so troca o personagem"). A chave do produto e
        //    calculada AQUI (mesma expressao que ja era usada na criacao da pipeline,
        //    logo abaixo) pra dar pra consultar a memoria ANTES de inventar prompt novo.
        $productKey = md5(($v['imagem'] ?? '') . $v['produto_nome']);

        $clipSecs = (int) config('services.sel30s.clip_seconds', 10);

        // SEL-NAOPARTIRATOA (12/08) — nao partir o video por causa de uma sobra pequena.
        //
        // MEDIDO em 30 dias, so pedido de cliente:
        //     8s    23 pedidos ->  19 entregues   83%   (1 corte)
        //    10s   611 pedidos -> 200 entregues   33%   (2 cortes)  <- 81% do volume
        //
        // Com corte de 8s, um pedido de 10s virava DOIS cortes: dois motores, duas
        // geracoes e duas chances de morrer, pra ganhar 2 SEGUNDOS. O tempo de geracao
        // e por VIDEO GERADO (60-140s), nao por segundo de video — entao partir em dois
        // dobra o custo. Com a frota disputada, o segundo corte espera vaga e o relogio
        // do video inteiro estoura ("a geracao demorou demais", o erro campeao do dia).
        //
        // Regra: so parte quando a sobra JUSTIFICA. Ate 25% acima do corte maximo,
        // entrega em UM corte. 8 segundos entregues valem mais que 10 que nunca saem.
        $limiteParaPartir = (float) $clipSecs * (float) config('services.sel30s.tolerancia', 1.25);

        if ((bool) config('services.sel30s.enabled', false)
            && $duracao > $limiteParaPartir
            && ! empty($imgPrincipal)
            && $this->planPermiteLongo($user)) {
            $maxSeg = (int) config('services.sel30s.max_segments', 3);
            // SEL-FALAREPETIDA (12/08): o piso de 2 cortes fazia 10s virar 16s
            // (2 clipes de 8s). Agora o nº de cortes vem da duração PEDIDA e cada
            // corte recebe o seu tamanho, fechando exatamente no que o cliente pediu.
            $durPedida = min($duracao, $clipSecs * $maxSeg);
            $N         = max(1, min((int) ceil($durPedida / $clipSecs), $maxSeg));
            $base      = round($durPedida / $N, 2);
            $lens      = array_fill(0, $N, $base);
            $lens[$N - 1] = round($durPedida - $base * ($N - 1), 2);
            $cena   = $script['scene'] ?? ($v['cenario'] ?? '');
                $beats  = $this->buildNarrationBeats($v, $N, $clipSecs);
            $shots  = $this->buildShotPrompts($v, $estilo, $cameraTexto, $cena, $N, $clipSecs, $beats, $lens);

            // ── SEL-MEMORIA: antes de mandar prompt NOVO, procura RECEITA que ja deu
            //    video APROVADO pra este mesmo (produto, estilo, duracao). Laudo 'ok' e
            //    cliente sem roteiro proprio -> reusa a fala E os cortes que funcionaram;
            //    se ele escreveu roteiro/inicio/meio/fim, o texto DELE vence e so a
            //    estrutura e reaproveitada. Sempre com variacao (angulo/luz/cenario) pra
            //    dois clientes NUNCA receberem o mesmo video. Fail-open: erro ou receita
            //    incompativel segue com $beats/$shots novos — fluxo atual intacto.
            $receitaMeta = [];
            try {
                $mem = \App\Services\Ai\VideoPromptMemory::montar([
                    'product_key' => $productKey,
                    'estilo'      => $estilo,
                    'duracao'     => $duracao,
                    'n'           => $N,
                    'lens'        => $lens,
                    'v'           => $v,
                    'user_id'     => (int) ($user->id ?? 0),
                    'beats_novos' => $beats,
                ]);
                if ($mem && ! empty($mem['clip_prompts'])) {
                    $beats       = $mem['narration_beats'];
                    $shots       = $mem['clip_prompts'];
                    $receitaMeta = $mem['meta'];
                }
            } catch (\Throwable $eMem) {
                Log::warning('[SEL-MEMORIA] memoria indisponivel, seguindo com prompt novo', ['err' => $eMem->getMessage()]);
            }
            try {
                $pipelineLongo = AiVideoPipeline::create([
                    'user_id'     => $user->id,
                    'mode'        => 'studio_long_' . $estilo,
                    'product_key' => $productKey,
                    'step'        => 'queued',
                    'payloads'    => [
                        'pipeline'        => 'video_longo',
                        'long_video'      => true,
                        'duration'        => $duracao,
                        'clip_seconds'    => $clipSecs,
                        'clip_lens'       => $lens,   // SEL-FALAREPETIDA: tamanho de CADA corte
                        'n_segments'      => $N,
                        'aspect_ratio'    => '9:16',
                        'image_url'       => $imgPrincipal,
                        'narration_beats' => $beats,
                        'narration_text'  => trim(implode(' ', $beats)),
                        'clip_prompts'    => $shots,
                        'prompt'          => $shots[0] ?? $prompt,
                        'estilo'          => $estilo,
                        'lang'            => 'pt-BR',
                        'camera_id'       => $cameraId,
                        'opcoes_mode'     => true,
                        '_priority'       => $ehPrioridade ? 'high' : null, // SEL-490
                        'quality_tier'    => $qualityTier,
                        'veo_model'       => in_array($qualityTier, ['ultra', 'ilimitado'], true) ? 'Veo 3.1 - Quality' : 'Omni Flash',
                        // SEL-MEMORIA: a fala saiu do TEXTO DO CLIENTE? Se saiu, esta
                        // geracao NUNCA vira receita — senao o roteiro pessoal de um
                        // cliente ("meu ape nao tem gas...") seria falado no video de
                        // outro. Receita so nasce de fala montada pelo sistema.
                        '_fala_do_cliente' => trim((string) ($v['roteiro_cliente'] ?? '')) !== '',
                        // SEL-MEMORIA: de qual receita esta geracao nasceu (vazio quando
                        // foi prompt novo). E o que deixa o aprendizado saber depois se o
                        // reuso deu certo (video:memoria-aprende).
                    ] + $receitaMeta,
                    'dry_run' => (bool) config('services.ai_video.dry_run', false),
                ]);

                \App\Services\Ai\VideoPlanLimitService::attachPipeline($reservationId, $pipelineLongo->id);
                if (! empty($receitaMeta['_receita_id'])) {
                    \App\Services\Ai\VideoPromptMemory::registrarUso(
                        (int) $receitaMeta['_receita_id'],
                        (int) $pipelineLongo->id
                    );
                }
                // SEL-ruan-lane (08/08): super_admin (Ruan) tem fila EXCLUSIVA com worker
                // dedicado -> nunca espera atras de cliente. Demais: priority/normal por plano.
                $filaVideo = (($user->role ?? null) === 'super_admin')
                    ? 'video-ruan'
                    : ($ehPrioridade ? 'video-priority' : 'video');
                \App\Jobs\StudioLongVideoJob::dispatch($pipelineLongo->id)
                    ->onQueue($filaVideo);

                Log::error('[SEL-30s] geração longa despachada', [
                    'receita'     => $receitaMeta['_receita_id'] ?? null,       // SEL-MEMORIA
                    'reuso'       => $receitaMeta['_receita_reuso'] ?? 'prompt novo',
                    'pipeline_id' => $pipelineLongo->id,
                    'estilo'      => $estilo,
                    'duracao'     => $duracao,
                    'clipes'      => $N,
                ]);

                return response()->json([
                    'pipeline_id' => $pipelineLongo->id,
                    'eta_seconds' => $duracao * 8 + 60,
                    'poll_url'    => url("/api/v1/ai/video-pipeline/{$pipelineLongo->id}"),
                ]);
            } catch (\Throwable $e) {
                Log::error('[SEL-30s] falha ao despachar longo, caindo pro fluxo curto', ['err' => $e->getMessage()]);
                // fail-open: segue pro fluxo curto (1 clipe) abaixo
            }
        }

        try {
            // SEL-GANCHO: a regra entra NO FIM do prompt, que e onde o modelo decide
            // como fechar. Nao inventa fala nova — so proibe o fechamento.
            if ($teraParte2 && is_string($prompt) && $prompt !== '') {
                $prompt .= "\n\n" . 'IMPORTANTE — ESTE VIDEO NAO PODE TERMINAR: e a PARTE 1 de uma serie. '
                    . 'NAO feche o assunto, NAO diga "corre no link", NAO se despeca e NAO faca gesto de '
                    . 'encerramento. Termine no meio da ideia, com a pessoa prestes a contar mais, deixando '
                    . 'o espectador querendo a continuacao. O ultimo segundo e de expectativa, nao de fecho.';
            }

            $pipeline = AiVideoPipeline::create([
                'user_id'     => $user->id,
                'mode'        => 'studio_opcoes_' . $estilo,
                'product_key' => $productKey,
                'step'        => 'queued',
                'payloads'    => [
                    'pipeline'     => $pipelineType,
                    'prompt'       => $prompt,
                    'duration'     => $duracao,
                    // SEL-PAISAGEM-LIBERADA (15/08, Ruan): "deixa fixado o RETRATO, que e o
                    // que todo mundo usa, mas deixa LIBERADO se eles quiserem paisagem".
                    // Antes isto era chumbado em 9:16 e o que o cliente escolhia era jogado
                    // fora — o seletor novo do Estudio mandava aspect_ratio e ninguem lia.
                    // Retrato segue sendo o PADRAO (quem nao escolhe, recebe 9:16 como
                    // sempre). So aceita os dois formatos que os motores entregam mesmo.
                    'aspect_ratio' => in_array($v['aspect_ratio'] ?? null, ['9:16', '16:9'], true)
                        ? $v['aspect_ratio']
                        : '9:16',
                    'image_url'    => $imgPrincipal,
                    'image_refs'   => $imgRefs,
                    // SEL-CUSTOMPRODUCT (avatar): so preenchido quando pipelineType ==
                    // 'avatar_apresentando' (ver bloco acima) — StudioGenerationJob:L292
                    // ja le este campo pro Kling elements[avatar]. null nos demais casos,
                    // comportamento identico ao de antes.
                    'avatar_url'   => $avatarUrl,
                    'gear'         => 'recomendado',
                    'lang'         => 'pt-BR',
                    'estilo'       => $estilo,
                    'camera_id'    => $cameraId,
                    // SEL-MUDO-PAYLOAD (14/08): o job precisa saber que o cliente pediu
                    // SEM SOM pra remover a trilha de audio do mp4 no fim. Sem esta linha,
                    // o pedido morria no controller e o arquivo saia com voz.
                    'sem_som'      => (array_key_exists('com_som', $v) && $v['com_som'] === false),
                    // SEL-VOZ-NOSSA-NO-VIDEO (16/08): quando o cliente escolhe "so narracao",
                    // o motor entrega mudo e a NOSSA voz le o roteiro por cima. O texto vai
                    // junto aqui pra o job nao precisar remontar nada.
                    'narracao'       => ! empty($v['narracao']),
                    'narracao_texto' => ! empty($v['narracao'])
                        ? trim((string) ($script['audio_diegetic'] ?? $script['speech'] ?? ''))
                        : null,
                    'opcoes_mode'  => true,
                    // SEL-490: prioridade/qualidade por plano. _priority=high faz o
                    // KlingBrowserService rotear pra fila kling-browser-priority.
                    '_priority'    => $ehPrioridade ? 'high' : null,
                    'quality_tier' => $qualityTier,
                    'veo_model'    => in_array($qualityTier, ['ultra', 'ilimitado'], true) ? 'Veo 3.1 - Quality' : 'Omni Flash',
                ],
                'dry_run' => (bool) config('services.ai_video.dry_run', false),
            ]);

            \App\Services\Ai\VideoPlanLimitService::attachPipeline($reservationId, $pipeline->id);

            // SEL-490: Ultra fura a fila (video-priority); demais planos na fila normal.
            // SEL-ruan-lane (08/08): super_admin (Ruan) tem fila EXCLUSIVA video-ruan
            // com worker dedicado -> entra na hora, nunca espera atras de cliente.
            $filaVideo = (($user->role ?? null) === 'super_admin')
                ? 'video-ruan'
                : ($ehPrioridade ? 'video-priority' : 'video');
            \App\Jobs\StudioGenerationJob::dispatch($pipeline->id)
                ->onQueue($filaVideo);

            Log::error('[SEL-452] geracao por opcoes despachada', [
                'pipeline_id' => $pipeline->id,
                'estilo'      => $estilo,
                'duracao'     => $duracao,
                'camera'      => $cameraId,
            ]);

            return response()->json([
                'pipeline_id' => $pipeline->id,
                'eta_seconds' => $duracao * 6 + 30,
                'poll_url'    => url("/api/v1/ai/video-pipeline/{$pipeline->id}"),
            ]);
        } catch (\Throwable $e) {
            // SEL-490: falhou ao enfileirar -> devolve a cota (não penaliza o cliente).
            \App\Services\Ai\VideoPlanLimitService::refund($reservationId);
            Log::error('[SEL-452] falha ao despachar', ['err' => $e->getMessage()]);
            return response()->json([
                'error'   => 'falha_ao_iniciar',
                'message' => 'Deu um erro ao iniciar a geracao. Tenta de novo?',
            ], 500);
        }
    }

    /**
     * SEL-486b (hotfix em producao, NAO commitado) — baixa uma imagem de referencia
     * (segue 302, User-Agent de navegador) e re-encoda pra JPG via ffmpeg, hospedando
     * em storage publico. Resolve os 3 furos que faziam a foto do produto NAO entrar:
     * webp (Kling rejeita conteudo), URL sem extensao (Shopee CDN) e 302 (goolhub legado).
     * Retorna a URL .jpg publica, ou null se falhar (o chamador e fail-open).
     */
    private function normalizarRef(string $url, bool $pad916 = false): ?string
    {
        try {
            // SEL-FOTOERRADA (12/08): o Referer era FIXO em shopee.com.br pra TODA
            // foto. A CDN do TikTok/Kalodata (img.kalocdn.com) responde 403 quando
            // ve Referer de outro dominio — provado no servidor: com Referer shopee
            // http=403/0 bytes, sem Referer http=200/36KB. Resultado: a foto do
            // produto que o cliente ESCOLHEU nao baixava, caia no fail-open (URL
            // crua), o motor tambem nao conseguia baixar e o video saia SEM a
            // referencia visual -> gerava OUTRO produto. Agora tenta uma sequencia
            // de perfis de cabecalho e para no primeiro que trouxer imagem.
            $ua   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36';
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $refs = [null];                                   // 1o: sem Referer (kalocdn/tiktok exigem isso)
            if (str_contains($host, 'shopee') || str_contains($host, 'susercontent')) {
                array_unshift($refs, 'https://shopee.com.br/');
            }
            $refs[] = 'https://' . $host . '/';                // 3o: mesma origem
            $refs[] = 'https://shopee.com.br/';                // ultimo: comportamento antigo

            $bin = false;
            $refUsado = null;
            foreach (array_values(array_unique($refs, SORT_REGULAR)) as $ref) {
                $hdr  = 'User-Agent: ' . $ua . "\r\n";
                $hdr .= "Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8\r\n";
                if ($ref) { $hdr .= 'Referer: ' . $ref . "\r\n"; }
                $opts = [
                    'timeout'         => 10,
                    'follow_location' => 1,
                    'max_redirects'   => 5,
                    'ignore_errors'   => true,
                    'header'          => $hdr,
                ];
                $ctx = stream_context_create(['http' => $opts, 'https' => $opts]);
                $try = @file_get_contents($url, false, $ctx);
                if ($try !== false && strlen($try) >= 800) {
                    $bin = $try;
                    $refUsado = $ref ?: '(sem referer)';
                    break;
                }
            }
            if ($bin === false) {
                // Falha nunca silenciosa: sem a foto, o video sai do produto ERRADO.
                Log::error('[SEL-FOTOERRADA] nao consegui baixar a foto do produto em NENHUM perfil de cabecalho', [
                    'url'  => mb_substr($url, 0, 160),
                    'host' => $host,
                ]);
                return null;
            }
            if ($refUsado !== null && $refUsado !== 'https://shopee.com.br/') {
                Log::error('[SEL-FOTOERRADA] foto baixada com perfil alternativo', ['host' => $host, 'referer' => $refUsado]);
            }
            $tmp = sys_get_temp_dir() . '/refsrc_' . md5($url) . '_' . uniqid();
            @file_put_contents($tmp, $bin);
            $fname = 'ref_' . date('Ymd') . '_' . substr(md5($url), 0, 12) . ($pad916 ? '_916' : '') . '.jpg';
            $dest  = storage_path('app/public/tt-media/' . $fname);
            if (! is_dir(dirname($dest))) {
                @mkdir(dirname($dest), 0755, true);
            }
            // SEL-MARCA-NAO-VAZA (15/08) — a faixa de baixo SAI antes de qualquer coisa.
            // Um cliente real (3758) recebeu video com a palavra "Veo" queimada no pixel:
            // a foto de referencia era reciclada de uma geracao anterior do proprio motor,
            // e o brandstrip so limpa METADADO (-map_metadata -1), nunca olhou pixel.
            // Metadado e o que a gente controlava; pixel e o que o cliente VE.
            // 9% de baixo cobre onde os geradores carimbam. Cortar+reesticar foi o unico
            // dos tres jeitos testados que nao deixa remendo (delogo risca, boxblur deixa
            // um quadrado de tom diferente).
            $semMarca = 'crop=iw:ih*0.91:0:0,';

            if ($pad916) {
                // 9:16 com fundo desfocado: escala a foto pra caber e preenche as
                // bordas com uma copia ampliada e borrada da propria foto.
                $proc = new \Symfony\Component\Process\Process(['ffmpeg', '-y', '-i', $tmp,
                    '-filter_complex',
                    '[0:v]' . $semMarca . 'scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=30:1[bg];'
                    . '[0:v]' . $semMarca . 'scale=1080:1920:force_original_aspect_ratio=decrease[fg];'
                    . '[bg][fg]overlay=(W-w)/2:(H-h)/2',
                    '-q:v', '3', $dest]);
            } else {
                $proc = new \Symfony\Component\Process\Process(['ffmpeg', '-y', '-i', $tmp,
                    '-vf', $semMarca . 'scale=iw:ih/0.91',
                    '-q:v', '3', $dest]);
            }
            $proc->setTimeout(15);
            $proc->run();
            @unlink($tmp);
            if (! is_file($dest) || filesize($dest) < 800) {
                return null;
            }
            @chmod($dest, 0644);
            return rtrim(env('APP_URL', 'https://api.seller.global'), '/') . '/storage/tt-media/' . $fname;
        } catch (\Throwable $e) {
            Log::warning('[maisimagens] normalizarRef falhou', ['url' => mb_substr($url, 0, 80), 'err' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * SEL-POV-FIX — `buildDeterministicScript()` monta o roteiro com as chaves
     * `presentation/performance/sections/style_notes/lang`, mas
     * `KaloclipStyleScriptService::toKlingPrompt()` (quem monta o TEXTO final que
     * vai pro Kling/Veo) le OUTRAS chaves: `style/subject/action_timeline/
     * framing/lighting_color/audio_diegetic/audio_timing/negative/_lang`. Os
     * nomes nao batem — o metodo nunca lancava erro (tudo `?? ''`/`?? []`), so
     * saia silenciosamente com essas linhas vazias no prompt final. Na pratica:
     * o que o cliente digitava (inicio/meio/fim, roteiro_cliente) NUNCA chegava
     * no video, em NENHUM estilo (ugc/pov/showcase/zero) — so sobrevivia
     * `scene`/`camera`/`performance`, que por coincidencia tem o mesmo nome nos
     * dois lados.
     *
     * Este metodo faz a PONTE: pega o `$script` no formato que
     * `buildDeterministicScript()` (ou o fallback estatico) produz e preenche as
     * chaves que faltam, SEM remover as originais (documentacao/consumidores
     * futuros continuam enxergando `presentation`/`style_notes` do jeito que
     * sempre estiveram). Chamado nos dois lugares que geram `$script` antes de
     * `toKlingPrompt()` — caminho normal e fallback estatico.
     *
     * @param array     $script         Saida de buildDeterministicScript() ou do fallback estatico
     * @param string    $estilo         ugc|pov|showcase|zero
     * @param int       $duracao        Duracao efetiva do roteiro em segundos (a mesma usada pra montar $script)
     * @param bool|null $maoJaPresente  SEL-POV: so importa quando $estilo==='pov'. true = a foto de
     *                                  referencia JA mostra uma mao segurando o produto (continua ela);
     *                                  false = a foto so tem o produto sozinho (injeta mao realista
     *                                  entrando em quadro); null = nao verificado (Vision indisponivel/
     *                                  nao chamado) -- cai no texto generico que cobre os dois casos.
     */
    private function enrichScriptForPrompt(array $script, string $estilo, int $duracao, ?bool $maoJaPresente = null): array
    {
        // subject — SEL-POV: pov nunca tem gente em quadro, so maos. Os outros
        // estilos usam a apresentadora escolhida (ou o texto padrao que
        // buildDeterministicScript ja calcula em $preset/$apresent).
        $subjectPov = match ($maoJaPresente) {
            true  => 'a MESMA mao que ja aparece segurando o produto na foto de referencia continua segurando e mostrando o produto — nao adicione uma segunda mao nem nenhuma pessoa. ARCO DA CENA, nesta ordem: o pacote/embalagem aparece no colo de quem filma, as maos abrem/rasgam a embalagem, tiram o produto de dentro e so entao o produto e mostrado e usado de perto — como se tudo estivesse sendo gravado de verdade, na hora. No FIM do video a mao SAI DE QUADRO por completo, deslizando pra fora pela lateral ou por baixo, e o ultimo instante mostra o produto SOZINHO no mesmo enquadramento. A cena, a luz e o enquadramento nao mudam do primeiro ao ultimo frame',
            false => 'a foto de referencia mostra so o produto sozinho — uma mao humana realista ENTRA em quadro (pela parte de baixo/lateral), pega e mostra o produto. Nenhuma pessoa, rosto ou corpo aparece. ARCO DA CENA, nesta ordem: o pacote/embalagem aparece no colo de quem filma, as maos abrem/rasgam a embalagem, tiram o produto de dentro e so entao o produto e mostrado e usado de perto — como se tudo estivesse sendo gravado de verdade, na hora. No FIM do video a mao SAI DE QUADRO por completo, deslizando pra fora pela lateral ou por baixo, e o ultimo instante mostra o produto SOZINHO no mesmo enquadramento. A cena, a luz e o enquadramento nao mudam do primeiro ao ultimo frame',
            default => 'apenas as MAOS de quem esta segurando o produto — nenhuma pessoa, rosto ou corpo aparece em nenhum momento do video. ARCO DA CENA, nesta ordem: o pacote/embalagem aparece no colo de quem filma, as maos abrem/rasgam a embalagem, tiram o produto de dentro e so entao o produto e mostrado e usado de perto — como se tudo estivesse sendo gravado de verdade, na hora. No FIM do video a mao SAI DE QUADRO por completo, deslizando pra fora pela lateral ou por baixo, e o ultimo instante mostra o produto SOZINHO no mesmo enquadramento. A cena, a luz e o enquadramento nao mudam do primeiro ao ultimo frame',
        };
        $subjectByEstilo = [
            'pov'      => $subjectPov,
            'showcase' => 'nenhuma pessoa visivel — o produto e o unico protagonista, estilo still-life de estudio',
            'zero'     => $this->textoApresentador(),
        ];
        $script['subject'] = $subjectByEstilo[$estilo] ?? $this->textoApresentador();

        // style — toKlingPrompt espera um resumo curto de estilo visual;
        // `presentation` (que buildDeterministicScript ja calcula por estilo) e
        // exatamente isso.
        // SEL-SEM-SOM-LIGA (14/08): carimba no roteiro que o cliente pediu MUDO. O
        // construtor do prompt (KaloclipStyleScriptService::toKlingPrompt) le isto e
        // troca o bloco de FALA pela proibicao de falar. Sem este carimbo, o pedido
        // ficava so no brief e o motor continuava fazendo a pessoa falar — foi
        // exatamente o que o Ruan viu no anuncio dele ("selecionei sem audio e saiu a voz").
        if (isset($this->pedidoSemSom) && $this->pedidoSemSom === true) {
            $script['sem_som']       = true;
            $script['movimentos']    = $this->pedidoMovimentos ?? null;
        }
        // SEL-PALAVRA-DO-CLIENTE-DE-VERDADE (16/08): o carimbo saiu daqui e virou etapa
        // COMUM (metodo carimbarPedidoDoCliente), porque este metodo so roda no caminho
        // de FALLBACK — 84 de 98 geracoes vao pelo LLM e nunca passariam por aqui.
        $script = $this->carimbarPedidoDoCliente($script);

        $script['style'] = $script['presentation'] ?? '';

        // action_timeline — a peca que faltava pra ligar o roteiro (sections) ao
        // prompt final. Distribui as sections no tempo total, cada uma vira um
        // shot cronometrado.
        $sections = $script['sections'] ?? [];
        $n        = max(1, count($sections));
        $por      = $duracao / $n;
        $timeline = [];
        $falas    = [];
        foreach (array_values($sections) as $i => $sec) {
            $inicio = round($i * $por, 1);
            $fim    = round(min($duracao, ($i + 1) * $por), 1);
            $rotulo = $sec['section'] ?? ('cena ' . ($i + 1));
            $fala   = trim((string) ($sec['dialogue'] ?? ''));
            // SEL-audio-fix (08/08): a DESCRICAO do shot (vira "ACAO POR TEMPO"
            // no prompt visual) usa a ESTRATEGIA de cena, NAO a fala. A fala vai
            // SO pro campo AUDIO (audio_diegetic). Assim o Veo MOSTRA a acao e
            // FALA a frase de venda — nunca narra a instrucao de direcao.
            $visual = trim((string) ($sec['visual_intent'] ?? ''));
            $timeline[] = [
                'start_s'     => $inicio,
                'end_s'       => $fim,
                'shot'        => $rotulo,
                'description' => trim($rotulo . ($visual !== '' ? (': ' . $visual) : '')),
            ];
            if ($fala !== '') { $falas[] = $fala; }
        }
        $script['action_timeline'] = $timeline;

        // audio_diegetic — A FALA DE VERDADE. Isto e o que estava se perdendo:
        // o roteiro que o cliente escreveu (inicio/meio/fim/roteiro_cliente) ia
        // pras sections, mas nunca saia dali rumo ao prompt.
        // SEL-corte-audio (Ruan 07/08): a fala combinada NAO pode passar do
        // orcamento de tempo do clipe. O Veo gera ~8s e corta a fala no meio se
        // sobrar texto (roteiro_cliente ia inteiro, ate ~35 palavras). Trunca
        // pro teto que cabe em ($duracao - reserva) a 2,2 pal/s, terminando numa
        // frase completa quando da, pra fala nunca cortar no fim.
        $faladoJunto = trim(implode(' ', $falas));
        if ($faladoJunto !== '') {
            $maxPalavras = max(6, (int) floor(((float) $duracao - 1.2) * 2.2));
            // SEL-audio-fix2 (09/08, Ruan "narração repete + buraco no áudio"): fala
            // curta (1 frase) num vídeo de 8-10s fazia o Veo REPETIR pra preencher
            // ("vale muito a pena" 2x) e ainda deixava silêncio. ROTEIRO COMPLETO do
            // tamanho do vídeo: completa com frases de venda DISTINTAS (gancho→valor→
            // CTA) até o piso, sem repetir, respeitando o teto no fim.
            $minPalavras = max(8, (int) floor(((float) $duracao - 1.2) * 1.9));
            // preserva o CTA (última fala) dentro do teto: corta o MEIO, nunca o fim.
            $faladoJunto = $this->montarFalaComCTA($falas, $maxPalavras);
            $faladoJunto = $this->completarFala($faladoJunto, $minPalavras, $maxPalavras);
        }
        $script['audio_diegetic'] = $faladoJunto;
        // SEL-audio-fix2: diretiva de AUDIO reescrita — PROÍBE repetição (era a causa
        // do "vale muito a pena" 2x) e pede narração ÚNICA e CONTÍNUA que preenche o
        // vídeo inteiro, sem silêncio. NÃO manda mais "distribuir nas N cenas" (era o
        // que fazia o Veo esticar/repetir a mesma frase pra caber no corte).
        $script['audio_timing']   = $falas
            ? ('Narração ÚNICA, contínua e natural em português do Brasil (voz '
               . ($this->generoResolvido() === 'homem' ? 'masculina' : 'feminina')
               . ', sotaque neutro). '
               . 'Começa em ~0,3s e TERMINA ~1s antes do fim do vídeo. As frases fluem gancho → benefício → '
               . 'chamada para ação e cada uma é dita UMA só vez: é PROIBIDO repetir qualquer frase, palavra-'
               . 'chave ou o CTA. Ritmo de conversa preenchendo o vídeo do início ao fim, SEM pausas longas e '
               . 'SEM nenhum trecho mudo.')
            : '';

        // framing — enquadramento por estilo (POV nunca sobe pro rosto).
        $framingByEstilo = [
            'pov'      => 'PRIMEIRA PESSOA DE VERDADE: a camera E O OLHO de quem filma, na altura dos olhos e apontada PRA BAIXO, para o proprio colo e para as maos. As PROPRIAS PERNAS/COLO de quem filma aparecem no rodape do quadro (e isso que faz ser primeira pessoa, e nao uma mao solta no ar). AS DUAS MAOS aparecem e trabalham juntas — segurando, abrindo, girando o produto. Ambiente domestico real (quarto, cama, sofa, mesa), o MESMO do primeiro ao ultimo frame. Camera na mao, estavel, sem subir para o rosto. Nada de plano de estudio, nada de fundo neutro.',
            'showcase' => 'produto centralizado, enquadramento fechado tipo still-life, respiro simetrico ao redor',
        ];
        $script['framing'] = $framingByEstilo[$estilo] ?? 'produto sempre visivel e em destaque, close final ocupando a maior parte do quadro';

        // lighting_color — por estilo, coerente com defaultScene().
        $lightingByEstilo = [
            'pov'      => 'luz natural suave, tons quentes de ambiente domestico, sem contraste artificial de estudio',
            'showcase' => 'iluminacao de estudio, sombras suaves e controladas, cores fieis ao produto',
            'zero'     => 'iluminacao natural, cores vivas e autenticas',
        ];
        $script['lighting_color'] = $lightingByEstilo[$estilo] ?? 'luz natural de ambiente domestico brasileiro, cores quentes e autenticas, nada de estudio';

        // negative — instrucao textual do que evitar (o Veo/worker por navegador
        // nao tem campo de negative_prompt separado, ver veo_generate.js: so le
        // `prompt`; entao isto entra dentro do texto principal mesmo, e a unica
        // forma de pedir "sem rosto" no caminho Veo).
        $negativeByEstilo = [
            'pov'      => 'rosto humano, pessoa inteira, apresentador visivel, corpo, cabeca — mostrar SOMENTE maos e o produto. Sem CGI, sem maos ou dedos deformados, sem watermark, sem baixa qualidade. Sem NENHUM texto, letra, palavra, numero, legenda, logo ou marca dagua em nenhum canto do quadro.',
            'showcase' => 'pessoas visiveis, rostos, maos de pessoas — o produto e o unico protagonista. Sem CGI, sem watermark, sem baixa qualidade. Sem NENHUM texto, letra, palavra, numero, legenda, logo ou marca dagua em nenhum canto do quadro.',
        ];
        $script['negative'] = $negativeByEstilo[$estilo] ?? 'CGI, pele plastica, maos deformadas, dedos extras, rosto distorcido, watermark, baixa qualidade, tremido excessivo. Sem NENHUM texto, letra, palavra, numero, legenda, logo ou marca dagua em nenhum canto do quadro.';

        // _lang — toKlingPrompt le `_lang` (nao `lang`) pra decidir pt-BR vs es-419.
        $script['_lang'] = $script['lang'] ?? 'pt-BR';

        return $script;
    }

    /**
     * SEL-roteirocoerente (09/08) — aplica SO os campos VISUAIS por estilo em cima do
     * roteiro que o LLM ESCREVEU, SEM tocar no audio_diegetic/audio_timing/
     * action_timeline (a fala coerente e do LLM; reconstruir apagaria a narracao).
     * Espelha os mapas de enrichScriptForPrompt, mas preserva a fala.
     *   - pov/showcase: FORCA subject/framing/lighting/negative/performance de "sem
     *     pessoa" (critico: o LLM tende a descrever uma apresentadora, e nesses estilos
     *     nao pode haver rosto/corpo em quadro).
     *   - ugc/zero: mantem o subject/framing/lighting/negative/performance do LLM (mais
     *     rico); so preenche o que vier vazio.
     * Sempre: camera = a escolha do cliente + regra do produto-estrela; cenario do
     * cliente quando ele escolheu um; product_desc (fidelidade); _lang=pt-BR.
     */
    /**
     * SEL-PALAVRA-DO-CLIENTE-DE-VERDADE (16/08) — o pedido do cliente viaja nos DOIS caminhos.
     *
     * Estes quatro campos decidem se o que o cliente escreveu/subiu/marcou chega ao motor.
     * Eles moravam dentro de enrichScriptForPrompt(), que so roda quando o LLM cai. Como o
     * LLM quase nunca cai (84 de 98 geracoes em 15/08), o resultado medido no banco foi:
     * 160 videos, 3 com a frase do cliente, ZERO com o cenario dele. E `sem_som` na mesma
     * estrada: 6 de 14 pedidos mudos receberam prompt mandando a pessoa FALAR.
     *
     * Agora e uma etapa comum, chamada logo antes de montar o prompt, venha o roteiro do
     * LLM ou do caminho deterministico.
     */
    private function carimbarPedidoDoCliente(array $script): array
    {
        $script['prompt_colado'] = $this->pedidoPromptColado ?? null;
        $script['cenario_foto']  = $this->pedidoCenarioFoto ?? null;

        if (isset($this->pedidoSemSom) && $this->pedidoSemSom === true) {
            $script['sem_som']    = true;
            $script['movimentos'] = $this->pedidoMovimentos ?? null;
        }

        return $script;
    }

    private function aplicarVisualPorEstilo(array $script, string $estilo, array $v, string $cameraTexto, ?bool $maoJaPresente = null): array
    {
        $subjectPov = match ($maoJaPresente) {
            true  => 'a MESMA mao que ja aparece segurando o produto na foto de referencia continua segurando e mostrando o produto — nao adicione uma segunda mao nem nenhuma pessoa. ARCO DA CENA, nesta ordem: o pacote/embalagem aparece no colo de quem filma, as maos abrem/rasgam a embalagem, tiram o produto de dentro e so entao o produto e mostrado e usado de perto — como se tudo estivesse sendo gravado de verdade, na hora. No FIM do video a mao SAI DE QUADRO por completo, deslizando pra fora pela lateral ou por baixo, e o ultimo instante mostra o produto SOZINHO no mesmo enquadramento. A cena, a luz e o enquadramento nao mudam do primeiro ao ultimo frame',
            false => 'a foto de referencia mostra so o produto sozinho — uma mao humana realista ENTRA em quadro (pela parte de baixo/lateral), pega e mostra o produto. Nenhuma pessoa, rosto ou corpo aparece. ARCO DA CENA, nesta ordem: o pacote/embalagem aparece no colo de quem filma, as maos abrem/rasgam a embalagem, tiram o produto de dentro e so entao o produto e mostrado e usado de perto — como se tudo estivesse sendo gravado de verdade, na hora. No FIM do video a mao SAI DE QUADRO por completo, deslizando pra fora pela lateral ou por baixo, e o ultimo instante mostra o produto SOZINHO no mesmo enquadramento. A cena, a luz e o enquadramento nao mudam do primeiro ao ultimo frame',
            default => 'apenas as MAOS de quem esta segurando o produto — nenhuma pessoa, rosto ou corpo aparece em nenhum momento do video. ARCO DA CENA, nesta ordem: o pacote/embalagem aparece no colo de quem filma, as maos abrem/rasgam a embalagem, tiram o produto de dentro e so entao o produto e mostrado e usado de perto — como se tudo estivesse sendo gravado de verdade, na hora. No FIM do video a mao SAI DE QUADRO por completo, deslizando pra fora pela lateral ou por baixo, e o ultimo instante mostra o produto SOZINHO no mesmo enquadramento. A cena, a luz e o enquadramento nao mudam do primeiro ao ultimo frame',
        };

        if ($estilo === 'pov') {
            $script['subject']        = $subjectPov;
            $script['framing']        = 'PRIMEIRA PESSOA DE VERDADE: a camera E O OLHO de quem filma, na altura dos olhos e apontada PRA BAIXO, para o proprio colo e para as maos. As PROPRIAS PERNAS/COLO de quem filma aparecem no rodape do quadro (e isso que faz ser primeira pessoa, e nao uma mao solta no ar). AS DUAS MAOS aparecem e trabalham juntas — segurando, abrindo, girando o produto. Ambiente domestico real (quarto, cama, sofa, mesa), o MESMO do primeiro ao ultimo frame. Camera na mao, estavel, sem subir para o rosto. Nada de plano de estudio, nada de fundo neutro.';
            $script['lighting_color'] = 'luz natural suave, tons quentes de ambiente domestico, sem contraste artificial de estudio';
            $script['performance']    = 'sem apresentador visivel, apenas as maos e o produto em foco, movimento continuo; a narracao e voz em off (ninguem aparece falando)';
            $script['negative']       = 'rosto humano, pessoa inteira, apresentador visivel, corpo, cabeca — mostrar SOMENTE maos e o produto. Sem CGI, sem maos ou dedos deformados, sem watermark, sem baixa qualidade. Sem NENHUM texto, letra, palavra, numero, legenda, logo ou marca dagua em nenhum canto do quadro.';
        } elseif ($estilo === 'showcase') {
            $script['subject']        = 'nenhuma pessoa visivel — o produto e o unico protagonista, estilo still-life de estudio';
            $script['framing']        = 'produto centralizado, enquadramento fechado tipo still-life, respiro simetrico ao redor';
            $script['lighting_color'] = 'iluminacao de estudio, sombras suaves e controladas, cores fieis ao produto';
            $script['performance']    = 'foco 100% no produto, sem pessoas visiveis, close-up cinematografico; a narracao e voz em off';
            $script['negative']       = 'pessoas visiveis, rostos, maos de pessoas — o produto e o unico protagonista. Sem CGI, sem watermark, sem baixa qualidade. Sem NENHUM texto, letra, palavra, numero, legenda, logo ou marca dagua em nenhum canto do quadro.';
        } else {
            // ugc / zero: o LLM ja descreve a apresentadora — mantem; so preenche vazio.
            if (empty($script['subject']))        { $script['subject'] = $this->textoApresentador(); }
            if (empty($script['framing']))        { $script['framing'] = 'produto sempre visivel e em destaque, close final ocupando a maior parte do quadro'; }
            if (empty($script['lighting_color'])) { $script['lighting_color'] = 'luz natural de ambiente domestico brasileiro, cores quentes e autenticas, nada de estudio'; }
            if (empty($script['performance']))    { $script['performance'] = 'atriz falando direto pra camera, autentica, tom UGC brasileiro'; }
            if (empty($script['negative']))       { $script['negative'] = 'CGI, pele plastica, maos deformadas, dedos extras, rosto distorcido, watermark, baixa qualidade, tremido excessivo. Sem NENHUM texto, letra, palavra, numero, legenda, logo ou marca dagua em nenhum canto do quadro.'; }
        }

        // camera: regra dura do produto (movimento maximo + produto-estrela em close).
        $script['camera'] = $cameraTexto;
        // cenario do cliente quando ele escolheu um (campo visual, nao e fala).
        if (! empty($v['cenario'])) { $script['scene'] = $v['cenario']; }
        // fidelidade do produto no slot PRODUTO do prompt.
        // SEL-PRODUTO-FIEL (16/08): este `if` era `if (empty($script['product_desc']))`.
        // O LLM que escreve o roteiro SEMPRE preenche esse campo com uma versao curta,
        // tirada do titulo do anuncio — sem cor, sem material, sem as pecas do produto.
        // Como so preenchiamos "se estivesse vazio", a NOSSA descricao (a unica que olha
        // a foto e diz "cor cinza-claro, apoio retratil para os pes") era descartada em
        // 100% dos pedidos. Resultado medido no #1297: foto de cadeira cinza-clara,
        // video entregue com cadeira preta. Agora a nossa manda; a do LLM so entra se a
        // nossa vier vazia (foto ilegivel / visao fora do ar).
        $nossaDesc = $this->buildProductDesc($v);
        if ($nossaDesc !== '') {
            $script['product_desc'] = $nossaDesc;
        } elseif (empty($script['product_desc'])) {
            $script['product_desc'] = $nossaDesc;
        }
        // toKlingPrompt le `_lang`; generate() ja seta, mas garantimos pt-BR.
        $script['_lang'] = $script['_lang'] ?? ($script['lang'] ?? 'pt-BR');

        return $script;
    }

    /**
     * SEL-refazer (09/08, Ruan): refaz um video REUSANDO a estrutura da pipeline
     * original (produto, cenario, camera, inicio/meio/fim, referencias, duracao) +
     * uma modificacao que o cliente descreve. Nao refaz do zero: carrega o payload
     * da pipeline original (do proprio usuario), injeta o ajuste no(s) prompt(s) e
     * dispara o MESMO caminho. Mantem 9:16 e pt-BR.
     */
    public function refazer(Request $request)
    {
        $data = $request->validate([
            'pipeline_id' => 'required|integer',
            'modificacao' => 'required|string|max:400',
        ]);
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Nao autenticado.'], 401);
        }

        $orig = AiVideoPipeline::where('id', $data['pipeline_id'])
            ->where('user_id', $user->id)
            ->first();
        if (! $orig) {
            return response()->json(['message' => 'Video original nao encontrado.'], 404);
        }

        $p   = $orig->payloads ?? [];
        $mod = trim($data['modificacao']);
        $inj = ' AJUSTE PEDIDO PELO CLIENTE (aplicar mantendo EXATAMENTE o mesmo produto, '
             . 'personagem, cenario, iluminacao e as referencias de imagem; mudar so o que ele pediu): ' . $mod;

        $p['prompt'] = mb_substr((string) ($p['prompt'] ?? ''), 0, 1700) . $inj;
        if (! empty($p['clip_prompts']) && is_array($p['clip_prompts'])) {
            $p['clip_prompts'] = array_map(
                fn ($cp) => mb_substr((string) $cp, 0, 1700) . $inj,
                $p['clip_prompts']
            );
        }
        // SEL-PAISAGEM-LIBERADA: refazer mantem o formato que o cliente escolheu no
        // pedido original. Chumbar 9:16 aqui fazia quem gerou em paisagem receber
        // retrato ao pedir "refazer" — mudanca silenciosa, do tipo que o cliente
        // percebe e a gente nao.
        $p['aspect_ratio'] = in_array($p['aspect_ratio'] ?? null, ['9:16', '16:9'], true)
            ? $p['aspect_ratio']
            : '9:16';
        $p['lang']         = 'pt-BR';
        $p['_refazer_de']  = $orig->id;
        $p['_modificacao'] = $mod;

        $novo = AiVideoPipeline::create([
            'user_id'     => $user->id,
            'mode'        => $orig->mode,
            'product_key' => $orig->product_key,
            'step'        => 'queued',
            'payloads'    => $p,
            'dry_run'     => (bool) config('services.ai_video.dry_run', false),
        ]);

        // mesmo gate de acesso do fluxo normal (nao contorna assinatura/quota)
        if (\App\Services\Ai\VideoAccessGuard::barrarPipeline($novo->id, 'studio')) {
            return response()->json(['message' => 'Seu plano nao libera essa geracao agora.'], 403);
        }

        $fila = (($user->role ?? null) === 'super_admin') ? 'video-ruan' : 'video';
        if (($p['pipeline'] ?? null) === 'video_longo') {
            \App\Jobs\StudioLongVideoJob::dispatch($novo->id)->onQueue($fila);
        } else {
            \App\Jobs\StudioGenerationJob::dispatch($novo->id)->onQueue($fila);
        }

        Log::error('[SEL-refazer] refazer despachado', [
            'novo' => $novo->id, 'origem' => $orig->id, 'mod' => mb_substr($mod, 0, 80),
        ]);

        return response()->json([
            'pipeline_id' => $novo->id,
            'eta_seconds' => (int) (((int) ($p['duration'] ?? 12)) * 8 + 40),
            'poll_url'    => url("/api/v1/ai/video-pipeline/{$novo->id}"),
        ]);
    }

    /**
     * SEL-463 — builder deterministico do roteiro Kling.
     * Substitui o call a LLM externo (Gemini/OpenAI/ChatGPT DICloak).
     * Usa direto os 7 passos que o cliente escolheu no wizard + padroes SEL-459.
     * Sempre funciona: sem tunnel, sem quota, sem API paga.
     */
    private function buildDeterministicScript(array $v, int $duracao, string $cameraTexto, array $brief): array
    {
        $estilo   = $v["estilo"] ?? "ugc";
        $produto  = trim($v["produto_nome"] ?? "produto");
        $produtoS = mb_substr($produto, 0, 80);
        $scene    = $v["cenario"] ?? $this->defaultScene($estilo);
        $apresent = $v["apresentadora"] ?? ($this->generoResolvido() === "homem"
            ? "apresentador natural, tom UGC brasileiro autentico"
            : "apresentadora natural, tom UGC brasileiro autentico");

        // Numero de sections varia com duracao (2s por section min, teto 5)
        $nSections = max(2, min(5, intdiv($duracao, 5)));

        // Diretrizes por estilo (SEL-459 patterns)
        $stylePresets = [
            "ugc"      => ["presentation" => "UGC brasileiro autentico, pessoa real segurando o produto, iluminacao natural", "performance" => "atriz fala direto pra camera, gestos naturais, mostrando o produto em uso"],
            "pov"      => ["presentation" => "primeira pessoa (POV), maos do usuario segurando o produto, camera baixa", "performance" => "sem apresentador visivel, apenas maos e produto em foco, movimento continuo"],
            "showcase" => ["presentation" => "produto em destaque studio-like, iluminacao dramatica, movimentos suaves", "performance" => "foco 100 pct no produto, sem pessoas visiveis, close-up cinematografico"],
            "zero"     => ["presentation" => "video gerado do zero sem imagem base, apenas descricao", "performance" => "visual coerente com descricao textual, camera estilo comercial"],
        ];
        $preset = $stylePresets[$estilo] ?? $stylePresets["ugc"];

        // Sections com dialogos derivados do input do cliente.
        // SEL-audio-fix (08/08, Ruan "prompt vazando no audio"): os presets de
        // inicio/meio/fim que o cliente CLICA sao ESTRATEGIA DE CENA (ex:
        // "Abrindo a embalagem, revelando o produto", "Chamando pra comprar no
        // link") — NAO sao a fala. Antes iam DIRETO pro audio_diegetic e a voz
        // recitava a instrucao ("...abrindo a embalagem revelando o produto"),
        // saindo um audio sem nexo. Agora: a estrategia guia o VISUAL do shot; a
        // FALA vira uma frase de venda REAL em pt-BR (mapa deterministico, sem
        // depender de LLM). Texto LIVRE digitado pelo cliente continua sendo
        // falado exatamente como ele escreveu (a intencao e dele).
        $sections = [];
        $arco = [
            $this->estrategiaParaFala($v["inicio"] ?? "", $produtoS, "inicio"),
            $this->estrategiaParaFala($v["meio"]   ?? "", $produtoS, "meio"),
            $this->estrategiaParaFala($v["fim"]    ?? "", $produtoS, "fim"),
        ];
        $dialogos = array_map(fn ($a) => $a["fala"],   $arco);
        $visuais  = array_map(fn ($a) => $a["visual"], $arco);
        if (! empty($v["roteiro_cliente"])) {
            // cliente escreveu o roteiro inteiro: as palavras dele SAO a fala.
            $dialogos[0] = mb_substr($v["roteiro_cliente"], 0, 200);
            $visuais[0]  = "";
        }

        $secLabels = ["abertura", "desenvolvimento", "aplicacao", "prova", "fechamento"];
        for ($i = 0; $i < $nSections; $i++) {
            $idx     = min($i, count($dialogos) - 1);
            $dialogo = $dialogos[$idx];
            $visual  = $visuais[$idx] ?? "";
            if ($i > 2 && $i < $nSections - 1) {
                $dialogo = "Olha esse detalhe aqui, ó.";
                $visual  = "close revelando mais um detalhe do {$produtoS} em acao";
            }
            if ($i == $nSections - 1) {
                $dialogo = $dialogos[count($dialogos) - 1];
                $visual  = $visuais[count($visuais) - 1] ?? "";
            }
            $sections[] = [
                "section"       => $secLabels[min($i, count($secLabels) - 1)],
                "dialogue"      => $dialogo,   // o que a voz FALA (frase de venda real)
                "visual_intent" => $visual,    // o que a CAMERA mostra (a estrategia)
            ];
        }

        return [
            "lang"         => "pt-BR",
            "scene"        => $scene,
            "camera"       => $cameraTexto,
            "presentation" => $preset["presentation"] . ". Video vertical 9:16, aspect ratio vertical para redes sociais",
            "performance"  => $preset["performance"],
            "sections"     => $sections,
            "style_notes"  => implode(". ", $brief),
            // SEL-490 Layer C: IDENTIDADE do produto no TEXTO do prompt. Sem isto,
            // o unico portador do produto era a imagem anexada — e quando o anexo
            // falhava (bug 07/08) o Veo inventava outro produto. Agora o nome/marca
            // viaja no prompt tambem (defesa em profundidade + ancora de fidelidade).
            "product_desc" => $this->buildProductDesc($v),
        ];
    }

    /**
     * SEL-490 Layer C — descricao textual do produto pro slot PRODUTO do prompt.
     * Nome + categoria + ordem explicita de fidelidade a foto de referencia.
     */
    private function buildProductDesc(array $v): string
    {
        $nome = mb_substr(trim((string) ($v['produto_nome'] ?? '')), 0, 120);
        if ($nome === '') { return ''; }
        $cat = trim((string) ($v['produto_categoria'] ?? ''));
        $catPart = ($cat !== '' && mb_stripos($nome, $cat) === false) ? " (categoria: {$cat})" : '';
        // SEL-CUSTOMPRODUCT: quando o cliente subiu o proprio produto e escreveu uma
        // descricao, ela entra aqui tambem — assim o caminho de FALLBACK deterministico
        // (sem LLM, quando o pool falha) ainda carrega a descricao no slot PRODUTO do
        // prompt, nao so o nome. No fluxo produto-em-alta este campo nunca existe.
        $descPart = '';
        $descCustom = trim((string) ($v['custom_product_description'] ?? ''));
        if ($descCustom !== '') {
            $descPart = ' Descricao do cliente: ' . mb_substr($descCustom, 0, 300) . '.';
        }
        // SEL-PRODUTO-PELA-FOTO (15/08): o que a FOTO mostra vem na frente.
        //
        // Medido no pedido 1240: o slot PRODUTO recebia so o titulo do anuncio ("Calça
        // Feminina de Alta Marrante Moda Gringa Elegância e Conforto para Todas as
        // Ocasiões Versátil e Premium") — nenhuma cor, nenhuma modelagem. O modelo
        // entregou uma jeans preta justa no lugar de uma pantalona roxa acetinada, e o
        // cliente nao pode usar um video do produto que ele nao vende.
        //
        // A descricao sai do Gemini OLHANDO a foto, pelo navegador, na conta que a casa
        // ja mantem viva (sem chave, sem custo). Cacheada por foto: o custo de ~30s
        // acontece uma vez por produto.
        $peloOlho = '';
        $fotoDoProduto = trim((string) ($v['imagem'] ?? ''));
        if ($fotoDoProduto !== '') {
            try {
                $peloOlho = app(\App\Services\Ai\VideoDirectorService::class)->descreveAparencia($fotoDoProduto, $nome);
            } catch (\Throwable $e) {
                Log::warning('[SEL-PRODUTO-PELA-FOTO] visao indisponivel, seguindo com o titulo', [
                    'err' => mb_substr($e->getMessage(), 0, 120),
                ]);
            }
        }

        // com descricao visual: ela manda, e o titulo vira contexto entre parenteses.
        // sem ela: exatamente o comportamento de antes.
        $cabeca = $peloOlho !== ''
            ? rtrim($peloOlho, '. ') . '. (anuncio: ' . $nome . ')' . $catPart . $descPart
            : $nome . $catPart . $descPart;

        // SEL-NAO-TIRA-PARTE (15/08): reforco dos dois atributos que o modelo mais
        // erra, medidos no teste do frasco SEMELLE — ele acertou o rotulo depois da
        // descricao, mas SUMIU com a sobretampa transparente e pintou de rosa um
        // liquido incolor. Dizer o nome da peca no meio da frase nao bastou; vai como
        // restricao propria, no fim, que e onde ele mais obedece.
        $reforco = $peloOlho !== ''
            ? ' Mantenha TODAS as partes que aparecem na foto (tampa, sobretampa, alca, tampinha, rotulo) '
              . '— nao remova nenhuma. Respeite a cor do produto E do conteudo: liquido incolor continua '
              . 'incolor, nao pinte, nao escureca, nao adicione cor.'
            : '';

        return $cabeca
            . '. Mostrar EXATAMENTE este produto, identico em cor, forma, marca e embalagem a foto de '
            . 'referencia anexada. NAO inventar, substituir nem trocar por outro produto.'
            . $reforco;
    }

    /**
     * SEL-audio-fix (08/08, Ruan "prompt vazando no audio") — traduz a
     * ESTRATEGIA de cena (o .txt do preset que o cliente clica em inicio/meio/
     * fim, ex "Abrindo a embalagem, revelando o produto") em DUAS coisas
     * separadas, pra voz NUNCA recitar a instrucao:
     *   - 'fala'   => frase de VENDA real, em pt-BR falado, que a apresentadora
     *                 diz de verdade (curta: o Omni rende ~8s).
     *   - 'visual' => a propria estrategia, que guia o que a CAMERA mostra.
     * Regras:
     *   - vazio                       => usa a fala padrao da posicao.
     *   - casa com um preset conhecido => fala = frase mapeada; visual = a estrategia.
     *   - texto LIVRE (nao casa)       => e o que o cliente digitou: vira a fala
     *                                     (a intencao e dele), sem visual separado.
     * Mapa deterministico de proposito: NAO depende de LLM (tunnel/quota/credito
     * caem). Chaves = .txt normalizado dos presets ARCO em StudioOptions.tsx.
     */
    private function estrategiaParaFala(string $val, string $produtoS, string $pos): array
    {
        $val = trim($val);
        $mapa = [
            // === inicio (gancho) ===
            'mostrando o problema que o produto resolve'    => 'Você ainda passa por isso? Olha só.',
            'uma pergunta direta pra quem esta assistindo'  => 'Deixa eu te perguntar uma coisa rapidinho.',
            'abrindo a embalagem revelando o produto'       => 'Olha o que acabou de chegar pra mim!',
            'o antes como era sem o produto'                => 'Antes disso aqui era o maior sufoco, viu.',
            'uma imagem de choque logo no primeiro quadro'  => 'Gente, eu não acreditei quando vi isso!',
            'ja comeca mostrando o resultado final'         => 'Olha esse resultado, depois te conto como.',
            'apontando um erro que quase todo mundo comete' => 'Quase todo mundo faz isso errado, ó.',
            'comecando pelo preco direto'                   => 'Adivinha quanto custa isso aqui.',
            // === meio (demonstracao) ===
            'usando o produto de verdade passo a passo'     => 'Ó como é simples de usar, olha.',
            'close nos detalhes material acabamento textura'=> 'Repara nesse acabamento, que capricho.',
            'comparando com o jeito antigo de fazer'        => 'Não tem nem comparação com o antigo.',
            'a reacao de quem acabou de experimentar'       => 'Cara, quando eu usei fiquei chocado.',
            'mostrando quanto tempo economiza'              => 'Em segundos já tá pronto, economiza tempo.',
            'testando a resistencia do produto'             => 'E aguenta tudo, pode testar aí.',
            'mostrando varias formas de usar'               => 'E dá pra usar de um monte de jeito.',
            'mostrando pra quem serve'                      => 'Serve pra todo mundo, viu.',
            // === fim (fechamento/CTA) ===
            'chamando pra comprar no link'                  => 'Corre no link aqui e garante o seu!',
            'mostrando o resultado final o depois'          => 'Olha o resultado, valeu cada centavo.',
            'recomendando pra quem esta vendo'              => 'Recomendo demais, vale muito a pena!',
            'avisando que a oferta e por tempo limitado'    => 'Corre que é só por tempo limitado!',
            'avisando que esta acabando'                    => 'Tá acabando o estoque, não vacila!',
            'respondendo a duvida mais comum'               => 'Qualquer dúvida é só me chamar.',
            'falando da garantia ou da troca'               => 'E ainda vem com garantia, sem risco.',
            'convidando pra seguir e ver mais'              => 'Segue aqui que tem muito mais!',
        ];
        $padrao = [
            'inicio' => 'Olha só que produto é esse, gente!',
            'meio'   => 'Deixa eu te mostrar funcionando.',
            'fim'    => 'Garante o seu agora, vale muito!',
        ];

        if ($val === '') {
            return ['fala' => $padrao[$pos] ?? $padrao['inicio'], 'visual' => ''];
        }
        $norm = $this->normalizarTexto($val);
        if (isset($mapa[$norm])) {
            return ['fala' => $mapa[$norm], 'visual' => $val];
        }
        // SEL 10/08: texto livre no ARCO (inicio/meio/fim) e DIRECAO DE CENA, NAO fala.
        // Ia pro audio e o avatar RECITAVA a acao (ex: "andando pra frente"). Agora vira
        // VISUAL (movimento no video); a fala usa a frase de venda padrao da posicao.
        return ['fala' => $padrao[$pos] ?? $padrao['inicio'], 'visual' => $val];
    }

    /** SEL-audio-fix — normaliza pra casar preset: minusculo, sem acento, sem
     *  pontuacao, espaco unico. Troca de acento MANUAL (nao usa iconv, que varia
     *  com o locale do servidor e pode devolver '?' e quebrar o casamento). */
    private function normalizarTexto(string $s): string
    {
        $s  = mb_strtolower(trim($s), 'UTF-8');
        $de = ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ'];
        $pa = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n'];
        $s  = str_replace($de, $pa, $s);
        $s  = preg_replace('/[^a-z0-9\s]/u', ' ', $s);
        $s  = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    /**
     * SEL-corte-audio: corta a fala pro teto de palavras, terminando numa frase
     * completa se possivel (senao corta na palavra e fecha com ponto).
     */
    private function truncarFala(string $texto, int $maxPalavras): string
    {
        $palavras = preg_split('/\s+/u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($palavras) <= $maxPalavras) {
            return trim($texto);
        }
        $corte = implode(' ', array_slice($palavras, 0, $maxPalavras));
        if (preg_match('/^(.*[\.\!\?])(?:\s|$)/su', $corte, $m)) {
            $frase = trim($m[1]);
            if (count(preg_split('/\s+/u', $frase, -1, PREG_SPLIT_NO_EMPTY)) >= 4) {
                return $frase;
            }
        }
        return rtrim($corte, " ,;:\xE2\x80\x94-") . '.';
    }

    /**
     * SEL-audio-fix2 — ROTEIRO COMPLETO do tamanho do vídeo. Se a fala combinada
     * ficou curta demais pro tempo (cliente mandou 1 frase, ou presets curtos), o
     * Veo repetia/deixava silêncio pra preencher. Aqui completamos com frases de
     * venda DISTINTAS (gancho→valor→CTA) até bater o piso de palavras, PULANDO
     * qualquer frase cuja ideia já esteja na fala (nada de repetir), e no fim
     * respeitamos o teto. Determinístico (sem LLM): não depende de quota/túnel.
     */
    /**
     * SEL-audio-fix2 (CTA-preserve) — monta a fala respeitando o TETO mas SEMPRE
     * preservando o CTA (a última fala, gancho→...→CTA). Enche do começo (gancho,
     * meio...) até esgotar o orçamento e cola o CTA no fim; se não couber tudo,
     * corta o MEIO — nunca o final (o CTA é o que vende). Antes o truncarFala cru
     * cortava a cauda e derrubava o CTA.
     */
    private function montarFalaComCTA(array $falas, int $maxPalavras): string
    {
        $falas = array_values(array_filter(array_map('trim', $falas), fn ($f) => $f !== ''));
        if ($falas === []) { return ''; }
        $wc = fn (string $t): int => count(preg_split('/\s+/u', trim($t), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if (count($falas) === 1) { return $this->truncarFala($falas[0], $maxPalavras); }

        $cta = $this->truncarFala((string) array_pop($falas), $maxPalavras); // CTA sempre preservado
        $orc = max(0, $maxPalavras - $wc($cta));
        $frente = [];
        foreach ($falas as $f) {                 // gancho, meio... enche até acabar o orçamento
            $c = $wc($f);
            if ($c <= $orc) { $frente[] = $f; $orc -= $c; } // não coube inteira => pula o MEIO
        }
        $txt = trim(implode(' ', array_merge($frente, [$cta])));

        return $this->truncarFala($txt, $maxPalavras);
    }

    private function completarFala(string $texto, int $minPalavras, int $maxPalavras): string
    {
        $texto = trim($texto);
        $conta = function (string $t): int {
            return count(preg_split('/\s+/u', trim($t), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        };
        // banco de frases de venda distintas, pt-BR falado, em ordem valor→CTA.
        $banco = [
            'Deixa eu te mostrar por que todo mundo tá amando isso.',
            'A diferença que faz no dia a dia é impressionante.',
            'A qualidade você sente logo na primeira vez que usa.',
            'É prático, resolve rápido e ainda te economiza um tempão.',
            'Depois que você experimenta, não larga mais.',
            'E o melhor: vale cada centavo pelo que entrega.',
            'Chega rapidinho na sua casa, é só pedir.',
            'Corre no link agora e garante o seu antes que acabe.',
        ];
        foreach ($banco as $frase) {
            if ($conta($texto) >= $minPalavras) { break; }
            $jaTem  = ' ' . $this->normalizarTexto($texto) . ' ';
            $chave5 = $this->normalizarTexto(implode(' ', array_slice(
                preg_split('/\s+/u', $frase, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 3)));
            if ($chave5 !== '' && str_contains($jaTem, ' ' . $chave5 . ' ')) { continue; } // não repete ideia
            $texto = trim($texto === '' ? $frase : ($texto . ' ' . $frase));
        }

        return $this->truncarFala($texto, $maxPalavras);
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
     * SEL-30s — divide o pitch em N beats narrativos (1 por clipe), cada um
     * dimensionado pra caber na fala de ~1 clipe (não corta no meio da palavra).
     * Se o cliente escreveu o roteiro, ele é LEI: divide o texto dele em N.
     */
    private function buildNarrationBeats(array $v, int $N, int $clipSecs): array
    {
        $produtoS = mb_substr(trim($v['produto_nome'] ?? 'produto'), 0, 60);
        $maxW     = max(6, (int) floor(((float) $clipSecs - 1.0) * 2.2));

        // roteiro do cliente é LEI: divide em N (respeita o texto dele)
        $fonte = trim((string) ($v['roteiro_cliente'] ?? ''));
        if ($fonte !== '') {
            return array_map(fn ($b) => $this->truncarFala((string) $b, $maxW), $this->dividirEmBeats($fonte, $N));
        }

        // SEL-audio-fix: traduz a ESTRATÉGIA (preset) na FALA de venda real — a voz
        // NUNCA recita a instrução ("Chamando pra comprar no link").
        $falaIni = $this->estrategiaParaFala(trim((string) ($v['inicio'] ?? '')), $produtoS, 'inicio')['fala'];
        $falaMei = $this->estrategiaParaFala(trim((string) ($v['meio']   ?? '')), $produtoS, 'meio')['fala'];
        $falaFim = $this->estrategiaParaFala(trim((string) ($v['fim']    ?? '')), $produtoS, 'fim')['fala'];

        // CONTINUIDADE: o FIM (gancho/CTA) vai SEMPRE no ÚLTIMO beat; os do meio
        // continuam a história SEM fechar. A CTA nunca cai num clipe do meio.
        if ($N <= 1) {
            $beats = [trim($falaIni . ' ' . $falaFim)];
        } elseif ($N === 2) {
            $beats = [trim($falaIni . ' ' . $falaMei), $falaFim];
        } else {
            // SEL-FALAREPETIDA (12/08): antes o meio era CLONADO pra encher a lista
            // (array_splice do mesmo $falaMei), e o avatar falava a mesma frase 2-3
            // vezes. Agora o miolo é REPARTIDO em pedaços distintos; se não der pra
            // repartir, sobra menos beat e o clipe fica só com imagem — nunca eco.
            $miolo = $this->dividirEmBeats($falaMei, $N - 2);
            $beats = array_merge([$falaIni], $miolo, [$falaFim]);
        }

        // Guarda final: dois beats seguidos iguais viram um só (o cliente ouve eco).
        $beats = array_values($beats);
        foreach ($beats as $i => $b) {
            if ($i > 0 && mb_strtolower(trim((string) $b)) === mb_strtolower(trim((string) $beats[$i - 1]))) {
                $beats[$i] = '';
            }
        }

        // trunca cada beat pro orçamento de fala do clipe (fecha em frase completa)
        return array_map(fn ($b) => $this->truncarFala((string) $b, $maxW), $beats);
    }

    /** SEL-30s — quebra um texto em N pedaços por frase (greedy, equilibrado). */
    private function dividirEmBeats(string $texto, int $N): array
    {
        if ($N <= 0) { return []; }
        $frases = preg_split('/(?<=[\.\!\?])\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [$texto];
        if (count($frases) <= $N) {
            // SEL-FALAREPETIDA: o preenchimento COPIAVA a última frase. Agora, se
            // faltar frase, tenta repartir por palavras; se nem isso der, devolve
            // beat VAZIO (clipe sem fala) — melhor silêncio que eco.
            $out = array_values(array_filter(array_map('trim', $frases), fn ($x) => $x !== ''));
            if (count($out) < $N) {
                $palavras = preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (count($palavras) >= $N * 3) {
                    $tam = (int) ceil(count($palavras) / $N);
                    $out = array_map(fn ($g) => trim(implode(' ', $g)), array_chunk($palavras, $tam));
                }
            }
            while (count($out) < $N) { $out[] = ''; }
            return array_slice($out, 0, $N);
        }
        // distribui as frases em N grupos o mais equilibrado possível
        $porGrupo = (int) ceil(count($frases) / $N);
        $grupos   = array_chunk($frases, $porGrupo);
        $out      = array_map(fn ($g) => trim(implode(' ', $g)), $grupos);
        while (count($out) < $N) { $out[] = ''; } // SEL-FALAREPETIDA: vazio, nunca cópia
        return array_slice($out, 0, $N);
    }

    /**
     * SEL-30s — N prompts de SHOT distintos (1 por clipe), cada um com
     * enquadramento/ângulo próprio, estilo VOICEOVER (sem talking-head), 9:16,
     * produto como estrela e coerente entre os cortes. O corte na costura é um
     * corte de câmera intencional (jump cut), não um corte seco.
     */
    private function buildShotPrompts(array $v, string $estilo, string $cameraTexto, string $scene, int $N, int $clipSecs, array $beats = [], array $lens = []): array
    {
        $produto = mb_substr(trim($v['produto_nome'] ?? 'produto'), 0, 80);
        $cena    = trim($scene) !== '' ? trim($scene) : 'ambiente residencial brasileiro com luz natural';

        // ÂNCORA DE CONSISTÊNCIA — bloco IDÊNTICO repetido em TODOS os cortes. É o
        // que faz o espectador não perceber que são N vídeos emendados.
        $ancora =
            'CONTINUIDADE VISUAL: MESMA cena em todos os cortes (' . $cena . '), '
          . 'MESMA iluminação (mesma fonte, mesma temperatura de cor, mesma hora do dia). '
          . 'Se aparecer uma pessoa, é SEMPRE A MESMA pessoa em todos os cortes — mesmo rosto, cabelo, roupa, pele e voz — nunca troque de pessoa entre os cortes. '
          . 'O produto ' . $produto . ' é o mesmo em todos os cortes: idêntico em cor, forma, marca e embalagem à foto de referência. ';

        // enquadramentos por posição narrativa (abertura -> uso -> detalhe/fecho)
        $framings = [
            'PLANO MÉDIO de abertura: o produto entra como gancho visual forte já nos primeiros segundos, energia alta',
            'CLOSE nas mãos e no produto em uso/demonstração, ângulo mais fechado e dinâmico que o corte anterior',
            'MACRO no produto revelando um detalhe real (textura, material, acabamento)',
            'PLANO DETALHE alternativo do produto em ação, ângulo novo',
            'CLOSE no produto, enquadramento cinematográfico',
        ];

        $baseUGC =
            'Vídeo vertical 9:16 (retrato), ultrarrealista, estilo UGC brasileiro filmado no celular. '
          . 'IDIOMA DA NARRAÇÃO/VOZ: PORTUGUÊS DO BRASIL (pt-BR), sotaque do sudeste. NÃO fale espanhol, NÃO fale inglês, NÃO fale português de Portugal. '
          // SEL-FALAREPETIDA (12/08): esta linha mandava NINGUÉM falar — era ela que
          // fazia o vídeo sair mudo ("para na metade e a pessoa não fala nada").
          . 'Sem texto na tela, sem legendas, sem watermark. '
          // SEL 10/08 (mesma receita do fluxo curto): realismo por LENTE/textura/grain
          // + negative de maos, no fluxo LONGO tambem. NAO usar 8k/hyper-realistic.
          . 'REALISMO: pele com textura e poros, maos realistas, lente 85mm, foco raso, grao natural, sem cara de IA, sem filtro. '
          . 'EVITAR: maos deformadas, dedos extras, maos duplicadas, legenda, texto na tela, marca d agua, outro produto, trocar de pessoa. ';

        // SEL-QUANTAS-CENAS: com 3 cenas, cada corte pode ter o CENARIO dele (ex.: 1
        // na porta de casa recebendo a caixa, 2 abrindo na cozinha, 3 o produto em uso
        // no fogao) e o texto que o cliente colou. O que ele nao preencher herda o
        // cenario geral -- e a ancora de continuidade (mesma pessoa, mesmo produto)
        // vale sempre, porque e uma historia so.
        $cenasDoCliente = array_values((array) ($v['cenas'] ?? []));

        $prompts = [];
        for ($i = 0; $i < $N; $i++) {
            $ultimo  = ($i === $N - 1);
            $framing = $framings[min($i, count($framings) - 1)];

            $extraDaCena = '';
            $cenaDoCorte = $cenasDoCliente[$i] ?? null;
            if (is_array($cenaDoCorte)) {
                $cenarioCena = trim((string) ($cenaDoCorte['cenario'] ?? ''));
                $textoCena   = trim((string) ($cenaDoCorte['prompt'] ?? ''));
                if ($cenarioCena !== '') {
                    $extraDaCena .= 'CENARIO DESTE CORTE (muda so o ambiente; a pessoa, a roupa e o produto seguem OS MESMOS): '
                                  . $cenarioCena . '. ';
                }
                if ($textoCena !== '') {
                    $extraDaCena .= 'O QUE ACONTECE NESTE CORTE, escrito pelo proprio cliente: ' . $textoCena . '. ';
                }
            }

            if ($ultimo) {
                $arco = 'ESTE É O ÚLTIMO CORTE: é aqui que a ação FECHA. Termine com o gancho/chamada final e um fechamento cinematográfico do produto. '
                      . ($N > 1 ? 'A câmera começa continuando o movimento do corte anterior e desacelera até o fechamento. ' : '');
            } else {
                $arco = 'ESTE NÃO É O ÚLTIMO CORTE: NÃO conclua, NÃO faça chamada pra ação, NÃO encerre a história, NÃO deixe o produto "assentar" como final. '
                      . 'Termine em AÇÃO ABERTA, no meio de um movimento, como se o próximo instante continuasse. '
                      . 'A câmera está em MOVIMENTO CONTÍNUO no fim deste corte (push-in/pan que NÃO para), pra o próximo corte começar continuando exatamente esse movimento — transição imperceptível. ';
            }

            // SEL-FALAREPETIDA: a fala do corte agora ENTRA no prompt (antes só
            // existia no payload, pro TTS que está desligado) e vem com janela de
            // tempo, pra terminar ANTES do corte da emenda e não ser decepada.
            $dur  = (float) ($lens[$i] ?? $clipSecs);
            $fala = trim((string) ($beats[$i] ?? ''));
            $bloco = '';
            if ($fala !== '') {
                $fim = max(1.0, round($dur - 1.2, 1));
                $bloco = 'FALA (áudio nativo, português do Brasil): a pessoa fala EM VOZ ALTA, '
                       . 'EXATAMENTE esta frase, UMA ÚNICA VEZ, sem improvisar e sem repetir nenhuma palavra: '
                       . '"' . $fala . '". '
                       . 'TEMPO: a fala começa em 0,3s e TERMINA até ' . $fim . 's; o resto é só imagem e som ambiente. '
                       . 'NÃO repita a frase nem parte dela, NÃO fale nada além dela. ';
            } else {
                $bloco = 'SEM FALA neste corte: apenas imagem e som ambiente, ninguém fala. ';
            }

            // SEL-PROMPT-CABE (12/08): a FALA vai na FRENTE. O prompt é truncado em
            // 1800 chars antes de ser digitado no Flow; o que estiver no fim se perde.
            // Ordem: quem fala e o quê -> corte/enquadramento -> câmera -> estilo.
            // SEL-CORTENOLONGO (13/08) — CORTE SECO DENTRO de cada clipe.
            //
            // O que estava errado: cada clipe do longo era UM plano so, e o prompt
            // pedia movimento continuo. Medido em 13/08 no primeiro longo pos-deploy
            // (pipe981): ZERO corte de camera. O dono cobrou 4x que "os cortes sao o
            // que da a dinamica, e exatamente o que o Kalodata faz".
            //
            // Por que nao copiei cru o conserto do fluxo curto: no longo o FIM de cada
            // clipe precisa continuar em movimento, senao a emenda entre clipes fica
            // visivel. Entao a receita aqui e diferente de proposito:
            //   - dentro do clipe: 3 janelas com CORTE SECO entre elas (medido no
            //     laboratorio: marcar tempo + pedir "CORTE SECO" e o que faz o Veo
            //     obedecer; timestamp sozinho nao basta);
            //   - no ULTIMO segundo: volta o movimento continuo, que e o que esconde
            //     a costura com o proximo clipe. Nos clipes finais nao precisa.
            $j1 = round($dur * 0.35, 1);
            $j2 = round($dur * 0.70, 1);
            $planos =
                'PLANOS DENTRO DESTE CORTE (o quadro TEM que trocar nestes tempos; cada troca e um CORTE SECO instantaneo, nunca dissolve, nunca fade):' . "\n"
              . '[00:00-' . $j1 . '] ' . $framing . ', camera de mao.' . "\n"
              . '[' . $j1 . '-' . $j2 . '] CORTE SECO para outro angulo do mesmo produto na mesma cena, mais fechado, camera de mao.' . "\n"
              . '[' . $j2 . '-' . $dur . '] CORTE SECO para um terceiro enquadramento (detalhe ou reacao), '
              . ($ultimo
                    ? 'desacelerando ate o fechamento final.'
                    : 'e a camera NAO para: termina em movimento continuo pra emendar no proximo corte.')
              . "\n";

            $prompts[] = $extraDaCena . 'CORTE ' . ($i + 1) . ' de ' . $N . ' — ' . $framing . '. '
              . $bloco
              . 'Duração: ' . $dur . ' segundos. '
              . $planos
              . $cameraTexto . ' ' . $ancora . $arco . $baseUGC;
        }
        return $prompts;
    }

    private function defaultScene(string $estilo): string
    {
        return match ($estilo) {
            "pov"      => "ambiente residencial brasileiro (cozinha, quarto, sala), luz natural, produto em primeiro plano",
            "showcase" => "estudio com fundo neutro, iluminacao profissional, produto em destaque em superficie limpa",
            "zero"     => "cenario coerente com produto e uso pretendido, iluminacao natural",
            default    => "ambiente domestico brasileiro autentico, iluminacao natural pela janela, cozinha ou sala",
        };
    }


    /**
     * SEL-TROCAPERSONAGEM (14/08) — o menu que substitui o Showcase.
     *
     * O Ruan descreveu tres coisas que o cliente ja faz na mao e a gente nao oferecia:
     *   1. mesma_fala — "tem muito video bom que o cliente gera": ele sobe um video que
     *      deu certo, a gente OUVE o que foi falado e refaz com OUTRO personagem, mesma
     *      fala e mesmo cenario.
     *   2. roupa — "tem uma roupa, a pessoa sobe uma imagem, coloca essa roupa na
     *      personagem e ela so fica mexendo o corpo mostrando a roupa" (sem fala; o
     *      cliente poe a musica depois).
     *   3. so_rosto — troca so o rosto de um video que ja existe.
     *
     * Os motores 2 e 3 JA EXISTIAM no StudioGenerationJob (provador_virtual e
     * trocar_rosto) e nunca tinham porta de entrada no Studio. O 1 e novo e so ficou
     * possivel hoje, com o ouvido (TranscreverVideoService).
     */
    private function gerarTrocaPersonagem(
        Request $request,
        array $v,
        $user,
        $reservationId,
        bool $ehPrioridade,
        string $qualityTier
    ) {
        $modo = (string) ($v['modo_troca'] ?? 'mesma_fala');

        try {
            $payload = [
                'aspect_ratio' => '9:16',
                'lang'         => 'pt-BR',
                'estilo'       => 'trocar_personagem',
                'modo_troca'   => $modo,
                'duration'     => 10,
                'opcoes_mode'  => true,
                '_priority'    => $ehPrioridade ? 'high' : null,
                'quality_tier' => $qualityTier,
            ];

            if ($modo === 'roupa') {
                // A roupa e a estrela: o personagem so movimenta o corpo mostrando a peca.
                $cloth  = $v['cloth_url'] ?? ($v['imagem'] ?? null);
                $person = $v['person_url'] ?? null;
                if (! $cloth || ! $person) {
                    return response()->json([
                        'error'   => 'faltou_imagem',
                        'message' => 'Pra vestir a roupa eu preciso de duas imagens: a da PESSOA e a da ROUPA.',
                    ], 422);
                }
                $tipo = 'provador_virtual';
                $payload += [
                    'person_url' => $person,
                    'cloth_url'  => $cloth,
                    'image_url'  => $cloth,
                    // SEL-ESPELHO (14/08, Ruan ao vivo): "nesse tipo de video de roupa
                    // existe um contexto — a pessoa esta em frente ao espelho, se
                    // exibindo, sem falar nada, e DEPOIS o cliente poe musica e a
                    // setinha do carrinho. E ela esta segurando o telefone gravando no
                    // proprio espelho. Senao fica espelho sem camera, sem nexo."
                    // Por isso o celular na mao e OBRIGATORIO nesta variante: e ele que
                    // explica quem esta filmando.
                    'prompt'     => (! empty($v['espelho'])
                        ? 'A pessoa esta de pe em frente a um ESPELHO DE CORPO INTEIRO gravando o proprio reflexo '
                          . 'com o CELULAR NA MAO (o telefone aparece claramente na mao dela, na altura do peito/rosto, '
                          . 'filmando o espelho — sem celular a cena nao faz sentido). Ela se exibe: gira o corpo devagar, '
                          . 'muda o peso de perna, ajusta a roupa, mostra o caimento do tecido de frente e de lado. '
                          . 'Enquadramento vertical 9:16 do reflexo no espelho, quarto ou provador com luz natural. '
                          . 'NINGUEM FALA — sem voz e sem narracao, so o som do ambiente (a musica entra depois). '
                          . 'Pele com textura, sem cara de IA, sem legenda e sem texto na tela.'
                        : 'A pessoa veste a roupa e movimenta o corpo devagar mostrando a peca: '
                          . 'gira o corpo, mexe os bracos, mostra o caimento do tecido. Camera parada, plano medio, '
                          . 'vertical 9:16. NINGUEM FALA — sem voz, sem narracao, so o ambiente. '
                          . 'Pele com textura, sem cara de IA, sem legenda e sem texto na tela.'),
                ];

            } elseif ($modo === 'so_rosto') {
                $face  = $v['face_url'] ?? null;
                $alvo  = $v['video_ref_url'] ?? null;
                if (! $face || ! $alvo) {
                    return response()->json([
                        'error'   => 'faltou_arquivo',
                        'message' => 'Pra trocar o rosto eu preciso do VIDEO e de uma FOTO do rosto novo.',
                    ], 422);
                }
                $tipo = 'trocar_rosto';
                $payload += ['face_url' => $face, 'target_video_url' => $alvo, 'image_url' => $face];

            } elseif ($modo === 'continuar') {
                // ── SEL-CONTINUAR (14/08) — a "parte 2" ──────────────────────────────
                //
                // O Ruan achou o no sozinho: "como ela vai continuar o assunto, sendo
                // que ela finalizou?". Nao da pra continuar o ARGUMENTO de um video que
                // fechou — da pra abrir um CAPITULO NOVO com a mesma pessoa e o mesmo
                // cenario, que e o que criador de verdade faz ("voltei pra te contar...").
                //
                // Aqui o frame de referencia e AMIGO, nao inimigo: no trocar_personagem
                // ele segurava a pessoa original e estragava (pipeline 1045); aqui
                // manter a pessoa e exatamente o objetivo.
                // Frame do MEIO, nao do fim — medido nos frames do video 1037: aos 7s a
                // pessoa ja saiu do quadro e sobrou o produto.
                $ref = $v['video_ref_url'] ?? null;
                if (! $ref) {
                    return response()->json([
                        'error'   => 'faltou_video',
                        'message' => 'Sobe o video que voce quer continuar — eu mantenho a pessoa e o cenario.',
                    ], 422);
                }

                $frameRef = app(\App\Services\Ai\FrameDoVideoService::class)->extrair($ref, 'meio');
                if (! $frameRef) {
                    \App\Services\Ai\VideoPlanLimitService::refund($reservationId);
                    return response()->json([
                        'error'   => 'nao_consegui_ler_o_video',
                        'message' => 'Nao consegui ler esse video agora pra manter a mesma pessoa. Tenta de novo em alguns minutos.',
                    ], 503);
                }

                // O que foi dito na parte 1 serve pra NAO repetir. Se o ouvido falhar,
                // seguimos assim mesmo: aqui a transcricao ajuda, nao e obrigatoria.
                $jaFalou = app(\App\Services\Ai\TranscreverVideoService::class)->daUrl($ref, 'p1u' . $user->id);

                $produtoNome = trim((string) ($v['produto_nome'] ?? 'esse produto'));
                $fala = trim((string) ($v['fala_parte2'] ?? ''));
                if ($fala === '') {
                    // Sem texto do cliente, abre capitulo novo — nunca repete o fecho.
                    $fala = 'Voltei pra te contar uma coisa que ninguem fala sobre ' . mb_substr($produtoNome, 0, 40) . '.';
                }
                $fala = $this->encurtaParaOitoSegundos($fala);

                $prompt = 'FALA (o UNICO audio do video). IDIOMA: portugues do Brasil. A MESMA pessoa da '
                    . 'imagem anexada diz UMA UNICA VEZ, exatamente: ' . $fala . ' Comeca a falar no primeiro '
                    . 'frame e termina por volta de 7s. Nunca repete e nunca inventa outra frase.'
                    . "\n\n" . 'CONTINUIDADE: a imagem anexada e um frame do video anterior. MANTENHA a MESMA '
                    . 'pessoa (mesmo rosto, cabelo e roupa), o MESMO cenario e a MESMA luz. E a continuacao '
                    . 'do mesmo video, gravada no mesmo dia.'
                    . ($jaFalou ? "\n\n" . 'NAO REPITA o que ja foi dito na parte 1: "' . mb_substr($jaFalou, 0, 180) . '".' : '')
                    . "\n\n" . 'PLANOS (o quadro TEM que trocar; cada troca e um CORTE SECO instantaneo):' . "\n"
                    . '[00:00-00:03] plano medio da pessoa falando, camera de mao.' . "\n"
                    . '[00:03-00:06] CORTE SECO para CLOSE NO PRODUTO nas maos dela, produto INTEIRO no quadro.' . "\n"
                    . '[00:06-00:08] CORTE SECO de volta pra pessoa, fechando a frase.'
                    . "\n\n" . 'Vertical 9:16, ultrarrealista, UGC brasileiro filmado no celular, pele com textura, '
                    . 'sem cara de IA, sem legenda, sem texto na tela, sem marca dagua.';

                $tipo = 'animar_produto';
                $payload += [
                    'prompt'        => $prompt,
                    'image_url'     => $frameRef,
                    'frame_ref_url' => $frameRef,
                    'video_ref_url' => $ref,
                    'fala_herdada'  => $fala,
                    'parte'         => 2,
                ];

            } else {
                // ── mesma_fala: o caminho que o Ruan descreveu primeiro ──────────────
                $ref = $v['video_ref_url'] ?? null;
                if (! $ref) {
                    return response()->json([
                        'error'   => 'faltou_video',
                        'message' => 'Sobe o video que voce quer copiar — eu ouco a fala dele e refaco com outro personagem.',
                    ], 422);
                }

                // OUVE o video (Gemini pelo navegador, no PC — sem API, sem custo).
                $fala = app(\App\Services\Ai\TranscreverVideoService::class)->daUrl($ref, 'ref' . $user->id);

                if ($fala === null) {
                    // Nao adivinho fala: devolvo o motivo e nao queimo geracao do cliente.
                    \App\Services\Ai\VideoPlanLimitService::refund($reservationId);
                    return response()->json([
                        'error'   => 'nao_consegui_ouvir',
                        'message' => 'Nao consegui ouvir a fala desse video agora. Tenta de novo em alguns minutos.',
                    ], 503);
                }

                $mudo = ($fala === '');
                $fala = $mudo ? '' : $this->encurtaParaOitoSegundos($fala);

                // manter_cenario: true (padrao) = mesmo ambiente do video que ele copiou;
                // false = ele so quer se inspirar no movimento e trocar a cena.
                $manterCenario = ! array_key_exists('manter_cenario', $v) || (bool) $v['manter_cenario'];
                $cenarioDito   = trim((string) ($v['cenario'] ?? ''));
                $cenario = $manterCenario
                    ? ($cenarioDito ?: 'o MESMO cenario do video de referencia (mesma luz, mesmo ambiente)')
                    : ($cenarioDito ?: 'um cenario NOVO, diferente do video de referencia, que combine com o produto');
                $quem    = trim((string) ($v['personagem'] ?? '')) ?: 'uma apresentadora brasileira diferente da do video original';

                // SEL-MOVIMENTO (14/08, aula do Ruan) — video MUDO nao se copia pela voz.
                //
                // Ele explicou o caso real: "tem gente que ve um video que viralizou de uma
                // pessoa se exibindo com a roupa, mas nao fala nada e bota so musica. Como
                // e que voce vai clonar uma voz se a pessoa nao falou nada?".
                // O que o cliente quer copiar ali e o MOVIMENTO e a EXIBICAO — nao a fala.
                // Antes eu mandava um texto generico ("movimentando o corpo"), que nao
                // copia jeito nenhum. Agora o prompt PEDE o movimento como protagonista.
                //
                // E o cenario virou ESCOLHA dele (`manter_cenario`): as vezes ele quer o
                // mesmo ambiente, as vezes so quer se inspirar no movimento e trocar a cena.
                $prompt = $mudo
                    ? 'Vertical 9:16, ultrarrealista, estilo UGC brasileiro filmado no celular. '
                      . 'SEM FALA E SEM NARRACAO — o video e mudo de proposito (o cliente poe musica depois).' . "\n\n"
                      . 'O QUE IMPORTA AQUI E O MOVIMENTO: ' . $quem . ' se exibindo e mostrando o produto com o '
                      . 'corpo — gira, muda de pose, aproxima o produto da camera, mostra o caimento e o detalhe. '
                      . 'Movimento continuo e confiante, energia alta, como quem sabe que esta sendo filmada.' . "\n\n"
                      . 'CENA: ' . $cenario . '.' . "\n\n"
                      . 'PLANOS (troca por CORTE SECO):' . "\n"
                      . '[00:00-00:03] plano medio, ela se apresentando com o produto no corpo.' . "\n"
                      . '[00:03-00:06] CORTE SECO para CLOSE no produto, mostrando textura e detalhe.' . "\n"
                      . '[00:06-00:08] CORTE SECO para plano medio de novo, pose final.' . "\n"
                      . 'Pele com textura, maos com cinco dedos, sem legenda, sem texto na tela, sem marca dagua.'
                    // A FALA vem PRIMEIRO — SEL-PROMPT-CABE (12/08): o prompt e truncado no
                    // motor, e o que fica pra tras e o que o modelo ignora.
                    : 'FALA (o UNICO audio do video). IDIOMA: portugues do Brasil. ' . $quem
                      . ' diz UMA UNICA VEZ, exatamente: ' . $fala . ' Comeca a falar no primeiro frame, '
                      . 'a voz nao para nos cortes e a frase termina por volta de 7s. Nunca repete e nunca inventa outra frase.'
                      . "\n\n" . 'CENA: ' . $cenario . ', parecida com a do video de referencia (mesma luz, mesmo tipo de ambiente).'
                      // SEL-TROCAPLANOS (14/08) — CORRIGIDO DEPOIS DE ASSISTIR O PRIMEIRO VIDEO.
                      // O pipeline 1042 saiu com personagem trocado e cenario certo, mas com
                      // UM PLANO SO nos 8 segundos e o produto cortado na borda de baixo. A
                      // linha antiga so listava os tempos; o fluxo normal (blocoPlanos) diz
                      // TAMBEM o que aparece em cada janela, e e por isso que ele corta.
                      // Regra do Ruan que estava faltando aqui: close no produto.
                      . "\n\n" . 'PLANOS (o quadro TEM que trocar nestes tempos; cada troca e um CORTE SECO '
                      . 'instantaneo, nunca dissolve, nunca fade):' . "\n"
                      . '[00:00-00:03] plano medio da pessoa falando, camera de mao, energia alta.' . "\n"
                      . '[00:03-00:06] CORTE SECO para CLOSE NO PRODUTO nas maos dela, produto INTEIRO '
                      . 'no quadro, sem cortar nas bordas.' . "\n"
                      . '[00:06-00:08] CORTE SECO para a pessoa de novo, mais fechado, fechando a frase.' . "\n"
                      . 'A MESMA pessoa e o MESMO cenario nos tres planos.'
                      . "\n\n" . 'Vertical 9:16, ultrarrealista, UGC brasileiro filmado no celular, pele com textura, '
                      . 'sem cara de IA, sem legenda, sem texto na tela, sem marca dagua.';

                // SEL-FRAMEREF (14/08) — o modelo precisa VER a cena, nao ler sobre ela.
                // MEDIDO no pipeline 1043: mandando so a descricao em texto, ele fez
                // "uma cozinha", nao A cozinha do video de referencia. Entao tiro um
                // FRAME do proprio video e mando como imagem de entrada.
                // Uso o frame do MEIO, nao o do fim: medido nos dois frames extraidos do
                // video 1037 — aos 4s aparece a PESSOA; aos 7s ela ja saiu do quadro e
                // sobrou so o produto. Pra manter personagem e cenario, o meio e o certo.
                // ⚠️ TESTADO E REVERTIDO (pipeline 1045). Eu tinha ligado o frame de
                // referencia como imagem de entrada pra segurar o cenario. Assisti o
                // resultado: o frame segura DEMAIS — voltou a MESMA mulher do video
                // original, mesma roupa, mesmo cabelo. Cenario perfeito e funcao
                // destruida: este menu existe justamente pra TROCAR a pessoa.
                // Entao aqui NAO vai frame. O cenario segue por descricao de texto
                // (fica "uma cozinha parecida", nao a mesma) — e o preco de trocar
                // mesmo o personagem, que e o que o cliente pediu.
                // O FrameDoVideoService fica: e exatamente o que o "continuar video"
                // precisa, onde manter a pessoa e o objetivo, nao o defeito.
                $tipo = 'video_do_zero';
                $payload += [
                    'prompt'         => $prompt,
                    'image_url'      => $v['imagem'] ?? null,
                    'video_ref_url'  => $ref,
                    'fala_herdada'   => $fala,
                    'ref_era_muda'   => $mudo,
                ];
            }

            $payload['pipeline'] = $tipo;

            $pipeline = AiVideoPipeline::create([
                'user_id'     => $user->id,
                'mode'        => 'studio_opcoes_trocar_personagem',
                'product_key' => md5('troca_' . $user->id . '_' . ($v['produto_nome'] ?? '') . '_' . now()->format('YmdHi')),
                'step'        => 'queued',
                'payloads'    => $payload,
                'dry_run'     => (bool) config('services.ai_video.dry_run', false),
            ]);

            \App\Services\Ai\VideoPlanLimitService::attachPipeline($reservationId, $pipeline->id);

            $fila = (($user->role ?? null) === 'super_admin')
                ? 'video-ruan'
                : ($ehPrioridade ? 'video-priority' : 'video');
            \App\Jobs\StudioGenerationJob::dispatch($pipeline->id)->onQueue($fila);

            Log::error('[SEL-TROCAPERSONAGEM] despachado', [
                'pipeline_id' => $pipeline->id,
                'modo'        => $modo,
                'tipo'        => $tipo,
                'tem_fala'    => ! empty($payload['fala_herdada']),
            ]);

            return response()->json([
                'ok'          => true,
                'pipeline_id' => $pipeline->id,
                'modo'        => $modo,
                'fala_usada'  => $payload['fala_herdada'] ?? null,
                'poll_url'    => url("/api/v1/ai/video-pipeline/{$pipeline->id}"),
            ]);

        } catch (\Throwable $e) {
            \App\Services\Ai\VideoPlanLimitService::refund($reservationId);
            Log::error('[SEL-TROCAPERSONAGEM] falhou', ['modo' => $modo, 'err' => $e->getMessage()]);
            return response()->json([
                'error'   => 'falha_troca_personagem',
                'message' => 'Nao consegui montar esse video agora. Sua geracao nao foi cobrada.',
            ], 500);
        }
    }

    /**
     * A entrega fecha em ~8s (medido: 23 de 25 videos). Fala longa herdada de um video
     * de referencia seria cortada no meio — entao ela e encurtada ANTES, cortando por
     * FRASE (nunca no meio de uma) ate caber. Ritmo medido de fala brasileira bem
     * articulada: ~2,2 palavras/seg; com ~7s uteis dao ~15 palavras.
     */
    private function encurtaParaOitoSegundos(string $fala): string
    {
        // SEL-FALA-RITMO (14/08, Ruan: "parece que a fala esta lenta").
        //
        // MEDIDO em 6 videos entregues (whisper com timestamp por palavra):
        // 113 palavras por minuto no trecho falado, contra 140-170 do UGC de vendas
        // em pt-BR. No pior caso, 88 wpm com 2,5s de video MUDO no fim.
        //
        // A conta nascia lenta: o prompt manda a fala terminar em ~7s e este corte
        // limitava a 15 palavras -> 129 wpm no melhor caso, 111 wpm com as 13 que
        // saiam de fato. A CONTRAPROVA veio do proprio lote: o video 1077 ignorou o
        // roteiro e falou ~20 palavras proprias em 8s a 165 wpm, ritmo natural.
        // O motor nao e lento; o roteiro e curto.
        //
        // Conserto: mais PALAVRAS pra preencher o tempo, e nao voz mais rapida
        // (acelerar a locucao ja tinha deixado video ininteligivel no SEL-411).
        // 19 palavras em 7s = ~163 wpm. E agora existe PISO: frase curta demais
        // vira silencio no fim, que foi o que aconteceu com o video de 8 palavras.
        $TETO = 19;
        $palavras = preg_split('/\s+/u', trim($fala), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($palavras) <= $TETO) {
            return trim($fala);
        }

        $frases = preg_split('/(?<=[.!?])\s+/u', trim($fala), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $saida  = '';
        foreach ($frases as $f) {
            $tentativa = trim($saida . ' ' . $f);
            if (count(preg_split('/\s+/u', $tentativa, -1, PREG_SPLIT_NO_EMPTY) ?: []) > $TETO) {
                break;
            }
            $saida = $tentativa;
        }

        // Nem uma frase inteira coube (frase unica e longa): corta na palavra, com reticencia.
        if ($saida === '') {
            $saida = implode(' ', array_slice($palavras, 0, $TETO));
        }

        // SEL-FALA-RITMO: piso. Menos que isto e silencio garantido no fim do video.
        if (count(preg_split('/\s+/u', $saida, -1, PREG_SPLIT_NO_EMPTY) ?: []) < 12) {
            Log::error('[SEL-FALA-RITMO] fala CURTA DEMAIS pro tempo do video — vai sobrar silencio', [
                'fala' => mb_substr($saida, 0, 120),
                'palavras' => count(preg_split('/\s+/u', $saida, -1, PREG_SPLIT_NO_EMPTY) ?: []),
                'minimo_saudavel' => 12,
            ]);
        }

        Log::error('[SEL-TROCAPERSONAGEM] fala encurtada pra caber em 8s', [
            'de'   => count($palavras),
            'para' => count(preg_split('/\s+/u', $saida, -1, PREG_SPLIT_NO_EMPTY) ?: []),
        ]);

        return $saida;
    }

}
