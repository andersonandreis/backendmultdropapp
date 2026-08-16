<?php

namespace App\Services\Ai;

use App\Jobs\KlingBrowserGenerateJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SEL-381 — Kling via NAVEGADOR (Playwright + sessão consumer).
 * Interface compatível com KlingService (API dev) — imageToVideo/textToVideo retornam
 * {code, data:{task_id,task_status}} igual API, mas por baixo enfileira job Node.
 * getVideoTask(taskId) lê Cache atualizado pelo job.
 */
class KlingBrowserService extends KlingService
{
    public function isConfigured(): bool
    {
        return (bool) env('KLING_BROWSER_ENABLED', false);
    }

    /**
     * AUDIT#6 — Redis (compartilhado entre queue worker + web request) com fallback.
     * Cache 'file' padrão do Laravel funciona OK entre workers do mesmo user unix,
     * mas Redis é atômico e mais confiável pra estado polling.
     */
    /**
     * SEL-387 F3: o Kling fala INGLES por padrao. So o caminho do chat com prompt
     * vazio montava as 10 secoes com "AUDIO (diegetic voice — SEMPRE em portugues
     * brasileiro)" e "IDIOMA: portugues brasileiro". Todos os outros caminhos
     * (POV, efeito viral, avatar, fallback do chat) mandavam prompt proprio sem
     * dizer idioma nenhum — e o video saia narrado em ingles.
     * Ruan, 29/07: dos videos gerados, so o que tinha essas duas linhas ficou bom.
     * Aqui a gente garante a diretiva em TODO prompt que sai daqui pro Kling.
     */
    public static function garanteIdiomaPtBr(string $prompt): string
    {
        return self::garanteIdioma($prompt, VideoLanguageService::PT);
    }

    /**
     * SEL-417: o idioma passa a ser escolhido, em vez de cravado.
     *
     * Antes isto forçava pt-BR em TODO prompt — e pt-BR é exatamente o idioma em
     * que o áudio nativo do Kling sai macarrônico. O cliente não estava
     * escolhendo errado: o sistema escolhia por ele, sempre o pior caso.
     *
     * O bloco do pt-BR continua idêntico ao que o SEL-387 deixou (é o que ficou
     * bom no teste do Ruan em 29/07) — só que agora só é aplicado quando o
     * idioma pedido é português, que hoje é caminho de super_admin/demo.
     */
    public static function garanteIdioma(string $prompt, ?string $lang = null): string
    {
        $p = trim($prompt);
        if ($p === '') {
            return $p;
        }
        if (mb_stripos($p, 'IDIOMA:') !== false) {
            return $p;
        }

        $lang      = VideoLanguageService::normaliza($lang);
        $tem_audio = mb_stripos($p, 'AUDIO (diegetic') !== false;
        $extra     = [];

        if ($lang === VideoLanguageService::PT) {
            if (! $tem_audio) {
                $extra[] = 'AUDIO (diegetic voice — SEMPRE em portugues brasileiro): fala natural da apresentadora em portugues do Brasil.';
            }
            $extra[] = 'IDIOMA: portugues do BRASIL (pt-BR). Voz feminina jovem brasileira, sotaque neutro do '
                     . 'sudeste, prosodia brasileira. PROIBIDO portugues de Portugal (pt-PT), sotaque europeu '
                     . 'ou lusitano. PROIBIDO "tu"/"vos" — usar "voce". PROIBIDO leitura robotica.';
        } elseif ($lang === 'en') {
            if (! $tem_audio) {
                $extra[] = 'AUDIO (diegetic voice — ALWAYS in English): natural presenter speech in English.';
            }
            $extra[] = 'IDIOMA: ingles (en-US). Voz feminina jovem, sotaque americano neutro. '
                     . 'PROIBIDO leitura robotica.';
        } else {
            // es-419 (padrão do cliente)
            if (! $tem_audio) {
                $extra[] = 'AUDIO (diegetic voice — SIEMPRE en espanol latinoamericano): habla natural de la presentadora en espanol.';
            }
            $extra[] = 'IDIOMA: espanol latinoamericano neutro (es-419). Voz femenina joven, acento neutro, '
                     . 'prosodia latinoamericana. PROHIBIDO espanol de Espana (castellano peninsular), '
                     . 'PROHIBIDO ceceo, PROHIBIDO "vosotros" — usar "ustedes". PROHIBIDA lectura robotica.';
        }

        return $p . "\n\n" . implode("\n", $extra);
    }

    public static function cache()
    {
        try {
            return Cache::store('redis');
        } catch (\Throwable $e) {
            return Cache::store();
        }
    }

    /**
     * IMAGE → VIDEO via navegador.
     * Aceita mesmo payload de KlingService: model_name, mode, duration, aspect_ratio,
     * image (URL), prompt, negative_prompt, camera_control, external_task_id.
     */
    public function imageToVideo(array $payload): array
    {
        // SEL-402: pedido sem imagem era aceito, respondia "submitted" pro cliente,
        // ocupava um worker de navegador por minutos e so ENTAO morria em
        // missing_image_url / missing_image_path_or_prompt. 17 das 28 falhas de
        // video em 24h eram isso. Agora recusa na hora, com motivo legivel.
        if (empty($payload['image'])) {
            throw new \InvalidArgumentException(
                'Nao da pra gerar video a partir de imagem sem imagem. Envie a foto do produto.'
            );
        }

        // SEL-402 F2: o worker por navegador exige input.prompt em TODOS os modos
        // (generate_video.js:164), mas alguns caminhos do Studio mandam so
        // `multi_prompt` (formato da API oficial do Kling, que o navegador nao
        // entende) ou nada. Resultado: o job montava tudo, chamava o worker e
        // levava missing_prompt em 2s — 13 falhas contra 1 sucesso em 29/07.
        // Aqui a gente deriva o texto em vez de deixar quebrar la na frente.
        if (trim((string) ($payload['prompt'] ?? '')) === '') {
            $derivado = '';
            if (!empty($payload['multi_prompt']) && is_array($payload['multi_prompt'])) {
                $partes = [];
                foreach ($payload['multi_prompt'] as $cena) {
                    $txt = is_array($cena) ? ($cena['prompt'] ?? $cena['text'] ?? '') : (string) $cena;
                    if (trim((string) $txt) !== '') { $partes[] = trim((string) $txt); }
                }
                $derivado = implode('. ', $partes);
            }
            // SEL-419: aqui existia um fallback que INVENTAVA o roteiro quando não
            // sobrava nada pra derivar:
            //
            //   'Video de produto para TikTok Shop, 9:16, cortes dinamicos, ...'
            //
            // Isso queimava crédito do Kling produzindo um vídeo genérico que
            // ninguém pediu — e o cliente recebia algo sem nenhuma relação com o
            // produto dele, sem nunca ser avisado de que o texto foi trocado. O
            // textToVideo() logo abaixo, no mesmo arquivo e pro mesmo caso,
            // sempre fez o certo: recusa e explica. Os dois agora se comportam
            // igual — sem roteiro, não gera.
            //
            // A derivação a partir de multi_prompt CONTINUA, e deve continuar:
            // ali o texto é do próprio cliente, só num formato que o worker por
            // navegador não entende. Derivar não é inventar.
            if ($derivado === '') {
                throw new \InvalidArgumentException(
                    'Nao da pra gerar video sem roteiro. Escreva o que o video deve mostrar.'
                );
            }
            $payload['prompt'] = mb_substr($derivado, 0, 2400);
            \Illuminate\Support\Facades\Log::info('[SEL-402 F2] prompt derivado no imageToVideo', [
                'origem' => 'multi_prompt',
                'tamanho' => mb_strlen($payload['prompt']),
            ]);
        }

        $payload['prompt'] = self::garanteIdioma((string) ($payload['prompt'] ?? ''), $payload['lang'] ?? null);

        $taskId = 'kbrowser_' . Str::uuid()->toString();

        // Estado inicial no Cache (TTL 30min)
        self::cache()->put("kling_browser:{$taskId}", [
            'task_id'     => $taskId,
            'task_status' => 'submitted',
            'created_at'  => now()->toIso8601String(),
            'kind'        => 'image2video',
            'payload'     => $payload,
        ], 1800);

        // Enfileirar Job. Prioridade admin: fila `kling-browser-priority` é lida ANTES da normal
        // (supervisor: --queue=kling-browser-priority,kling-browser). Passa `_priority`=admin
        // no payload OU chame o service com user admin autenticado (auto-detecta).
        $queue = $this->resolveQueue($payload); // TOK-22
        KlingBrowserGenerateJob::dispatch($taskId, 'image2video', $payload)
            ->onQueue($queue);

        return [
            'code' => 0,
            'message' => 'ok',
            'request_id' => $taskId,
            'data' => [
                'task_id'      => $taskId,
                'task_status'  => 'submitted',
                'task_info'    => ['external_task_id' => $payload['external_task_id'] ?? null],
                'created_at'   => now()->timestamp * 1000,
                'updated_at'   => now()->timestamp * 1000,
            ],
        ];
    }

    /** TEXT → VIDEO. */
    public function textToVideo(array $payload): array
    {
        // SEL-402: mesmo caso do imageToVideo — texto vazio virava missing_prompt
        // depois de queimar o worker.
        if (trim((string) ($payload['prompt'] ?? '')) === '') {
            throw new \InvalidArgumentException(
                'Nao da pra gerar video sem roteiro. Escreva o que o video deve mostrar.'
            );
        }

        $payload['prompt'] = self::garanteIdioma((string) ($payload['prompt'] ?? ''), $payload['lang'] ?? null);
        $taskId = 'kbrowser_' . Str::uuid()->toString();
        self::cache()->put("kling_browser:{$taskId}", [
            'task_id'     => $taskId,
            'task_status' => 'submitted',
            'created_at'  => now()->toIso8601String(),
            'kind'        => 'text2video',
            'payload'     => $payload,
        ], 1800);
        $queue = $this->resolveQueue($payload); // TOK-22
        KlingBrowserGenerateJob::dispatch($taskId, 'text2video', $payload)
            ->onQueue($queue);
        return [
            'code' => 0,
            'message' => 'ok',
            'request_id' => $taskId,
            'data' => [
                'task_id'      => $taskId,
                'task_status'  => 'submitted',
                'task_info'    => ['external_task_id' => $payload['external_task_id'] ?? null],
                'created_at'   => now()->timestamp * 1000,
                'updated_at'   => now()->timestamp * 1000,
            ],
        ];
    }

    /**
     * Consulta status/resultado do task. Compatível com KlingService::getVideoTask.
     * Retorna task_status in [submitted, processing, succeed, failed] + videos[].url quando pronto.
     */
    public function getVideoTask(string $taskId, string $type = 'image2video'): array
    {
        $state = self::cache()->get("kling_browser:{$taskId}");
        if (!$state) {
            return [
                'code' => 404,
                'message' => 'task_not_found_or_expired',
                'data' => ['task_id' => $taskId, 'task_status' => 'failed'],
            ];
        }
        $data = [
            'task_id'      => $taskId,
            'task_status'  => $state['task_status'] ?? 'processing',
            'task_status_msg' => $state['task_status_msg'] ?? '',
            'created_at'   => isset($state['created_at']) ? strtotime($state['created_at']) * 1000 : now()->timestamp * 1000,
            'updated_at'   => isset($state['updated_at']) ? strtotime($state['updated_at']) * 1000 : now()->timestamp * 1000,
            'task_info'    => ['external_task_id' => $state['payload']['external_task_id'] ?? null],
        ];
        if (($state['task_status'] ?? '') === 'succeed' && !empty($state['output_url'])) {
            $data['task_result'] = [
                'videos' => [[
                    'id'       => $taskId,
                    'url'      => $state['output_url'],
                    'duration' => (string) ($state['payload']['duration'] ?? 5),
                ]],
            ];
        }
        return ['code' => 0, 'message' => 'ok', 'data' => $data];
    }

    /**
     * Provider name pra logging/ai_generations.provider.
     */
    public function providerName(): string
    {
        return 'kling_browser';
    }

    /**
     * Health check da sessão (usado pelo AdminKlingBrowserController).
     */
    public function health(): array
    {
        $sessionPath = env('KLING_BROWSER_SESSION_PATH', '/home/api.seller.global/storage/kling-browser/session.json');
        $sessionExists = is_file($sessionPath);
        $sessionAgeS = $sessionExists ? (time() - filemtime($sessionPath)) : null;
        $lastRunPath = env('KLING_BROWSER_RATE_LOCK', '/home/api.seller.global/storage/kling-browser/last_run.txt');
        $lastRunAgeS = is_file($lastRunPath) ? (time() - filemtime($lastRunPath)) : null;
        $videosDir = env('KLING_BROWSER_VIDEOS_DIR', '/home/api.seller.global/storage/kling-browser/videos');
        $videosCount = is_dir($videosDir) ? count(glob("{$videosDir}/*.mp4")) : 0;
        return [
            'enabled'        => $this->isConfigured(),
            'session_exists' => $sessionExists,
            'session_age_s'  => $sessionAgeS,
            'last_run_age_s' => $lastRunAgeS,
            'videos_stored'  => $videosCount,
            'account_email'  => env('KLING_BROWSER_ACCOUNT_EMAIL'),
            'plan'           => env('KLING_BROWSER_PLAN', 'Pro'),
        ];
    }

    /**
     * AUDIT#5 SEL-381 — detecta se request é ADMIN (fila priority) por 3 caminhos:
     *   1. `_priority=admin|high` explícito no payload
     *   2. `Auth::user()->role in [admin, super_admin]` no request atual
     *   3. `external_task_id` começa com `admin_` (caller pode marcar)
     */
    protected function isPriorityRequest(array $payload): bool
    {
        $p = strtolower((string) ($payload['_priority'] ?? ''));
        if ($p === 'admin' || $p === 'high') return true;
        $ext = (string) ($payload['external_task_id'] ?? '');
        if (str_starts_with($ext, 'admin_')) return true;
        try {
            $u = \Illuminate\Support\Facades\Auth::user();
            if ($u && in_array($u->role ?? '', ['admin', 'super_admin'])) return true;
        } catch (\Throwable $e) {}
        return false;
    }

    /**
     * TOK-22 — decide EM QUAL FILA o job de geração entra. Só roteamento: não
     * toca em prompt, modelo, duração nem em nada da geração em si.
     *
     * Três níveis, na ordem que o supervisor sellerapp-kling-browser lê
     * (`--queue=kling-browser-priority,kling-browser,external-video-low`):
     *
     *   kling-browser-priority  admin do seller.global (fura a fila)
     *   kling-browser           cliente pagante do seller.global
     *   external-video-low      pedido de produto externo (Tokfy)
     *
     * O Laravel só puxa da fila seguinte quando a anterior está VAZIA, então o
     * Tokfy nunca entra na frente de um pedido do seller.global que esteja
     * esperando — que é exatamente a garantia que o Ruan pediu.
     *
     * Comportamento de quem já usava isto fica IDÊNTICO: nenhum chamador atual
     * manda `_external_source`, então todos caem nos dois primeiros ramos como
     * antes.
     */
    protected function resolveQueue(array $payload): string
    {
        // Origem externa é checada PRIMEIRO, de propósito: assim um pedido do
        // Tokfy não tem como acabar na fila de prioridade por acidente (ex: se
        // um dia alguém deixar `_priority` vazar pro payload). Externo é sempre
        // o último da fila, sem exceção.
        if (($payload['_external_source'] ?? null) === 'tokfy') {
            return 'external-video-low';
        }

        // SEL-CONVITE: render do trial do /convite vai pra fila MAIS BAIXA (drenada
        // depois de kling-browser-priority e kling-browser). Protege o pagante: um
        // teste nunca fura a fila de quem paga.
        if (! empty($payload['_trial'])) {
            return 'external-video-low';
        }

        if ($this->isPriorityRequest($payload)) {
            return 'kling-browser-priority';
        }

        return 'kling-browser';
    }

    // AUDIT#4 SEL-381 — Fallbacks silenciosos (antes: throw 500 quebrava o Studio).
    // Ao invés de quebrar, retorna resposta "failed" imediata — o caller vê task_status=failed
    // com msg clara e pode oferecer alternativa ao cliente (não crasha o app).
    protected function unsupportedResponse(string $method, ?string $ext = null): array
    {
        $taskId = 'kbrowser_unsupported_' . Str::uuid()->toString();
        return [
            'code' => 0, 'message' => 'ok', 'request_id' => $taskId,
            'data' => [
                'task_id' => $taskId,
                'task_status' => 'failed',
                // SEL-459: dizia "aguarde reativacao API". Duas coisas erradas ali:
                // a API esta fora do plano (Ruan, 31/07: "nao estamos usando API,
                // esquece isso"), e o limite nao e do modo navegador — e do nosso
                // worker, que so dirige image-to-video e text-to-video. Varias
                // dessas telas EXISTEM no plano Pro (medido em 31/07).
                'task_status_msg' => "browser_mode_unsupported:{$method} — nosso worker ainda nao dirige a tela desta funcao",
                'task_info' => ['external_task_id' => $ext],
                'created_at' => now()->timestamp * 1000,
                'updated_at' => now()->timestamp * 1000,
            ],
        ];
    }
    /**
     * Responde `false` porque O NOSSO WORKER ainda não dirige a tela de Lip Sync
     * — não porque o Kling não saiba fazer sincronia labial.
     *
     * SEL-459 (31/07), medido na UI do plano Pro com sessão real: a ferramenta
     * EXISTE, em `https://kling.ai/app/ai-human/video/new` ("AI Lip-Sync Video
     * Generator"), e o painel dela é só `Upload video` + `Audio for Lip Sync` +
     * `Generate`. O que falta é `generate_video.js` saber navegar até lá e subir
     * DOIS arquivos; hoje ele sobe um só, e só conhece image-to-video e
     * text-to-video.
     *
     * A redação anterior dizia "o modo navegador NÃO faz sincronia labial", o
     * que levava a concluir que a única saída era reativar a API. Não é: o
     * caminho é o worker. Comentário errado é o que faz o próximo agente
     * concluir besteira — foi o que aconteceu nesta tarefa.
     *
     * O RETORNO CONTINUA `false`, e deve continuar enquanto o worker não dirigir
     * a tela: quem pergunta a capacidade antes consegue recusar de forma legível,
     * em vez de descobrir pelo erro depois. `lipSync()` aqui devolve
     * `code=0, message='ok'` COM um task_id e o `task_status: failed` escondido
     * lá dentro — quem só olha o task_id guarda um id `kbrowser_unsupported_*` e
     * vai fazer poll de uma tarefa que nunca existiu.
     */
    public function suportaLipSync(): bool { return false; }

    public function virtualTryOn(array $payload): array { return $this->unsupportedResponse('virtualTryOn', $payload['external_task_id'] ?? null); }
    public function lipSync(array $payload): array { return $this->unsupportedResponse('lipSync', $payload['external_task_id'] ?? null); }

    /**
     * SEL-468 — CENA COM REFERÊNCIAS, de verdade.
     *
     * Era `unsupportedResponse('multiImageToVideo')`: 6 tentativas, 0 sucessos no
     * banco. Não era limitação do Kling — era o worker, que só dirigia duas telas
     * e subia UMA imagem. A tela existe no plano Pro e foi medida em 31/07:
     * `kling.ai/app/omni/new?template=referImage&model=video`, aceita até 7
     * referências, 9:16 tem botão próprio e a duração vai a 15s.
     *
     * Aceita os três formatos que os chamadores já usam:
     *   image_list: [['image' => url], ...]   (formato da API oficial, é o que o
     *                                          StudioGenerationJob monta hoje)
     *   images:     [url, url, ...]
     *   image:      url                        (+ image_refs)
     */
    public function multiImageToVideo(array $payload): array
    {
        $imagens = self::normalizaImagens($payload);

        // Recusa na ENTRADA, com motivo legível — em vez de ocupar um worker de
        // navegador por minutos pra morrer lá na frente (foi o padrão que o
        // SEL-402 matou nos outros métodos).
        if (count($imagens) < 2) {
            throw new \InvalidArgumentException(
                'Cena com referencias precisa de pelo menos 2 fotos. Envie a foto do '
                . 'produto e mais uma referencia (pessoa, ambiente ou outro angulo).'
            );
        }
        if (trim((string) ($payload['prompt'] ?? '')) === '') {
            throw new \InvalidArgumentException(
                'Nao da pra gerar video sem roteiro. Escreva o que a cena deve mostrar.'
            );
        }

        // A tela aceita no máximo 7. Cortar aqui (e dizer no log) é melhor do que
        // deixar o worker descobrir e escolher sozinho quais entram.
        if (count($imagens) > 7) {
            Log::info('[SEL-468] mais de 7 referencias, usando as 7 primeiras', [
                'recebidas' => count($imagens),
            ]);
            $imagens = array_slice($imagens, 0, 7);
        }

        $payload['images'] = $imagens;
        $payload['prompt'] = self::garanteCameraNoProduto((string) $payload['prompt']);
        $payload['prompt'] = self::garanteIdioma($payload['prompt'], $payload['lang'] ?? null);

        // Regra dura do produto: 9:16 e nunca menos de 10s.
        $payload['aspect_ratio'] = '9:16';
        $payload['duration']     = max(10, min(15, (int) ($payload['duration'] ?? 12)));

        $taskId = 'kbrowser_' . Str::uuid()->toString();
        self::cache()->put("kling_browser:{$taskId}", [
            'task_id'     => $taskId,
            'task_status' => 'submitted',
            'created_at'  => now()->toIso8601String(),
            'kind'        => 'omni_refs',
            'payload'     => $payload,
        ], 1800);

        Log::info('[SEL-468] cena_com_referencias enfileirada', [
            'task_id'     => $taskId,
            'referencias' => count($imagens),
            'duracao'     => $payload['duration'],
        ]);

        $queue = $this->resolveQueue($payload); // TOK-22
        KlingBrowserGenerateJob::dispatch($taskId, 'omni_refs', $payload)->onQueue($queue);

        return [
            'code' => 0,
            'message' => 'ok',
            'request_id' => $taskId,
            'data' => [
                'task_id'     => $taskId,
                'task_status' => 'submitted',
                'task_info'   => ['external_task_id' => $payload['external_task_id'] ?? null],
                'created_at'  => now()->timestamp * 1000,
                'updated_at'  => now()->timestamp * 1000,
            ],
        ];
    }

    /**
     * SEL-485 — as duas VARIANTES do Omni.
     *
     * `Video Reference` (referVideo) e `Instruction Transformation` (baseVideo)
     * são a mesma tela do Omni que a `multiImageToVideo` já usa, com outro
     * template — e com uma diferença que importa: o campo aceita VÍDEO
     * (`.mp4,.mov`) junto das fotos.
     *
     * Elas são mais seguras que o Motion Control porque TÊM linha de proporção e
     * de duração: 9:16 e >=10s são fixados e conferidos na tela, não herdados do
     * arquivo.
     *
     * >>> Um porém medido, que vale pro `omni_instrucao`: <<<
     * a tela às vezes REESCREVE a duração depois da gente confirmar, pra
     * acompanhar o vídeo-base (medido: pedimos 10s, ficou 12s — e o preço subiu
     * de 90 pra 108 pontos, o que confirma que mudou de verdade). O worker relê
     * a barra encostado no botão e aborta se cair abaixo do mínimo do produto.
     */
    public function videoReference(array $payload): array
    {
        return $this->omniComVideo('omni_video_ref', $payload);
    }

    public function instructionTransformation(array $payload): array
    {
        return $this->omniComVideo('omni_instrucao', $payload);
    }

    private function omniComVideo(string $ferramenta, array $payload): array
    {
        $arquivos = self::normalizaImagens($payload);
        foreach ((array) ($payload['videos'] ?? []) as $v) {
            if (is_string($v) && trim($v) !== '') $arquivos[] = trim($v);
        }
        if (!empty($payload['video']) && is_string($payload['video'])) {
            $arquivos[] = trim($payload['video']);
        }
        $arquivos = array_values(array_unique(array_filter($arquivos)));

        if (count($arquivos) < 1) {
            throw new \InvalidArgumentException(
                'Envie pelo menos um arquivo de referencia (video ou foto) pra essa funcao.'
            );
        }
        if (trim((string) ($payload['prompt'] ?? '')) === '') {
            throw new \InvalidArgumentException(
                'Nao da pra gerar video sem roteiro. Escreva o que a cena deve mostrar.'
            );
        }

        // Mesmos formatos que o input da tela aceita (medido).
        foreach ($arquivos as $u) {
            $ext = strtolower((string) pathinfo(parse_url($u, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'mp4', 'mov'], true)) {
                throw new \InvalidArgumentException(
                    'Essa funcao aceita JPG, PNG, MP4 ou MOV. Um dos arquivos e "' . ($ext ?: 'sem extensao') . '".'
                );
            }
        }

        if (count($arquivos) > 7) {
            Log::info('[SEL-485] mais de 7 referencias, usando as 7 primeiras', [
                'ferramenta' => $ferramenta,
                'recebidas'  => count($arquivos),
            ]);
            $arquivos = array_slice($arquivos, 0, 7);
        }

        $payload['files']  = $arquivos;
        $payload['prompt'] = self::garanteIdioma((string) $payload['prompt'], $payload['lang'] ?? null);
        // Regra dura do produto — e aqui a tela DEIXA cumprir.
        $payload['aspect_ratio'] = '9:16';
        $payload['duration']     = max(10, min(15, (int) ($payload['duration'] ?? 12)));

        $taskId = 'kbrowser_' . Str::uuid()->toString();
        self::cache()->put("kling_browser:{$taskId}", [
            'task_id'     => $taskId,
            'task_status' => 'submitted',
            'created_at'  => now()->toIso8601String(),
            'kind'        => $ferramenta,
            'payload'     => $payload,
        ], 1800);

        Log::info('[SEL-485] omni com video enfileirado', [
            'task_id'    => $taskId,
            'ferramenta' => $ferramenta,
            'arquivos'   => count($arquivos),
            'duracao'    => $payload['duration'],
        ]);

        $queue = $this->resolveQueue($payload); // TOK-22
        KlingBrowserGenerateJob::dispatch($taskId, $ferramenta, $payload)->onQueue($queue);

        return [
            'code' => 0,
            'message' => 'ok',
            'request_id' => $taskId,
            'data' => [
                'task_id'     => $taskId,
                'task_status' => 'submitted',
                'task_info'   => ['external_task_id' => $payload['external_task_id'] ?? null],
                'created_at'  => now()->timestamp * 1000,
                'updated_at'  => now()->timestamp * 1000,
            ],
        ];
    }

    /**
     * SEL-485 — COPIAR MOVIMENTO (tela "Motion Control" do Kling).
     *
     * É o que o Ruan descreveu: "você manda uma imagem e manda o vídeo e ele
     * troca o personagem". Sobe DOIS arquivos em campos diferentes — o vídeo com
     * o movimento e a foto da pessoa — e devolve a pessoa da foto fazendo aquele
     * movimento.
     *
     * >>> POR QUE A VALIDAÇÃO DO VÍDEO É TÃO DURA AQUI <<<
     * Esta tela NÃO tem controle de proporção nem de duração (medido em 31/07,
     * antes e depois dos uploads: o painel só oferece Mode 720p/1080p e Number
     * of Outputs 1-4). O que sai herda o formato e a duração do vídeo que entra.
     * Como a regra do produto é 9:16 e no mínimo 10s, a única garantia possível
     * é recusar o material de entrada — e é melhor recusar aqui, na hora que a
     * pessoa envia, do que ocupar o navegador por minutos pra entregar um vídeo
     * fora do padrão.
     *
     * A conferência fina (proporção exata e duração via ffprobe) roda no worker,
     * com o arquivo já em disco. Aqui fica a recusa barata e legível.
     */
    public function motionControl(array $payload): array
    {
        $video = trim((string) ($payload['video'] ?? $payload['video_url'] ?? ''));
        $foto  = trim((string) ($payload['image'] ?? ''));

        if ($video === '') {
            throw new \InvalidArgumentException(
                'Falta o video com o movimento. Envie um video vertical (9:16) de pelo menos 10 segundos '
                . 'mostrando o movimento que voce quer copiar.'
            );
        }
        if ($foto === '') {
            throw new \InvalidArgumentException(
                'Falta a foto da pessoa. Envie uma foto (JPG ou PNG) de quem vai fazer o movimento.'
            );
        }

        $ext = strtolower((string) pathinfo(parse_url($video, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'mov'], true)) {
            throw new \InvalidArgumentException(
                'O video precisa ser MP4 ou MOV. O arquivo enviado e "' . ($ext ?: 'sem extensao') . '".'
            );
        }
        $extFoto = strtolower((string) pathinfo(parse_url($foto, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        if (!in_array($extFoto, ['jpg', 'jpeg', 'png'], true)) {
            throw new \InvalidArgumentException(
                'A foto precisa ser JPG ou PNG. O arquivo enviado e "' . ($extFoto ?: 'sem extensao') . '".'
            );
        }

        $payload['video'] = $video;
        $payload['image'] = $foto;
        // Registrado pra deixar explícito: NÃO adianta mandar aspect_ratio ou
        // duration daqui — a tela não tem esses controles. Quem manda é o vídeo.
        unset($payload['aspect_ratio'], $payload['duration']);

        $taskId = 'kbrowser_' . Str::uuid()->toString();
        self::cache()->put("kling_browser:{$taskId}", [
            'task_id'     => $taskId,
            'task_status' => 'submitted',
            'created_at'  => now()->toIso8601String(),
            'kind'        => 'motion_control',
            'payload'     => $payload,
        ], 1800);

        Log::info('[SEL-485] copiar_movimento enfileirado', [
            'task_id' => $taskId,
            'video'   => mb_substr($video, 0, 120),
        ]);

        $queue = $this->resolveQueue($payload); // TOK-22
        KlingBrowserGenerateJob::dispatch($taskId, 'motion_control', $payload)->onQueue($queue);

        return [
            'code' => 0,
            'message' => 'ok',
            'request_id' => $taskId,
            'data' => [
                'task_id'     => $taskId,
                'task_status' => 'submitted',
                'task_info'   => ['external_task_id' => $payload['external_task_id'] ?? null],
                'created_at'  => now()->timestamp * 1000,
                'updated_at'  => now()->timestamp * 1000,
            ],
        ];
    }

    /**
     * SEL-485 — o worker dirige a tela do Motion Control. Serve pro chamador
     * perguntar ANTES de oferecer o botão, em vez de descobrir pelo erro.
     */
    public function suportaMotionControl(): bool { return true; }

    /** SEL-468 — junta as fotos de referência, venham no formato que vierem. */
    public static function normalizaImagens(array $payload): array
    {
        $urls = [];

        foreach ((array) ($payload['image_list'] ?? []) as $item) {
            $u = is_array($item) ? ($item['image'] ?? $item['url'] ?? null) : $item;
            if (is_string($u) && trim($u) !== '') $urls[] = trim($u);
        }
        foreach ((array) ($payload['images'] ?? []) as $u) {
            if (is_string($u) && trim($u) !== '') $urls[] = trim($u);
        }
        if (!empty($payload['image']) && is_string($payload['image'])) {
            $urls[] = trim($payload['image']);
        }
        foreach ((array) ($payload['image_refs'] ?? []) as $u) {
            if (is_string($u) && trim($u) !== '') $urls[] = trim($u);
        }

        return array_values(array_unique($urls));
    }

    /**
     * SEL-468 — direção de câmera no TEXTO do prompt.
     *
     * No modo navegador o `camera_control` do payload é descartado (está
     * documentado no topo do StudioOptionsController): quem dirige a câmera é o
     * texto que o worker digita. Sem isso a cena fica parada e o produto vira
     * cenário — Ruan é explícito: o produto é a estrela, não o avatar.
     *
     * Só ACRESCENTA direção; não reescreve nem inventa roteiro (o roteiro do
     * cliente continua intacto, como o SEL-419 deixou claro que tem que ser).
     */
    public static function garanteCameraNoProduto(string $prompt): string
    {
        $p = trim($prompt);
        if ($p === '' || mb_stripos($p, 'CAMERA:') !== false) {
            return $p;
        }

        return $p . "\n\n"
            . 'CAMERA: movimento continuo e expressivo o tempo todo — nada de plano parado. '
            . 'Comeca em plano medio e faz uma aproximacao progressiva ate close-up no PRODUTO, '
            . 'com push-in lento, leve orbita ao redor do produto e handheld sutil. '
            . 'FOCO: o PRODUTO e a estrela do video — ele ocupa o centro do quadro e recebe o '
            . 'close final. Pessoa e cenario existem so pra dar contexto ao produto, nunca roubam '
            . 'o enquadramento. Mantem o produto identico ao das fotos de referencia (mesma cor, '
            . 'mesmo formato, mesmo logo, mesmos detalhes).';
    }
    public function faceSwap(array $payload): array { return $this->unsupportedResponse('faceSwap', $payload['external_task_id'] ?? null); }
    public function videoExtend(array $payload): array { return $this->unsupportedResponse('videoExtend', $payload['external_task_id'] ?? null); }
    public function getImageTask(string $taskId, string $type = "face-swap"): array { return $this->unsupportedResponse('getImageTask'); }
    public function getVideoExtendTask(string $taskId): array { return $this->unsupportedResponse('getVideoExtendTask'); }
    public function getVideoStatus(string $taskId, string $type = 'image2video'): array
    {
        return $this->getVideoTask($taskId, $type);
    }
    public function accountCosts(?int $startMs = null, ?int $endMs = null): array
    {
        return ['code' => 0, 'message' => 'ok', 'data' => ['balance' => null, 'note' => 'browser mode — see health()']];
    }
}
