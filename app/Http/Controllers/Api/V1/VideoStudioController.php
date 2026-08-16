<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Services\Ai\SeedanceCatalog;
use App\Services\VideoSubscriptionGuard;
use App\Services\Ai\SeedanceService;
use App\Services\Ai\PromptBuilderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SEL-115: VideoStudio — endpoint dedicado com wizard completo.
 * Rotas: POST /api/v1/videostudio/generate | GET /api/v1/videostudio/status/{id}
 * Modelo: env ARK_MODEL_ID (nunca hardcoded).
 */
class VideoStudioController extends Controller
{
    public function __construct(
        private SeedanceService $seedance,
        private PromptBuilderService $builder,
    ) {}

    public function generate(Request $request)
    {
        $v = $request->validate([
            "wizard"                       => "required|array",
            "wizard.style"                 => "nullable|string|in:cinematic,hook,unboxing,demo,before_after,lifestyle,asmr,ugc,custom",
            "wizard.camera_move"           => "nullable|string|in:dolly-forward,zoom-in,pan-lateral,static,handheld",
            "wizard.aspect_ratio"          => "nullable|string|in:9:16,16:9,1:1,4:3,3:4",
            "wizard.duration"              => "nullable|integer|between:4,15",
            "wizard.product_name"          => "nullable|string|max:300",
            "wizard.product_description"   => "nullable|string|max:1000",
            "wizard.overlays"              => "nullable|array",
            "wizard.overlays.*"            => "string",
            "wizard.hook"                  => "nullable|string|max:500",
            "wizard.custom_prompt"         => "nullable|string|max:2500",
            "wizard.negative_prompt"       => "nullable|string|max:500",
            "image_url"                    => "nullable|url|max:2000",
            "resolution"                   => "nullable|string|in:480p,720p,1080p",
            "model"                        => "nullable|string|in:" . implode(",", SeedanceCatalog::ids()),
        ]);

        // SEL-417: guard de uso mensal
        if ($request->user() !== null) {
            $videoGuard = new VideoSubscriptionGuard();
            if (! $videoGuard->canGenerate($request->user())) { // SEL-417
                return response()->json([
                    "error"   => "video_limit_reached",
                    "message" => "Voce atingiu o limite de videos do seu plano neste ciclo.",
                ], 402);
            }
        }

        if (! $this->seedance->isConfigured()) {
            return response()->json([
                "configured" => false,
                "message"    => "Motor de video ainda nao ativado.",
            ], 503);
        }

        $built = $this->builder->build($v["wizard"]);
        $content = [];
        if ($built["prompt"]) {
            $content[] = ["type" => "text", "text" => $built["prompt"]];
        }
        if (! empty($v["image_url"])) {
            $content[] = [
                "type"      => "image_url",
                "image_url" => ["url" => $v["image_url"]],
                "role"      => "first_frame",
            ];
        }
        if (empty($content)) {
            return response()->json(["message" => "Informe ao menos um prompt ou imagem do produto."], 422);
        }

        $model      = $v["model"] ?? (env("ARK_MODEL_ID") ?: null);
        $resolution = $v["resolution"] ?? "720p";
        $duration   = (int) ($v["wizard"]["duration"] ?? 5);

        try {
            $out = $this->seedance->createTask($content, [
                "model"      => $model,
                "ratio"      => $built["ratio"],
                "duration"   => $duration,
                "resolution" => $resolution,
            ]);
        } catch (Throwable $e) {
            Log::warning("SEL-115 VideoStudio generate error: " . $e->getMessage());
            return $this->providerError($e);
        }

        $taskId    = $out["id"] ?? null;
        $usedModel = $v["model"] ?? ($model ?: $this->seedance->providerModel());

        $gen = AiGeneration::create([
            "tenant_id"        => $request->user()?->tenant_id ?? null,
            "user_id"          => $request->user()?->id ?? null,
            "service"          => "video",
            "provider"         => "seedance",
            "provider_model"   => $usedModel,
            "provider_task_id" => $taskId,
            "wizard_payload"   => array_merge($v["wizard"] ?? [], [
                "_options" => [
                    "model"      => $usedModel,
                    "resolution" => $resolution,
                    "duration"   => $duration,
                ],
            ]),
            "final_prompt"     => $built["prompt"],
            "status"           => "processing",
            "output_url"       => null,
            "credits_debited"  => 0,
            "cost_usd"         => 0,
        ]);

        return response()->json([
            "id"         => $gen->id,
            "task_id"    => $taskId,
            "status"     => "processing",
            "prompt"     => $built["prompt"],
            "model_used" => $usedModel,
        ], 201);
    }

    public function status(string $id)
    {
        $gen = AiGeneration::query()
            ->where("id", $id)
            ->where("user_id", request()->user()?->id)
            ->where("service", "video")
            ->first();

        if (! $gen) {
            return response()->json(["message" => "Geracao nao encontrada."], 404);
        }

        return response()->json([
            "id"         => $gen->id,
            "status"     => $gen->status,
            "video_url"  => $gen->output_url,
            "expires_at" => $gen->expires_at,
            "model_used" => $gen->provider_model,
            "cost_usd"   => $gen->cost_usd,
            "credits"    => $gen->credits_debited,
            "created_at" => $gen->created_at,
        ]);
    }

    private function providerError(Throwable $e)
    {
        $msg = $e->getMessage();
        if (str_contains($msg, "not_configured")) {
            return response()->json(["error" => "provider_not_configured", "message" => "Motor de video ainda nao conectado."], 503);
        }
        return response()->json(["error" => "provider_error", "message" => "Falha temporaria no provedor de video."], 502);
    }
}
