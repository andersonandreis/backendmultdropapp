<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserVideoSubscription;
use App\Models\VideoSubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SEL-417: Endpoints de planos de assinatura do servico Criador de Videos com IA.
 *
 * GET /api/v1/video-plans     -> publico, sem auth, rate-limit 60/min
 * GET /api/v1/video-plans/me  -> autenticado (sanctum), plano do usuario logado
 */
class VideoPlansController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = VideoSubscriptionPlan::where("is_active", true)
            ->orderBy("sort_order")
            ->get()
            ->map(fn ($p) => $this->formatPlan($p));

        return response()->json(["data" => $plans]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $sub = UserVideoSubscription::where("user_id", $user->id)
            ->where("status", "active")
            ->where(function ($q) {
                $q->whereNull("cycle_ends_at")
                  ->orWhere("cycle_ends_at", ">=", now());
            })
            ->first();

        $planSlug = $sub?->plan_slug ?? "free";
        $plan     = VideoSubscriptionPlan::where("slug", $planSlug)
            ->where("is_active", true)
            ->first();

        if ($plan === null) {
            $plan = new VideoSubscriptionPlan([
                "slug"                => "free",
                "name"                => "Free",
                "price_cents_monthly" => 0,
                "price_cents_yearly"  => 0,
                "videos_per_month"    => 3,
                "features_json"       => [],
                "is_featured"         => false,
                "is_active"           => true,
                "sort_order"          => 1,
            ]);
        }

        $used      = $sub?->videos_used_this_cycle ?? 0;
        $remaining = max(0, $plan->videos_per_month - $used);

        return response()->json([
            "plan"                   => $this->formatPlan($plan),
            "subscription"           => $sub ? [
                "status"           => $sub->status,
                "cycle_started_at" => $sub->cycle_started_at?->toIso8601String(),
                "cycle_ends_at"    => $sub->cycle_ends_at?->toIso8601String(),
            ] : null,
            "videos_used_this_cycle" => $used,
            "videos_remaining"       => $remaining,
        ]);
    }

    private function formatPlan(VideoSubscriptionPlan $p): array
    {
        return [
            "slug"             => $p->slug,
            "name"             => $p->name,
            "price_monthly"    => round($p->price_cents_monthly / 100, 2),
            "price_yearly"     => round($p->price_cents_yearly / 100, 2),
            "videos_per_month" => $p->videos_per_month,
            "features"         => $p->features_json ?? [],
            "is_featured"      => (bool) $p->is_featured,
        ];
    }
}
