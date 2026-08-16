<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\ProductReferenceCollector;
use App\Services\Ai\ProductIsolatorService;
use App\Services\Ai\ClientAvatarResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use App\Services\Ai\KlingCatalogService;

/**
 * SEL-361 Fase A - StudioPrepareController
 * Endpoints: prepare-context, generate-exclusive-avatar, upload-audio, feedback
 */
class StudioPrepareController extends Controller
{
    public function __construct(
        private ProductReferenceCollector $collector,
        private ProductIsolatorService    $isolator,
        private ClientAvatarResolver      $avatarResolver,
    ) {}

    public function prepareContext(Request $request)
    {
        $v = $request->validate([
            "client_product_id"  => "nullable|integer|exists:client_products,id",
            "external_url"       => "nullable|url|max:2000",
            "uploaded_image_url" => "nullable|url|max:2000",
            // P1 SEL-361: query params da URL (product=X&image=Y&images=Y1|Y2&price=Z)
            "product"            => "nullable|string|max:500",
            "product_key"        => "nullable|string|max:200",
            "image"              => "nullable|string|max:2000",
            "images"             => "nullable|string|max:5000",
            "price"              => "nullable|numeric",
            "product_rating"     => "nullable|numeric",
            "product_sales"      => "nullable|integer",
        ]);

        $user   = $request->user();
        $client = $user->client;

        $refs = []; $product = []; $isolatedUrl = null;
        // SEL-379 (Ruan 28/07): so 2 modos na escolha — Video do Zero primeiro, POV segundo
        $suggestedPipelines = ["video_do_zero", "pov_so_mao"];

        // P1 SEL-361: pre-popular a partir de query params da URL (product=X&image=Y&images=Y1|Y2)
        if (!empty($v["product"]) || !empty($v["image"]) || !empty($v["images"])) {
            $productName = $v["product"] ?? "Produto";
            $product = [
                "name"           => $productName,
                "category"       => "geral",
                "price"          => $v["price"] ?? null,
                "product_rating" => $v["product_rating"] ?? null,
                "product_sales"  => $v["product_sales"] ?? null,
                "product_key"    => $v["product_key"] ?? null,
            ];

            // Processar images (pipe-separado) e image (singular)
            $rawImages = [];
            if (!empty($v["images"])) {
                $rawImages = array_filter(
                    explode("|", $v["images"]),
                    fn($u) => !empty($u) && $u !== "__scrape_queued__"
                );
            }
            if (!empty($v["image"]) && $v["image"] !== "__scrape_queued__") {
                array_unshift($rawImages, $v["image"]);
            }
            $rawImages = array_values(array_unique($rawImages));

            // SEL-381 fallback ROBUSTO: se product_key ausente, buscar por:
            //   (1) HASH da URL da imagem original (busca reversa) — pega o produto exato
            //   (2) TÍTULO (palavras) — fallback quando hash não bate
            // Extrai TODAS URLs de imagem do payload recursivamente (image.url_list,
            // sku_info[].image.url_list, transparent_image, product_marketing_info, etc).
            if (empty($v["product_key"]) && count($rawImages) <= 1) {
                try {
                    // Helper recursivo: extrai URLs de imagem de qualquer estrutura JSON
                    $extractImages = function ($node, array &$out) use (&$extractImages) {
                        if (is_array($node)) {
                            foreach ($node as $k => $val) {
                                if (is_string($val) && preg_match('#^https?://[^\s]+\.(webp|jpg|jpeg|png)(\?|~|$)#i', $val)) {
                                    // Aceita SÓ imagens de produto: nosso CDN OR p*-oec-* (TikTok product storage).
                                    // Rejeita ícones/avatars: tiktokcdn.com, tiktokcdn-eu, free_shipping, icon, badge, avt-
                                    if (str_contains($val, 'api.seller.global/storage/') || preg_match('#p\d+-oec-#i', $val)) {
                                        if (!preg_match('/(free_shipping|icon|badge|avatar|avt-|tplv-.*?-emoji)/i', $val)) {
                                            $out[] = preg_replace('/\?.*$/', '', $val);
                                        }
                                    }
                                } else if (is_array($val)) {
                                    $extractImages($val, $out);
                                }
                            }
                        }
                    };
                    $foundRows = collect();

                    // (1) BUSCA REVERSA por HASH da imagem original (matcha produto exato)
                    if (!empty($v["image"])) {
                        // Extrai o hash do path da URL (última parte antes de .webp/etc)
                        if (preg_match('#/([a-f0-9]{16,})\.(webp|jpg|jpeg|png)#i', $v["image"], $mh)) {
                            $hash = $mh[1];
                            foreach ([['tt_shop_raw', 'product'], ['kalodata_raw', 'products'], ['kalodata_raw', 'shops']] as $src) {
                                $rows = DB::table($src[0])->where('type', $src[1])->where('payload', 'like', "%$hash%")->limit(3)->get(['payload']);
                                foreach ($rows as $r) $foundRows->push($r->payload);
                            }
                        }
                    }

                    // (2) BUSCA POR TÍTULO — REMOVIDA em SEL-439.
                    //
                    // O que existia aqui: pegava as 3 primeiras palavras do
                    // título e aceitava qualquer produto do banco que casasse
                    // 2 delas. Na prática "Jogo de Panelas 10 Peças" casava com
                    // "Jogo De Panela Monaco Diamante" — produto DIFERENTE — e
                    // as fotos desse outro produto iam pro Estúdio gerar o
                    // vídeo. Ruan, 30/07: "quero a imagem real dos produtos
                    // porra, se não vai gerar vídeos de outro produto, tu tá
                    // doido, arruma isso, não inventa".
                    //
                    // Casar por palavra do título NÃO prova que a foto é
                    // daquele produto. Só a busca (1), por hash da imagem, prova
                    // — porque o hash é o mesmo arquivo do mesmo anúncio.
                    // Quando (1) não acha, o certo é devolver vazio: o front já
                    // trata isso mostrando "não achamos foto real desse produto"
                    // e bloqueando a geração. Vídeo nenhum é melhor que vídeo do
                    // produto errado.
                    //
                    // Pra popular foto de produto sem foto, o caminho certo é o
                    // comando tiktok:enrich-images-from-videos, que liga produto
                    // e imagem pelo ID via mapeamento da própria Kalodata.

                    // Extrai imagens de todos os payloads encontrados
                    $extracted = [];
                    foreach ($foundRows as $payload) {
                        $p = json_decode($payload, true);
                        $extractImages($p, $extracted);
                    }
                    // Dedup por HASH do arquivo (p16 e p19 são CDN edges diferentes do MESMO arquivo)
                    $seen = [];
                    $unique = [];
                    foreach ($extracted as $u) {
                        // Chave = hash antes de "~tplv" ou basename sem query
                        $k = '';
                        if (preg_match('#/([a-f0-9]{16,})(?:~|\.)#i', $u, $mk)) $k = $mk[1];
                        else $k = basename(parse_url($u, PHP_URL_PATH) ?: $u);
                        if (isset($seen[$k])) continue;
                        $seen[$k] = true;
                        $unique[] = $u;
                    }
                    // Prioriza URLs do nosso CDN (api.seller.global/storage/tt-media/*)
                    usort($unique, function ($a, $b) {
                        $sa = str_contains($a, 'api.seller.global/storage/');
                        $sb = str_contains($b, 'api.seller.global/storage/');
                        return $sb <=> $sa;
                    });
                    foreach ($unique as $u) {
                        if (!in_array($u, $rawImages, true) && count($rawImages) < 8) $rawImages[] = $u;
                        if (count($rawImages) >= 8) break;
                    }
                } catch (\Throwable $e) { /* silent */ }
            }

            // SEL-379 (Ruan 28/07): cacador de imagens — complementa com todas as fotos
            // ja localizadas desse produto (tt-media, URLs estaveis) via product_key
            if (!empty($v["product_key"])) {
                try {
                    $hunted = DB::table("tiktok_product_images")
                        ->where("product_key", $v["product_key"])
                        ->where(function ($q) {
                            $q->whereNotNull("url_local")
                              ->orWhere(function ($q2) {
                                  $q2->whereNotNull("url_original")
                                     ->where("url_original", "!=", "__scrape_queued__");
                              });
                        })
                        ->orderByDesc("quality_score")
                        ->limit(12)
                        ->get();
                    foreach ($hunted as $h) {
                        $u = $h->url_local ?: $h->url_original;
                        if ($u && !in_array($u, $rawImages, true)) {
                            $rawImages[] = $u;
                        }
                    }
                } catch (Throwable $e) {
                    // tabela pode nao existir em outros backends do repo compartilhado
                }
            }

            foreach ($rawImages as $i => $url) {
                $refs[] = ["url" => $url, "kind" => "url_param", "priority" => $i + 1];
            }
        }

        if (!empty($v["client_product_id"])) {
            $refData = $this->collector->collect((int) $v["client_product_id"]);
            $refs    = $refData["images"] ?? [];
            $product = $refData["product"] ?? [];

            $firstImage = $refs[0]["url"] ?? null;
            if ($firstImage && $client) {
                $isolatedUrl = $this->isolator->isolate((int) $v["client_product_id"], $firstImage);
            }
        } elseif (!empty($v["uploaded_image_url"])) {
            $refs    = [["url" => $v["uploaded_image_url"], "kind" => "client_upload", "priority" => 1]];
            $product = ["name" => "Produto enviado", "category" => "geral"];
        } elseif (!empty($v["external_url"])) {
            $refs    = [["url" => $v["external_url"], "kind" => "external", "priority" => 1]];
            $product = ["name" => "Produto via link", "category" => "geral"];
        }

        $avatarStatus = "missing";
        if ($client) {
            $r = $this->avatarResolver->resolve($client->id, "avatar_apresentando");
            $avatarStatus = $r["status"];
        }

        $contextId   = "ctx_" . Str::random(20);
        $contextData = [
            "context_id"           => $contextId,
            "product"              => $product,
            "images_collected"     => $refs,
            "isolated_product_url" => $isolatedUrl,
            "client_avatar_status" => $avatarStatus,
            "suggested_pipelines"  => $suggestedPipelines,
            "created_at"           => now()->toISOString(),
        ];

        Cache::put("studio_ctx_{$contextId}", $contextData, now()->addMinutes(30));
        return response()->json($contextData);
    }

    public function getContext(Request $request, string $contextId)
    {
        $ctx = Cache::get("studio_ctx_{$contextId}");
        if (!$ctx) return response()->json(["error" => "context_expired"], 404);
        return response()->json($ctx);
    }

    public function generateExclusiveAvatar(Request $request)
    {
        // SEL-avatar (10/08): cria avatar do ZERO (arquetipo OU descricao livre) via
        // motor Google Flow / Nano Banana (R$0, incluso Ultra). Async: dispara job e
        // devolve task_id; front faz polling em GET /ai/image/task/{id}.
        $v = $request->validate([
            "mode"        => "nullable|in:archetype,describe",
            "archetype"   => "nullable|string|max:40",
            "gender"      => "nullable|in:feminino,masculino,neutro",
            "description" => "nullable|string|max:400",
            // compat legado (ignorados no fluxo novo, mas nao quebram chamadas antigas)
            "age_range"      => "nullable|string|max:10",
            "ethnicity_hint" => "nullable|string|max:32",
            "style"          => "nullable|string|max:32",
        ]);
        $mode = $v["mode"] ?? "archetype";

        $client = $request->user()->client;
        if (!$client) return response()->json(["error" => "client_required"], 403);

        $isAdmin = in_array((string) ($request->user()->role ?? ""), ["admin", "super_admin"], true);

        // Gate: TODO plano PAGO pode criar avatar (assinatura ativa/trial).
        $paid = \App\Models\Subscription::where("client_id", $client->id)
            ->whereIn("status", ["active", "trialing"])
            ->exists();
        if (!$paid && !$isAdmin) {
            return response()->json([
                "error"   => "plan_required",
                "message" => "Criar apresentador e um recurso dos planos pagos. Faca upgrade para liberar.",
            ], 403);
        }

        // Limite: 3 avatares gerados por cliente (evita abuso/gasto).
        $jaTem = \Illuminate\Support\Facades\DB::table("client_video_avatars")
            ->where("client_id", $client->id)
            ->where("source", "generated_exclusive")
            ->where("is_active", true)
            ->count();
        if ($jaTem >= 3 && !$isAdmin) {
            return response()->json([
                "error"   => "avatar_limit",
                "message" => "Voce ja tem 3 apresentadores criados. Apague um para criar outro.",
            ], 422);
        }

        if ($mode === "describe" && !trim((string) ($v["description"] ?? ""))) {
            return response()->json(["error" => "description_required", "message" => "Descreva o apresentador que voce quer."], 422);
        }
        if ($mode === "archetype" && !($v["archetype"] ?? null)) {
            return response()->json(["error" => "archetype_required", "message" => "Escolha um estilo de apresentador."], 422);
        }

        $label = "Apresentador criado em " . now()->format("d/m/Y");

        // handle de polling: linha em ai_generations (service=image) — reusa GET /ai/image/task/{id}
        $gen = \App\Models\AiGeneration::create([
            "tenant_id"       => $request->user()->tenant_id ?? null,
            "user_id"         => $request->user()->id,
            "service"         => "image",
            "provider"        => "mac-flow-image",
            "provider_model"  => "nano-banana",
            "status"          => "queued",
            "credits_debited" => 0,
            "cost_usd"        => 0,
        ]);

        \App\Jobs\GenerateAvatarJob::dispatch(
            $gen->id,
            $client->id,
            $mode,
            $v["archetype"] ?? null,
            $v["gender"] ?? null,
            $v["description"] ?? null,
            $label,
        )->onQueue("default");

        return response()->json([
            "task_id" => $gen->id,
            "status"  => "queued",
            "message" => "Estamos criando seu apresentador...",
        ], 202);
    }

    public function uploadAudio(Request $request)
    {
        $request->validate([
            "file" => "required|file|max:20480|mimes:mp3,wav,m4a,ogg,webm",
        ]);

        $file = $request->file("file");
        $path = $file->store("studio/audio/" . now()->format("Ymd"), "public");
        $url  = asset("storage/" . $path);
        $fullPath = storage_path("app/public/" . $path);

        // SEL-AUDIO-OUVIDO (14/08, Ruan: "opcao de subir o proprio audio e clonar
        // nao esta funcionando").
        //
        // CAUSA MEDIDA: a transcricao dependia SO da OpenAI, e a chave dela esta
        // VAZIA neste backend (`openai_not_configured`) -- assim como ElevenLabs e
        // Gemini. Pior: o catch era vazio, entao o erro sumia e o cliente recebia
        // 200 com "audio recebido" e NADA acontecia na tela. Falha silenciosa.
        //
        // Agora, quando nao ha chave, o audio vai pelo MESMO caminho que o resto da
        // fabrica usa: o navegador. O "ouvido" sobe o arquivo no Gemini com a sessao
        // Google que ja existe e devolve a transcricao literal -- sem chave, sem
        // custo de API.
        $text = null;
        try {
            $openai = app(\App\Services\Ai\OpenAiService::class);
            $tr = $openai->transcribe($fullPath);
            $text = $tr["text"] ?? null;
        } catch (Throwable $e) {
            Log::warning("[SEL-AUDIO] transcricao por API falhou, tentando pelo navegador", [
                "erro" => mb_substr($e->getMessage(), 0, 160),
            ]);
        }

        // SEL-OUVIDO-PLUGADO (16/08) — o "ouvido" passa a ser LOCAL.
        //
        // Aqui rodava `xvfb-run node ouvido_gemini.js` no SERVIDOR. Esse worker foi
        // escrito pro PC Windows: exige `pool.json` (Chrome quente com sessao Google) e
        // aponta caminhos C:\\... Rodando aqui, ele so sabe responder
        // {"ok":false,"stage":"sem_motor"} — ou seja, a transcricao NUNCA funcionou por
        // este caminho. 63 audios de clientes passaram por ele desde 30/07 e todos
        // receberam "nao consegui ler o que voce falou".
        //
        // Agora usa /opt/ouvido: faster-whisper rodando dentro do nosso servidor, modelo
        // ja em disco, sem chave, sem conta e sem custo por uso. HF_HUB_OFFLINE=1 impede
        // qualquer ida na internet (o modelo ja esta baixado) e evita o erro de permissao
        // no cache quando roda como o usuario do site.
        if (! $text) {
            try {
                $ouvir = "/opt/ouvido/ouvir.py";
                if (is_file($ouvir) && is_file($fullPath)) {
                    $proc = new \Symfony\Component\Process\Process(
                        ["/opt/ouvido/bin/python", $ouvir, $fullPath],
                        "/tmp",
                        [
                            "HOME"            => "/tmp",
                            "HF_HUB_OFFLINE"  => "1",
                            "OMP_NUM_THREADS" => "2",
                            "PATH"            => "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
                        ]
                    );
                    $proc->setTimeout(300);
                    $proc->run();

                    $saida = trim($proc->getOutput());
                    $iniJ  = strpos($saida, "{");
                    $j     = $iniJ !== false ? json_decode(substr($saida, $iniJ), true) : null;

                    if (is_array($j) && ! empty($j["texto"]) && trim($j["texto"]) !== "") {
                        $text = trim($j["texto"]);
                        Log::error("[SEL-OUVIDO] audio do cliente transcrito no servidor", [
                            "chars"   => mb_strlen($text),
                            "idioma"  => $j["idioma"] ?? null,
                            "duracao" => $j["duracao"] ?? null,
                        ]);
                    } else {
                        // Log::error (nao warning) de proposito: o .env deste backend esta
                        // com LOG_LEVEL=error, entao warning nao aparece em lugar nenhum —
                        // foi assim que esta falha ficou invisivel por semanas.
                        Log::error("[SEL-OUVIDO] nao devolveu texto", [
                            "saida" => mb_substr($saida, 0, 200),
                            "err"   => mb_substr($proc->getErrorOutput(), 0, 200),
                        ]);
                    }
                }
            } catch (Throwable $e) {
                Log::error("[SEL-OUVIDO] falhou", ["erro" => mb_substr($e->getMessage(), 0, 200)]);
            }
        }

        return response()->json([
            "url"           => $url,
            "transcription" => $text,
            "message"       => $text
                ? "Peguei o audio! Texto detectado. Uso esse roteiro no video?"
                : "Recebi seu audio e ele esta salvo, mas a nossa transcricao nao conseguiu ler agora — e coisa nossa, nao do seu audio. Cola o roteiro no campo de texto que eu sigo daqui.",
        ]);
    }

    public function storeFeedback(Request $request)
    {
        $v = $request->validate([
            "generation_id"   => "required|integer",
            "rating"          => "required|in:great,ok,bad",
            "free_text"       => "nullable|string|max:500",
            "pipeline"        => "nullable|string|max:64",
            "category"        => "nullable|string|max:128",
            "vibe"            => "nullable|string|max:64",
            "hook_type"       => "nullable|string|max:32",
            // SEL-490 QA: o cliente confirma se o PRODUTO do video esta certo.
            // true=certo, false=produto errado (aciona regeracao/alerta). Ground truth
            // do QA de fidelidade (complementa o hard-gate e a checagem visual).
            "produto_correto" => "nullable|boolean",
        ]);

        $user   = $request->user();
        $client = $user->client;

        try {
            DB::table("video_feedback")->insert([
                "generation_id" => $v["generation_id"],
                "client_id"     => $client->id ?? 0,
                "user_id"       => $user->id,
                "rating"        => $v["rating"],
                "free_text"     => $v["free_text"] ?? null,
                "pipeline"      => $v["pipeline"] ?? null,
                "category"      => $v["category"] ?? null,
                "vibe"          => $v["vibe"] ?? null,
                "hook_type"     => $v["hook_type"] ?? null,
                "produto_correto" => array_key_exists('produto_correto', $v) ? ($v['produto_correto'] === null ? null : (int) $v['produto_correto']) : null,
                "created_at"    => now(),
                "updated_at"    => now(),
            ]);

            // SEL-490 QA: "produto errado" reportado pelo cliente vira ALERTA (o
            // hard-gate deve tornar isso raro; se acontecer, e sinal de regressao).
            if (array_key_exists('produto_correto', $v) && $v['produto_correto'] === false) {
                \Illuminate\Support\Facades\Log::warning('[SEL-490][QA] cliente reportou PRODUTO ERRADO no video', [
                    'generation_id' => $v['generation_id'],
                    'user_id'       => $user->id,
                    'free_text'     => $v['free_text'] ?? null,
                ]);
            }

            $msgs = [
                "great" => "Otimo! Vou usar isso como referencia.",
                "ok"    => "Entendido! Aprendendo com isso.",
                "bad"   => "Poxa! Guardei o feedback pra melhorar.",
            ];

            return response()->json(["recorded" => true, "message" => $msgs[$v["rating"]] ?? "Feedback ok!"]);
        } catch (Throwable $e) {
            return response()->json(["error" => "Nao foi possivel registrar."], 500);
        }
    }

    /** SEL-361: retorna catalogo completo de funcoes Kling com custo estimado */
    public function klingCatalog(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'catalog'  => KlingCatalogService::catalog(),
            'version'  => '1.0.0',
            'generated_at' => now()->toISOString(),
        ]);
    }

    /**
     * SEL-417 — GET /api/v1/studio/languages
     *
     * Lista de idiomas que a CONTA LOGADA pode escolher pro áudio do vídeo.
     * É a fonte da verdade pro frontend desenhar o seletor: os que vêm com
     * `selecionavel=false` aparecem na tela DESABILITADOS com o `badge`, porque
     * o Ruan quer que o cliente veja que português vem aí — não que a opção suma.
     *
     * O backend não confia nesta lista pra nada: quem decide de verdade é o
     * VideoLanguageService::resolver() na hora da geração. Se alguém mandar
     * pt-BR na request sem ser super_admin, cai no padrão do mesmo jeito.
     * Esconder opção na tela não é bloqueio — é enfeite.
     */
    public function languages(\Illuminate\Http\Request $request)
    {
        $userId = $request->user()?->id;

        return response()->json([
            'padrao'   => \App\Services\Ai\VideoLanguageService::PADRAO_CLIENTE,
            'idiomas'  => \App\Services\Ai\VideoLanguageService::catalogo($userId),
        ]);
    }
}
