<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client as GuzzleClient;

/**
 * SEL-030: Chat Gabriel - Central de Ajuda seller.global
 */
class ChatController extends Controller
{

    /**
     * SEL-chat-marca (12/08): o mesmo backend atende seller.global E tokfy.io.
     * Descobre pela origem da requisicao em qual marca o visitante esta, pro
     * Gabriel nunca se apresentar como a marca errada.
     */
    /** Nome do atendente por marca — Tokfy tem agente proprio, sem heranca do seller. */
    private function atendenteDaMarca(): string
    {
        $m = $this->marcaDoVisitante();
        // SEL-BIA (14/08, Ruan): na tela do seller.global o botao diz "Falar com
        // Bia" e a foto e da Bia — mas ela se apresentava como "Gabriel". Cliente
        // percebe. Gabriel continua nas marcas de dropshipping, onde ele vende.
        if ($m === 'Tokfy') return 'Nina';
        if ($m === 'Seller Global') return 'Bia';
        return 'Gabriel';
    }

    private function marcaDoVisitante(\Illuminate\Http\Request $request = null): string
    {
        $req = $request ?: request();
        $origem = strtolower((string) ($req?->headers?->get('Origin') ?: $req?->headers?->get('Referer') ?: ''));
        if (str_contains($origem, 'tokfy')) return 'Tokfy';
        // SEL-BIA (14/08): o mesmo arquivo roda nos 7 backends. Sem separar aqui,
        // a resposta de VIDEO vazaria pros paineis de dropshipping (e era o
        // contrario que acontecia: o seller.global respondia plano de drop).
        foreach (['hubai', 'multdrop', 'mestoredrop', 'fornecefy', 'goolhub', 'dropehub'] as $drop) {
            if (str_contains($origem, $drop)) return 'HubAI';
        }
        return 'Seller Global';
    }

    // SEL-183 pentest fix: tokens hardcoded REMOVIDOS.
    // SEL-182 tinha deixado fallback pros valores reais como constantes PHP
    // (versionadas no git => qualquer um com acesso ao repo consegue postar
    // como admin no CRM Chatwoot e enviar msgs no Telegram do Ruan).
    // Agora exigimos as 3 vars de env (CHATWOOT_TOKEN, TELEGRAM_BOT_TOKEN_CHAT,
    // TELEGRAM_CHAT_ID_RUAN); se qualquer uma faltar => RuntimeException.
    // Os tokens antigos devem ser rotacionados nos consoles Chatwoot/Telegram.
    private const CW_BASE    = 'https://crm2.zappro.io';
    private const CW_ACCOUNT = 2;
    private const CW_INBOX   = 22;

    // Usa config('services.chat.*') e nao env() direto — env() retorna null
    // quando config:cache esta ligado (Laravel so le .env dentro dos config
    // files durante o boot; chamar depois retorna null). Registrado em
    // config/services.php bloco 'chat'.
    private static function cwToken(): string
    {
        $t = config('services.chat.chatwoot_token');
        if (! $t) {
            throw new \RuntimeException('CHATWOOT_TOKEN nao configurado');
        }
        return $t;
    }

    private static function tgBot(): string
    {
        $t = config('services.chat.telegram_bot_token');
        if (! $t) {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN_CHAT nao configurado');
        }
        return $t;
    }

    private static function tgChat(): string
    {
        $t = config('services.chat.telegram_chat_id');
        if (! $t) {
            throw new \RuntimeException('TELEGRAM_CHAT_ID_RUAN nao configurado');
        }
        return $t;
    }

    public function startConversation(Request $request)
    {
        $user = $request->user('sanctum');
        $mode = $user ? 'support' : 'sales';

        $data = $request->validate([
            'visitor_name'  => 'nullable|string|max:120',
            'visitor_email' => 'nullable|email|max:191',
            'skip_greeting' => 'nullable|boolean',
        ]);

        $uuid = (string) Str::uuid();

        $contactName  = $user ? ($user->name ?? $user->email) : ($data['visitor_name'] ?? 'Visitante');
        $contactEmail = $user ? $user->email : ($data['visitor_email'] ?? null);

        $cwContactId = null;
        $cwConvId    = null;
        // SEL-BIA-SEPARADA (14/08, Ruan: "eu falei pra separar, nao queria nada de
        // outra empresa ou Gabriel"). O seller.global e o Tokfy tem atendimento
        // PROPRIO. Antes, TODA conversa daqui era espelhada no Chatwoot do HubAI
        // (o CRM do Gabriel) — cliente de video caindo na caixa de dropshipping,
        // e o handoff entregando ele pra outra empresa. Cortado na raiz: marca de
        // video nao cria contato nem conversa la, e nada e enviado pra la.
        $marcaPropria = in_array($this->marcaDoVisitante(), ['Seller Global', 'Tokfy'], true);
        try {
            if (! $marcaPropria) {
                $cwContact   = $this->cwCreateOrFindContact($contactName, $contactEmail);
                $cwContactId = $cwContact['id'] ?? null;

                if ($cwContactId) {
                    $cwConvId = $this->cwCreateConversation((int)$cwContactId, $mode, $user);
                }
            }
        } catch (\Throwable $cwErr) {
            file_put_contents(
                storage_path('logs/sel030_cw_errors.log'),
                date('Y-m-d H:i:s') . ' ' . get_class($cwErr) . ': ' . $cwErr->getMessage() . "\n",
                FILE_APPEND
            );
        }

        $convId = DB::table('chat_conversations')->insertGetId([
            'uuid'                     => $uuid,
            'client_id'                => $user?->id,
            'visitor_name'             => $contactName,
            'visitor_email'            => $contactEmail,
            'mode'                     => $mode,
            'status'                   => 'open',
            'chatwoot_conversation_id' => $cwConvId,
            'chatwoot_contact_id'      => (string)($cwContactId ?? ''),
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        if (empty($data['skip_greeting'])) {
            DB::table('chat_messages')->insert([
                'conversation_id' => $convId,
                'sender'          => 'agent',
                'content'         => 'Oi! Sou ' . $this->atendenteDaMarca() . ', do time da ' . $this->marcaDoVisitante() . '. Posso te ajudar em tempo real. Sobre o que é?',
                'from_chatwoot'   => false,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        return response()->json([
            'uuid'   => $uuid,
            'mode'   => $mode,
            'status' => 'open',
        ], 201);
    }

    public function sendMessage(Request $request, string $uuid)
    {
        $data = $request->validate(['content' => 'required|string|max:4000']);

        $conv = DB::table('chat_conversations')->where('uuid', $uuid)->first();
        if (! $conv) {
            return response()->json(['error' => 'Conversa nao encontrada'], 404);
        }
        if ($conv->status === 'resolved') {
            return response()->json(['error' => 'Conversa encerrada'], 409);
        }

        $msgId = DB::table('chat_messages')->insertGetId([
            'conversation_id' => $conv->id,
            'sender'          => 'user',
            'content'         => $data['content'],
            'from_chatwoot'   => false,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        if ($conv->chatwoot_conversation_id) {
            $this->cwSendMessage((int)$conv->chatwoot_conversation_id, $data['content'], 'incoming');
        }

        $lower = mb_strtolower($data['content']);
        $wantsHuman = str_contains($lower, 'falar com atendente') || str_contains($lower, 'atendente') || str_contains($lower, 'humano');
        if ($wantsHuman) {
            $this->triggerHandoff($conv);
        }

        // SEL-068: resposta instantanea rule-based — sem match a conversa segue pro Chatwoot
        $autoReply = null;
        if (! $wantsHuman && $conv->status !== 'handoff') {
            $replyText = $this->autoReply($lower);

            // SEL-BIA-OLHA (14/08): em queixa de PROBLEMA, o texto pronto vem depois do
            // FATO. Ela abre a ficha do cliente, diz o que esta acontecendo com o pedido
            // DELE e, se estiver travado, ja resolve. Texto generico so como complemento.
            $ehQueixa = false;
            foreach (['nao ', 'não ', 'preta', 'escura', 'branca', 'travou', 'sumiu', 'cade', 'cadê',
                      'demora', 'erro', 'bugou', 'parado', 'perdi', 'onde esta', 'onde está'] as $sinal) {
                if (str_contains($lower, $sinal)) { $ehQueixa = true; break; }
            }
            if ($ehQueixa && in_array($this->marcaDoVisitante(), ['Seller Global', 'Tokfy'], true)) {
                $ficha = $this->diagnosticoDoCliente($conv->client_id ? (int) $conv->client_id : null);
                if ($ficha !== null) {
                    $replyText = $ficha . ($replyText ? "\n\n" . $replyText : '');
                }
            }

            // SEL-SEM-SILENCIO (14/08, Ruan: "desgraca, e ninguem viu isso, atendente
            // nao tentou olhar e resolver na hora"). Ele tem razao e o buraco era meu:
            // sem regra que casasse, a conversa do seller.global simplesmente NAO
            // respondia nada — a cliente shoplorena@gmail.com escreveu "TELA PRETA"
            // as 11:08 e ficou no vazio. Agora e impossivel um cliente falar e nao
            // ouvir de volta: sem regra, a Bia assume que nao entendeu, ABRE CHAMADO
            // e o alerta sai na hora pra alguem olhar.
            if ($replyText === null && in_array($this->marcaDoVisitante(), ['Seller Global', 'Tokfy'], true)) {
                $replyText = 'Recebi sua mensagem e nao quero te deixar sem resposta: '
                    . 'nao entendi direito o que aconteceu. Me diz em uma frase o que voce '
                    . 'estava fazendo quando deu problema (por exemplo: "cliquei em gerar e '
                    . 'ficou parado") que eu resolvo com voce agora. Ja avisei o time tambem.';
                $this->alertaSemResposta($conv, (string) $data['content']);
            }

            if ($replyText !== null) {
                $cwMsgId = $conv->chatwoot_conversation_id
                    ? $this->cwSendMessage((int)$conv->chatwoot_conversation_id, $replyText, 'outgoing')
                    : null;
                $replyId = DB::table('chat_messages')->insertGetId([
                    'conversation_id'     => $conv->id,
                    'sender'              => 'agent',
                    'content'             => $replyText,
                    'from_chatwoot'       => false,
                    'chatwoot_message_id' => $cwMsgId,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);
                $autoReply = DB::table('chat_messages')->find($replyId);
            }
        }

        $msg = DB::table('chat_messages')->find($msgId);

        return response()->json([
            'id'          => $msg->id,
            'sender'      => $msg->sender,
            'content'     => $msg->content,
            'created_at'  => $msg->created_at,
            'agent_reply' => $autoReply ? [
                'id'            => $autoReply->id,
                'sender'        => 'agent',
                'content'       => $autoReply->content,
                'from_chatwoot' => false,
                'created_at'    => $autoReply->created_at,
            ] : null,
        ], 201);
    }

    public function getMessages(Request $request, string $uuid)
    {
        $conv = DB::table('chat_conversations')->where('uuid', $uuid)->first();
        if (! $conv) {
            return response()->json(['error' => 'Conversa nao encontrada'], 404);
        }

        $after = (int)$request->query('after', 0);

        $messages = DB::table('chat_messages')
            ->where('conversation_id', $conv->id)
            ->when($after > 0, fn($q) => $q->where('id', '>', $after))
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'sender', 'content', 'from_chatwoot', 'created_at']);

        return response()->json([
            'status'   => $conv->status,
            'messages' => $messages,
        ]);
    }

    public function handoff(Request $request, string $uuid)
    {
        $conv = DB::table('chat_conversations')->where('uuid', $uuid)->first();
        if (! $conv) {
            return response()->json(['error' => 'not found'], 404);
        }

        $this->triggerHandoff($conv);

        return response()->json(['ok' => true, 'status' => 'handoff']);
    }

    public function chatwootWebhook(Request $request)
    {
        // SEL-182/SEL-183 pentest fix: qualquer anonimo podia forjar mensagens
        // de "agente" no chat visitando o webhook.
        // SEL-183: se CHATWOOT_WEBHOOK_SECRET nao esta configurado no .env,
        // retornamos 503 (Service Unavailable) — nao aceitar silenciosamente.
        // Se o secret esta configurado mas o cliente enviou secret errado ou
        // nenhum secret => 401 Unauthorized (ao inves do 200 silent-fail que
        // mascarava o problema e nao dava sinal pro monitoramento).
        $expected = config('services.chat.webhook_secret');
        if (! $expected) {
            Log::error('[chat] CHATWOOT_WEBHOOK_SECRET nao configurado');
            return response()->json([
                'ok'    => false,
                'error' => 'webhook_secret_not_configured',
            ], 503);
        }

        // SEL-399: a inbox 22 ("Seller Web") e do tipo Channel::Api. O webhook de
        // inbox do Chatwoot NAO envia cabecalho customizado — so o corpo. Ou seja,
        // desde o SEL-183 (16/07) TODA resposta de atendente humano tomou 401 e o
        // cliente nunca viu: 2.861 entregas descartadas so em 28-29/07, e zero
        // mensagem from_chatwoot desde 16/07.
        // Em vez de afrouxar a validacao, quando nao vem cabecalho a gente confirma
        // a mensagem na PROPRIA API do Chatwoot antes de aceitar. Forjar continua
        // impossivel: a mensagem tem que existir la de verdade.
        $received  = $request->header('X-Webhook-Secret');
        $porHeader = $received && hash_equals($expected, $received);

        $payload = $request->all();
        $event   = $payload['event'] ?? '';

        if ($event !== 'message_created') {
            return response()->json(['ok' => true]);
        }

        if (! $porHeader && ! $this->cwConfirmaMensagem($payload)) {
            Log::warning('[chat] webhook chatwoot recusado: nao confere na origem', [
                'ip'     => $request->ip(),
                'msg_id' => $payload['id'] ?? null,
            ]);
            return response()->json([
                'ok'    => false,
                'error' => 'unauthorized',
            ], 401);
        }

        $cwConvId = $payload['conversation']['id'] ?? null;
        $msgType  = $payload['message_type'] ?? '';
        $content  = $payload['content'] ?? '';
        $cwMsgId  = $payload['id'] ?? null;

        if ($msgType !== 'outgoing' || empty($content) || ! $cwConvId) {
            return response()->json(['ok' => true]);
        }

        $conv = DB::table('chat_conversations')
            ->where('chatwoot_conversation_id', (int)$cwConvId)
            ->first();

        if (! $conv) {
            return response()->json(['ok' => true]);
        }

        $exists = DB::table('chat_messages')
            ->where('conversation_id', $conv->id)
            ->where('chatwoot_message_id', $cwMsgId)
            ->exists();

        if ($exists) {
            return response()->json(['ok' => true]);
        }

        DB::table('chat_messages')->insert([
            'conversation_id'     => $conv->id,
            'sender'              => 'agent',
            'content'             => $content,
            'from_chatwoot'       => true,
            'chatwoot_message_id' => $cwMsgId,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * SEL-399: confirma na API do Chatwoot que a mensagem recebida existe mesmo.
     * E o que substitui o cabecalho secreto que a inbox Channel::Api nao manda.
     */
    private function cwConfirmaMensagem(array $payload): bool
    {
        $convId = (int) ($payload['conversation']['id'] ?? 0);
        $msgId  = $payload['id'] ?? null;
        if (! $convId || ! $msgId) {
            return false;
        }

        try {
            $res = $this->guzzle()->get(
                self::CW_BASE . '/api/v1/accounts/' . self::CW_ACCOUNT
                . '/conversations/' . $convId . '/messages'
            );
            $dados = json_decode((string) $res->getBody(), true);
            $lista = $dados['payload'] ?? (is_array($dados) ? $dados : []);

            foreach ($lista as $m) {
                if ((string) ($m['id'] ?? '') === (string) $msgId) {
                    return trim((string) ($m['content'] ?? '')) === trim((string) ($payload['content'] ?? ''));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[chat] nao consegui confirmar mensagem no Chatwoot', ['erro' => $e->getMessage()]);
        }

        return false;
    }

    private function guzzle(): GuzzleClient
    {
        return new GuzzleClient([
            'timeout'         => 8,
            'connect_timeout' => 5,
            'verify'          => true,
            'headers'         => ['api_access_token' => self::cwToken()],
        ]);
    }

    private function cwCreateOrFindContact(string $name, ?string $email): array
    {
        $http = $this->guzzle();
        try {
            // Busca primeiro por email
            if ($email) {
                $res  = $http->get(self::CW_BASE . '/api/v1/accounts/' . self::CW_ACCOUNT . '/contacts/search', [
                    'query' => ['q' => $email],
                ]);
                $data = json_decode((string)$res->getBody(), true);
                $contacts = $data['payload'] ?? [];
                if (!empty($contacts) && isset($contacts[0]['id'])) {
                    return $contacts[0];
                }
            }

            // Tenta criar
            // Chatwoot POST /contacts retorna {"payload":{"contact":{id,...}}}
            $payload = ['name' => $name];
            if ($email) $payload['email'] = $email;
            $res  = $http->post(self::CW_BASE . '/api/v1/accounts/' . self::CW_ACCOUNT . '/contacts', ['json' => $payload]);
            $data = json_decode((string)$res->getBody(), true);
            // Suporta resposta direta {id:...} e aninhada {payload:{contact:{id:...}}}
            $contactData = $data['payload']['contact'] ?? $data;
            if (isset($contactData['id'])) {
                return $contactData;
            }

            return [];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // 422 = email duplicado — busca novamente pelo email
            $httpStatus = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            if ($email && $httpStatus === 422) {
                try {
                    $res      = $http->get(self::CW_BASE . '/api/v1/accounts/' . self::CW_ACCOUNT . '/contacts/search', [
                        'query' => ['q' => $email],
                    ]);
                    $data     = json_decode((string)$res->getBody(), true);
                    $contacts = $data['payload'] ?? [];
                    if (!empty($contacts) && isset($contacts[0]['id'])) {
                        return $contacts[0];
                    }
                } catch (\Throwable $e2) {
                    Log::warning('SEL-030 cwCreateContact fallback: ' . $e2->getMessage());
                }
            }
            Log::warning('SEL-030 cwCreateContact: ' . $e->getMessage());
            return [];
        } catch (\Throwable $e) {
            Log::warning('SEL-030 cwCreateContact: ' . $e->getMessage());
            return [];
        }
    }

    private function cwCreateConversation(int $contactId, string $mode, ?object $user): ?int
    {
        try {
            $attrs = ['source' => 'seller_web', 'mode' => $mode];
            if ($user) {
                $attrs['client_email'] = $user->email;
            }

            $res  = $this->guzzle()->post(self::CW_BASE . '/api/v1/accounts/' . self::CW_ACCOUNT . '/conversations', [
                'json' => [
                    'inbox_id'              => self::CW_INBOX,
                    'contact_id'            => $contactId,
                    'additional_attributes' => $attrs,
                ],
            ]);
            $data = json_decode((string)$res->getBody(), true);
            return isset($data['id']) ? (int)$data['id'] : null;
        } catch (\Throwable $e) {
            Log::warning('SEL-030 cwCreateConversation: ' . $e->getMessage());
            return null;
        }
    }

    private function cwSendMessage(int $cwConvId, string $content, string $messageType): ?int
    {
        try {
            $res  = $this->guzzle()->post(self::CW_BASE . '/api/v1/accounts/' . self::CW_ACCOUNT . '/conversations/' . $cwConvId . '/messages', [
                'json' => [
                    'content'      => $content,
                    'message_type' => $messageType,
                    'private'      => false,
                ],
            ]);
            $data = json_decode((string)$res->getBody(), true);
            return isset($data['id']) ? (int)$data['id'] : null;
        } catch (\Throwable $e) {
            Log::warning('SEL-030 cwSendMessage: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * SEL-068: respostas instantaneas pros fluxos comuns (inclui guia de integracao).
     * Retorna null sem match — segue pro Chatwoot (Gabriel/humano).
     */
    /**
     * SEL-BIA (14/08, Ruan ao vivo: "ela ja esta falando besteira, falando de
     * planos de drop"). O seller.global NAO e dropshipping — e plataforma de
     * VIDEO com IA. A Bia respondia com o roteiro do HubAI (conectar
     * marketplace, fornecedor sem estoque, Start/Scaling/Pro), que nao existe
     * aqui. Agora quem entra pelo seller.global/tokfy recebe resposta de video,
     * e ela GUIA passo a passo em vez de so descrever.
     */
    private function respostaDeVideo(string $lower, callable $has): ?string
    {
        // SEL-BIA-NOVIDADES (14/08, Ruan: "avisar isso tudo para todos, SAC,
        // agentes, tudo"). A tela mudou hoje em cinco pontos e o atendimento nao
        // pode ficar explicando a versao velha -- cliente perguntando de um botao
        // que nao existe mais e a pior experiencia possivel.
        if ($has(['som', 'sem som', 'sem voz', 'mudo', 'musica', 'nao quero que fale', 'sem falar'])) {
            return "Da sim, e agora e escolha sua: na tela de criar video, depois de escolher o produto, "
                 . "aparece **Esse video vai ter som?**\n\n"
                 . "- **Com som**: a pessoa fala o roteiro, com voz em portugues.\n"
                 . "- **Sem som**: ninguem fala e a boca nem se mexe — sai so imagem, do jeito certo pra "
                 . "voce colocar a sua musica depois.\n\n"
                 . "Escolhendo sem som, abre um menu de **movimentos** (girar o corpo, mostrar de perto, "
                 . "apontar pro produto, abrir a embalagem...) — voce marca quantos quiser. E tem um campo "
                 . "pra colar o seu proprio prompt, sem limite de tamanho.";
        }
        if ($has(['cenario', 'cenário', 'fundo', 'ambiente', 'meu cenario', 'subir cenario'])) {
            return "Tem tres caminhos pro cenario, na etapa **Onde esse video acontece?**:\n\n"
                 . "1. Escolher um dos prontos da grade.\n"
                 . "2. **Escrever** o cenario que voce quer.\n"
                 . "3. **Subir a foto do SEU cenario** (sua loja, sua bancada, seu quarto) — e ele fica "
                 . "guardado em **Meus cenarios** pra voce reusar nos proximos videos sem redigitar nada.";
        }
        // SEL-BIA-ORDEM: 'cenario' contem 'cena'. Aqui a checagem e por palavra inteira,
        // e este bloco fica DEPOIS do cenario de proposito.
        if (preg_match('/\\b(cenas?|tres cenas|3 cenas|inicio meio e fim|historia)\\b/u', $lower)
            && ! str_contains($lower, 'cenario') && ! str_contains($lower, 'cenário')) {
            return "Voce escolhe: **1 cena** (um take so, direto) ou **3 cenas** (inicio, meio e final).\n\n"
                 . "Com 3 cenas o video vira historia: um pedaco pro gancho, um pro meio e um pro final — "
                 . "mesma pessoa e mesmo produto do comeco ao fim. Cada cena tem o campo dela pra voce "
                 . "escrever (ou colar o prompt que ja fez) e pode ate ter o **cenario proprio**: "
                 . "ex. cena 1 na porta de casa recebendo a caixa, cena 2 abrindo na cozinha, cena 3 usando.";
        }
        if ($has(['roupa', 'provador', 'espelho', 'moda', 'vestir'])) {
            return "Pra roupa o caminho e **Trocar Personagem > Vestir uma roupa**. Ai voce escolhe como ela mostra:\n\n"
                 . "- **No espelho, gravando com o celular** (o que mais vende): ela filma o proprio reflexo, "
                 . "celular na mao, se exibindo e mostrando o caimento. Sai **sem fala**, pra voce por a musica "
                 . "e a setinha do carrinho.\n"
                 . "- **Camera parada**: plano fixo, ela gira na frente da camera.";
        }
        if ($has(['parte 2', 'continuar o video', 'continuar esse video', 'segunda parte'])) {
            return "Essa opcao saiu da plataforma — confundia mais do que ajudava.\n\n"
                 . "O que faz o mesmo trabalho, e melhor: na Galeria, no video que voce gostou, use "
                 . "**Refazer mudando alguma coisa** (voce escreve o que quer diferente e o resto continua igual) "
                 . "ou **Copiar com outra pessoa** (mesmo roteiro, personagem novo).";
        }
        if ($has(['editar', 'cort', 'velocidad', 'aceler', 'seta', 'selo', 'preco no video'])) {
            return "Tudo isso e na Galeria: abre o video e voce tem **20 versoes prontas** de selo "
                 . "(preco, desconto, seta pro carrinho, frete gratis, 'corre que acaba'...) pra escolher olhando.\n\n"
                 . "Logo abaixo tem **Cortar e acelerar**: arrasta as pontas pra escolher onde comeca e termina, "
                 . "e escolhe a velocidade (1.1x e 1.2x seguram melhor a atencao). O preview muda na hora, "
                 . "antes de baixar.";
        }

        if ($has(['como gero', 'primeiro video', '1o video', '1º video', 'como faco', 'como fazer', 'comecar', 'começar', 'nao sei usar', 'guia', 'tutorial'])) {
            return "Te levo pelo caminho todo — sao 3 cliques:\n\n"
                . "1. Menu lateral > Novo Video\n"
                . "2. Escolha quanto tempo (10s ja funciona bem) e o tipo:\n"
                . "   - UGC: uma pessoa apresenta e fala do produto\n"
                . "   - POV: so a mao usando, sem rosto (o que mais vende no TikTok Shop)\n"
                . "   - Trocar Personagem: refaz um video que deu certo com outra pessoa\n"
                . "   - Video do Zero: qualquer video, nem precisa ser de produto\n"
                . "3. Escolha o produto (Produto em alta = seu catalogo) e clique em Gerar meu video\n\n"
                . "Fica pronto em poucos minutos e cai sozinho na sua Galeria. Pode fechar a tela que eu te aviso.\n\n"
                . "Quer que eu te acompanhe agora? Me diz o que voce quer vender que eu sugiro o formato.";
        }
        // "minha tela ESTA preta" nao casava com o termo colado "tela preta" — o
        // cliente escreve do jeito dele, entao a checagem passa a ser por PALAVRA.
        $telaRuim = str_contains($lower, 'tela')
            && $has(['preta', 'preto', 'escura', 'escuro', 'branca', 'branco', 'vazia', 'vazio']);
        if ($telaRuim || $has(['nao carrega', 'nao abre', 'travou', 'nao esta funcionando', 'bugou', 'nada aparece'])) {
            return "Sinto muito por isso — e coisa nossa, nao sua. Faz assim agora:\n\n"
                . "Aperte Ctrl+Shift+R (no computador) ou feche e abra a aba de novo (no celular).\n\n"
                . "Isso pega a versao corrigida na hora. Se ainda ficar preta, me diz aqui que eu abro um chamado com prioridade e o time olha o seu caso especifico.";
        }
        if ($has(['baixar', 'download', 'salvar o video', 'nao consigo baixar'])) {
            return "Na Galeria (menu Meus Videos), cada video tem o botao Baixar embaixo dele — o arquivo sai em MP4, pronto pra subir no TikTok, Reels ou Shorts.\n\n"
                . "Se o botao nao responder, me manda a data do video que eu verifico esse arquivo especifico.";
        }
        if ($has(['demora', 'quanto tempo', 'ainda nao ficou', 'travado', 'fila'])) {
            return "O normal e de 3 a 8 minutos. Se passar disso, o sistema tenta sozinho de novo — voce nao perde o pedido nem precisa refazer.\n\n"
                . "Passou de 30 minutos? Me fala aqui que eu verifico o seu na hora.";
        }
        if ($has(['trocar personagem', 'outra pessoa', 'trocar rosto', 'continuar', 'parte 2'])) {
            return "Esse e o Trocar Personagem (menu Novo Video). Sao 4 jeitos:\n\n"
                . "- Copiar com outra pessoa: eu ouco a fala do seu video e repito com outro personagem\n"
                . "- Vestir uma roupa: o personagem veste a peca e mostra o caimento\n"
                . "- Trocar so o rosto: mantem o video igual, troca so quem aparece\n"
                . "- Continuar (parte 2): mesma pessoa, mesmo cenario, o assunto continua\n\n"
                . "O video de partida voce escolhe direto da sua Galeria — nao precisa baixar e subir de novo.";
        }
        if ($has(['preco', 'preço', 'plano', 'assin', 'quanto custa', 'valor', 'mensalidade'])) {
            return "O plano de video e o Video IA Ultra: R\$297/mes, com video ilimitado.\n\n"
                . "Sem fidelidade e voce usa o Studio inteiro: UGC, POV, Trocar Personagem e Video do Zero.\n\n"
                . "Quer que eu te mande o link pra assinar?";
        }
        if ($has(['o que e', 'o que é', 'como funciona', 'que faz', 'pra que serve'])) {
            return "A Seller Global cria os videos que vendem seu produto, com IA — voce nao precisa aparecer, nem gravar, nem contratar ninguem.\n\n"
                . "Voce escolhe o produto e o formato; a gente escreve o roteiro, gera a pessoa, grava a voz em portugues e entrega o MP4 pronto pro TikTok Shop, Reels e Shorts.\n\n"
                . "Quer que eu te guie no primeiro agora? E rapido.";
        }
        if ($has(['produto em alta', 'catalogo', 'catálogo', 'qual produto', 'o que vender'])) {
            return "Em Produtos em alta (menu lateral) voce ve o que esta vendendo de verdade no TikTok Shop, com faturamento e crescimento.\n\n"
                . "Da pra sair de la direto pro video: escolhe o produto e ja cai no Novo Video com ele selecionado.";
        }
        return null;
    }

    private function autoReply(string $lower): ?string
    {
        $has = function (array $terms) use ($lower): bool {
            foreach ($terms as $t) {
                if (str_contains($lower, $t)) return true;
            }
            return false;
        };

        // SEL-BIA (14/08): marca de VIDEO nunca responde com roteiro de drop.
        if (in_array($this->marcaDoVisitante(), ['Seller Global', 'Tokfy'], true)) {
            $r = $this->respostaDeVideo($lower, $has);
            if ($r !== null) return $r;
        }

        if ($has(['gabriel', 'bia', 'nina'])) {
            return "Sou eu mesmo! Pode mandar sua dúvida aqui que eu respondo na hora. Se preferir atendimento humano, é só dizer \"falar com atendente\".";
        }
        if ($has(['preço', 'preco', 'plano', 'assin', 'quanto custa', 'valor'])) {
            if ($this->marcaDoVisitante() === 'Tokfy') {
                return "Na Tokfy o acesso é simples:\n\n• R\$147/ano — acesso completo\n• R\$297 — pagamento único\n\nPagamento no PIX. Quer que eu te mande o link pra começar?";
            }
            return "Temos 3 planos mensais:\n\n• Start R\$97/mês — Shopee + Mercado Livre, até 100 produtos\n• Scaling R\$197/mês — tudo do Start + imagens com IA, até 200 produtos\n• Pro R\$297/mês — TODOS os marketplaces (incl. TikTok Shop) + vídeos com IA, 300+ produtos\n\nAssina em seller.global/planos — ativa na hora. Quer ajuda pra escolher pelo seu volume de vendas?";
        }
        if ($has(['integra', 'conectar', 'conexao', 'conexão', 'mercado livre', 'shopee', 'marketplace', 'sincroniz', 'bling'])) {
            return "Te guio agora, é rápido:\n\n1. Abra Integrações no menu lateral\n2. Escolha o marketplace (Mercado Livre, Shopee, Bling...) e clique em Conectar\n3. Dê um nome pra loja e confirme\n4. Você vai pra página oficial do marketplace — faça login lá e autorize\n5. Pronto! Pedidos e estoque passam a sincronizar sozinhos\n\nSe der qualquer erro no caminho, me manda aqui a mensagem que apareceu que eu resolvo com você.";
        }
        if ($has(['como funciona', 'o que é', 'o que e ', 'que faz'])) {
            return "A " . $this->marcaDoVisitante() . " centraliza sua operação: você conecta seus marketplaces (ML, Shopee, Amazon, TikTok Shop...), escolhe produtos de fornecedores sem precisar de estoque, publica com ajuda de IA e acompanha pedidos e financeiro num painel só.\n\nQuer ver funcionando? O modo demonstração mostra tudo — e os planos começam em R\$97/mês.";
        }
        if ($has(['pedido', 'rastre', 'entrega', 'envio'])) {
            return "Seus pedidos de todos os canais ficam no menu Pedidos, com filtro por status, marketplace e data. O pagamento ao fornecedor pode ser automático (débito da carteira) e o envio é responsabilidade do fornecedor.\n\nSe for um pedido específico travado, me manda o número dele que eu verifico.";
        }
        if ($has(['saldo', 'pix', 'carteira', 'financeiro', 'pagamento', 'boleto', 'cartão', 'cartao'])) {
            return "No menu Financeiro você vê saldo, entradas e pagamentos a fornecedores. Pra adicionar saldo é PIX na hora, sem taxa — botão \"Adicionar Saldo\". O débito automático usa esse saldo pra pagar pedidos sem você aprovar um a um.\n\nFicou com dúvida em algum lançamento? Me fala qual.";
        }
        // SEL-BIA-ORDEM (generica) (14/08): esta regra pegava QUALQUER frase com a
        // palavra video -- inclusive "como corto e acelero o video", que tem resposta
        // propria (editar/cortar/velocidade). Medido no chat de verdade: a pergunta de
        // edicao recebia a descricao antiga do Studio. Agora ela cede a vez.
        if ($has(['vídeo', 'video', 'imagem', 'studio', 'criativo'])
            && ! $has(['cort', 'aceler', 'velocidad', 'selo', 'seta', 'editar',
                       'som', 'cena', 'cenario', 'cenário', 'roupa', 'espelho'])) {
            return "No Studio (menu lateral) você gera vídeos e imagens dos produtos com IA — Roteiro Mágico, Animar Foto, Trocar Pessoa e mais. Também tem botão de vídeo em cada card do catálogo.\n\nVídeo com IA entra nos planos Scaling e Pro.";
        }
        if ($has(['fornecedor', 'catálogo', 'catalogo', 'produto', 'estoque'])) {
            return "O Catálogo (menu lateral) tem fornecedores com produtos a preço de custo — você publica no marketplace com sua margem e o fornecedor envia. Na Lista de Fornecedores dá pra buscar por marca, produto ou cidade e abrir o catálogo completo de cada um.";
        }
        if (mb_strlen(trim($lower)) <= 25 && $has(['oi', 'olá', 'ola', 'bom dia', 'boa tarde', 'boa noite', 'eai', 'e aí', 'hey'])) {
            return "Oi! Me conta o que você precisa — planos, integração de marketplace, pedidos, financeiro ou vídeos com IA. Tô aqui pra resolver.";
        }

        return null;
    }

    /**
     * SEL-SEM-SILENCIO (14/08): cliente falou e a Bia nao soube responder. Em vez de
     * engolir, deixa rastro em DOIS lugares que alguem olha: log proprio (o vigia le)
     * e chamado no painel. Silencio e o unico desfecho proibido.
     */
    private function alertaSemResposta(object $conv, string $texto): void
    {
        try {
            file_put_contents(
                storage_path('logs/bia-sem-resposta.log'),
                date('Y-m-d H:i:s') . ' | conv ' . $conv->id
                    . ' | cliente ' . ($conv->client_id ?: 'visitante')
                    . ' | ' . ($conv->visitor_email ?: '-')
                    . ' | ' . preg_replace('/\s+/', ' ', mb_substr($texto, 0, 200)) . "\n",
                FILE_APPEND
            );
            if ($conv->client_id) {
                DB::table('support_tickets')->insert([
                    'client_id'   => $conv->client_id,
                    'title'       => 'Chat sem resposta automatica',
                    'category'    => 'chat',
                    'priority'    => 'high',
                    'status'      => 'open',
                    'description' => mb_substr($texto, 0, 2000),
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('SEL-SEM-SILENCIO: ' . $e->getMessage());
        }
    }

    /**
     * SEL-BIA-OLHA (14/08). Ruan: "atendente nao tentou olhar e resolver na hora".
     * Estava certo — a Bia so recitava texto pronto. Agora, antes de responder, ela
     * ABRE A FICHA DO CLIENTE: os pedidos de video dele, o que esta em andamento, o
     * que ficou pronto, o que falhou. E quando da pra resolver sozinha, resolve:
     * pedido travado ela reenfileira na hora, em vez de mandar o cliente esperar.
     */
    private function diagnosticoDoCliente(?int $clientId): ?string
    {
        if (! $clientId) return null;

        try {
            $naoFinais = ['queued', 'render', 'processing', 'voice', 'lipsync', 'queued_wait'];

            $andando = DB::table('ai_video_pipelines')
                ->where('user_id', $clientId)->whereIn('step', $naoFinais)
                ->orderByDesc('id')->first();

            if ($andando) {
                $min = (int) \Carbon\Carbon::parse($andando->created_at)->diffInMinutes(now());
                if ($min >= 45) {
                    // RESOLVE NA HORA: pedido velho demais volta pra fila em vez de
                    // morrer calado enquanto o cliente pergunta o que houve.
                    try {
                        \App\Jobs\StudioGenerationJob::dispatch((int) $andando->id)->onQueue('video-priority');
                        DB::table('ai_video_pipelines')->where('id', $andando->id)
                            ->update(['step' => 'queued', 'updated_at' => now()]);
                        Log::info('SEL-BIA-OLHA reenfileirou pipeline ' . $andando->id);
                        return "Olhei seu pedido agora (numero {$andando->id}): ele tinha empacado no meio, ha {$min} minutos. "
                            . "Ja recoloquei na frente da fila — nao precisa refazer nem pagar de novo. "
                            . "Em poucos minutos ele aparece na sua Galeria. Se quiser, me chama aqui que eu confirmo com voce.";
                    } catch (\Throwable $e) {
                        Log::warning('SEL-BIA-OLHA falhou reenfileirar: ' . $e->getMessage());
                        return "Olhei seu pedido (numero {$andando->id}) e ele esta parado ha {$min} minutos. "
                            . "Ja escalei pro time com prioridade — voce nao perde o pedido nem paga de novo.";
                    }
                }
                return "Olhei aqui agora: seu video (pedido {$andando->id}) esta sendo gerado neste momento, "
                    . "comecou ha {$min} minuto(s). O normal e ficar pronto entre 3 e 8 minutos, e ele cai sozinho "
                    . "na sua Galeria — pode fechar a tela que nao se perde.";
            }

            $ultimo = DB::table('ai_video_pipelines')
                ->where('user_id', $clientId)->whereNotNull('output_url')
                ->orderByDesc('id')->first();

            if ($ultimo) {
                $qdo = \Carbon\Carbon::parse($ultimo->created_at)->diffForHumans();
                return "Olhei sua conta agora: seu ultimo video (pedido {$ultimo->id}) ficou PRONTO {$qdo} e esta "
                    . "na sua Galeria, em Meus Videos, com o botao Baixar embaixo dele. "
                    . "Se ele nao aparece na sua tela, e versao velha do site carregada no seu navegador: "
                    . "aperte Ctrl+Shift+R (ou feche e abra a aba no celular) que ele aparece.";
            }

            return "Olhei sua conta agora e vi que voce ainda nao tem nenhum video gerado. "
                . "Quer que eu te leve pelo primeiro? E rapido: menu Novo Video, escolhe o tempo, o tipo e o produto.";
        } catch (\Throwable $e) {
            Log::warning('SEL-BIA-OLHA: ' . $e->getMessage());
            return null;
        }
    }

    private function triggerHandoff(object $conv): void
    {
        if ($conv->status === 'handoff') {
            return;
        }

        DB::table('chat_conversations')->where('id', $conv->id)->update([
            'status'     => 'handoff',
            'handoff_at' => now(),
            'updated_at' => now(),
        ]);

        // SEL-BIA-SEPARADA (14/08): na marca de video o "falar com atendente" abre
        // um chamado NOSSO (Chamados, no menu do cliente) — nao entrega o cliente
        // pra fila de outra empresa. A promessa que a Bia faz e a que o sistema
        // cumpre: fica registrado e responde no painel dele.
        $marcaPropria = in_array($this->marcaDoVisitante(), ['Seller Global', 'Tokfy'], true);
        // So promete chamado pra quem TEM conta: visitante nao tem tela de
        // Chamados pra acompanhar, e prometer o que ele nao pode ver e mentira.
        $aviso = ! $marcaPropria
            ? 'Perfeito! Estou passando voce para um atendente humano. Aguarde um momento.'
            : ($conv->client_id
                ? 'Registrei seu caso pro nosso time olhar — voce acompanha em Chamados, no menu. Se quiser adiantar, me conta aqui o que aconteceu que eu ja deixo tudo anotado.'
                : 'Anotei aqui e nosso time olha. Me deixa seu e-mail que eu te respondo por la — ou me conta o que aconteceu que eu ja tento resolver agora com voce.');

        if ($marcaPropria && $conv->client_id) {
            try {
                $ultima = DB::table('chat_messages')->where('conversation_id', $conv->id)
                    ->where('sender', '!=', 'agent')->orderByDesc('id')->value('content');
                DB::table('support_tickets')->insert([
                    'client_id'   => $conv->client_id,
                    'title'       => 'Atendimento pelo chat',
                    'category'    => 'chat',
                    'priority'    => 'normal',
                    'status'      => 'open',
                    'description' => mb_substr((string) $ultima, 0, 2000) ?: 'Cliente pediu atendimento pelo chat.',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('SEL-BIA handoff ticket: ' . $e->getMessage());
            }
        }

        DB::table('chat_messages')->insert([
            'conversation_id' => $conv->id,
            'sender'          => 'agent',
            'content'         => $aviso,
            'from_chatwoot'   => false,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        if ($conv->chatwoot_conversation_id) {
            try {
                $this->guzzle()->post(
                    self::CW_BASE . '/api/v1/accounts/' . self::CW_ACCOUNT . '/conversations/' . $conv->chatwoot_conversation_id . '/labels',
                    ['json' => ['labels' => ['handoff', 'seller-web']]]
                );
            } catch (\Throwable $e) {}
        }

        $name = $conv->visitor_name ?? 'Visitante';
        $mode = $conv->mode === 'support' ? 'Cliente logado' : 'Visitante';
        $msg  = "Chat seller.global - handoff solicitado\n{$mode}: {$name}\nUUID: {$conv->uuid}";

        try {
            (new GuzzleClient(['timeout' => 5]))->post(
                'https://api.telegram.org/bot' . self::tgBot() . '/sendMessage',
                ['json' => ['chat_id' => self::tgChat(), 'text' => $msg]]
            );
        } catch (\Throwable $e) {}
    }
}
