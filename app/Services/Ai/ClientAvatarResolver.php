<?php

namespace App\Services\Ai;

use App\Models\ClientVideoAvatar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * SEL-361 Fase A -- ClientAvatarResolver
 *
 * Resolve o avatar ativo de um cliente.
 * Regra dura: modos com rosto (avatar_apresentando, trocar_rosto) BLOQUEIAM
 * se o cliente nao tiver avatar exclusivo (source != pool_shared).
 *
 * Cria avatar exclusivo via gpt-image-1 quando solicitado.
 */
class ClientAvatarResolver
{
    // Sources considerados exclusivos (nao pool compartilhado).
    // INF-030 (14/08, Conserto 2 -- medido: 7 clientes com avatar escolhido
    // source=auto_geracao_frame): ClientAvatarHarvester::bootstrapFromVideoFrame
    // extrai 1 frame do PROPRIO video que o cliente gerou (nunca de video de
    // outro cliente -- harvestFromPipeline resolve $client via
    // clients.user_id=$pipeline->user_id antes de gravar, e o modo 'ugc' sem
    // avatar_id/avatar_url escolhido gera rosto sintetizado unico por geracao
    // via IA, nunca reaproveita foto do pool video_avatars). custom_avatar_url
    // e video_avatar_id=null sempre nesse source -- e sempre exclusivo de
    // verdade, seguro incluir aqui.
    private const EXCLUSIVE_SOURCES = ["upload", "generated_exclusive", "ready_player_me", "auto_geracao_frame"];

    // Pipelines que exigem avatar exclusivo
    private const PIPELINES_REQUIRING_EXCLUSIVE = [
        "avatar_apresentando",
        "trocar_rosto",
        "showcase_silencioso",
    ];

    public function __construct(
        private OpenAiService $openai,
    ) {}

    /**
     * Resolve o avatar para uso em geracao.
     *
     * @return array{
     *   status: "ready"|"missing"|"pool_only",
     *   avatar_url: string|null,
     *   avatar_id: int|null,
     *   source: string|null,
     *   can_proceed: bool,
     *   block_reason: string|null,
     * }
     */
    public function resolve(int $clientId, string $pipeline): array
    {
        // Buscar avatar ativo mais recente.
        // INF-030 (14/08, Conserto 2): FIELD() devolve 0 pra source que nao
        // esta na lista -- e ORDER BY e ascendente, entao 0 vem ANTES de
        // "pool_shared" (posicao 4). auto_geracao/auto_geracao_frame nao
        // estavam listados: ordenavam em PRIMEIRO lugar (na frente ate de
        // "upload"/"generated_exclusive"), so por acidente do default do
        // FIELD(), nao por prioridade deliberada. Adicionado auto_geracao_frame
        // na posicao certa (depois dos 3 exclusivos deliberados, antes do
        // pool). auto_geracao (sem _frame) fica de fora de proposito -- ele
        // pode ser tanto um rosto exclusivo quanto um avatar do pool
        // reaproveitado (ClientAvatarHarvester::harvestFromPipeline linha
        // ~111: video_avatar_id vem preenchido quando bate com foto do pool),
        // e o `source` sozinho nao distingue os dois casos -- ver isExclusive
        // abaixo, que usa is_exclusive_to_client pra decidir esse.
        $avatar = DB::table("client_video_avatars")
            ->where("client_id", $clientId)
            ->where("is_active", true)
            ->orderByRaw("FIELD(source, \"upload\", \"generated_exclusive\", \"ready_player_me\", \"auto_geracao_frame\", \"pool_shared\")")
            ->orderBy("updated_at", "desc")
            ->first();

        $requiresExclusive = in_array($pipeline, self::PIPELINES_REQUIRING_EXCLUSIVE);

        if (!$avatar) {
            return [
                "status"       => "missing",
                "avatar_url"   => null,
                "avatar_id"    => null,
                "source"       => null,
                "can_proceed"  => !$requiresExclusive,
                "block_reason" => $requiresExclusive
                    ? "Voce precisa criar seu avatar exclusivo antes. O TikTok bane videos com avatares repetidos entre usuarios."
                    : null,
            ];
        }

        $source = $avatar->source ?? "pool_shared";
        // INF-030 (14/08, Conserto 2): alem da whitelist de source, confia na
        // coluna is_exclusive_to_client quando ela ja diz "1" -- coluna
        // mantida corretamente em toda a base hoje (VideoAvatarController::
        // reserve seta 0 pra reserva de pool, ::upload seta 1;
        // ClientAvatarResolver::generateExclusive sempre grava 1;
        // ClientAvatarHarvester grava 1 quando o rosto NAO bate com foto do
        // pool e 0 quando bate -- medido: 0 linhas ativas hoje com
        // is_exclusive_to_client incoerente com o source). Isso cobre
        // source=auto_geracao (nao entrou na whitelist acima de proposito)
        // sem arriscar tratar um auto_geracao pool-linkado como exclusivo.
        $isExclusive = in_array($source, self::EXCLUSIVE_SOURCES)
            || (bool) ($avatar->is_exclusive_to_client ?? false);

        if ($requiresExclusive && !$isExclusive) {
            return [
                "status"       => "pool_only",
                "avatar_url"   => $avatar->custom_avatar_url ?? null,
                "avatar_id"    => $avatar->id,
                "source"       => $source,
                "can_proceed"  => false,
                "block_reason" => "Seu avatar atual e do pool compartilhado. Risco de strike no TikTok. Crie um avatar exclusivo primeiro.",
            ];
        }

        $url = $avatar->custom_avatar_url ?? null;

        // Se source = pool_shared e nao requere exclusivo, buscar imagem do pool
        if (!$url && $source === "pool_shared" && isset($avatar->video_avatar_id)) {
            $url = DB::table("video_avatars")->where("id", $avatar->video_avatar_id)->value("image_url");
        }

        return [
            "status"       => "ready",
            "avatar_url"   => $url,
            "avatar_id"    => $avatar->id,
            "source"       => $source,
            "can_proceed"  => true,
            "block_reason" => null,
        ];
    }

    /**
     * Gera um avatar exclusivo para o cliente via gpt-image-1.
     *
     * @param int    $clientId
     * @param array  $params  {gender, age_range, ethnicity_hint, style}
     * @return array{avatar_id:int, avatar_url:string}
     */
    public function generateExclusive(int $clientId, array $params): array
    {
        $gender      = $params["gender"]         ?? "feminino";
        $ageRange    = $params["age_range"]       ?? "25-35";
        $ethnicity   = $params["ethnicity_hint"]  ?? "pardo";
        $style       = $params["style"]           ?? "casual";

        // Seed pseudo-random unico (garante unicidade entre clientes)
        $seed = substr(md5($clientId . microtime() . Str::uuid()), 0, 16);

        $prompt = "Ultra-realistic portrait photograph. Brazilian {$ethnicity} {$gender}, age {$ageRange}, "
            . "natural mixed features, warm sun-kissed skin, individual visible skin pores on T-zone, "
            . "{$style} style clothing, warm confident smile, natural catchlight in eyes, "
            . "editorial magazine quality, Canon EOS R5 85mm f/2, softbox lighting, "
            . "NO CGI, NO plastic skin, NO airbrushed look, NO watermark, NO text. "
            . "Unique portrait seed: {$seed}.";

        $imageUrl = $this->openai->generateImage($prompt, size: "1024x1024", quality: "hd");

        if (!$imageUrl) {
            throw new \RuntimeException("Falha ao gerar avatar exclusivo via IA.");
        }

        // Salvar no banco
        $avatarId = DB::table("client_video_avatars")->insertGetId([
            "client_id"              => $clientId,
            "video_avatar_id"        => null,
            "custom_avatar_url"      => $imageUrl,
            "label"                  => "Avatar exclusivo gerado em " . now()->format("d/m/Y"),
            "source"                 => "generated_exclusive",
            "is_exclusive_to_client" => true,
            "is_active"              => true,
            "generation_prompt"      => $prompt,
            "generation_seed"        => $seed,
            "created_at"             => now(),
            "updated_at"             => now(),
        ]);

        Log::info("[SEL-361 ClientAvatarResolver] avatar exclusivo gerado", [
            "client_id"  => $clientId,
            "avatar_id"  => $avatarId,
            "seed"       => $seed,
        ]);

        return ["avatar_id" => $avatarId, "avatar_url" => $imageUrl];
    }
}
