<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserVideoSubscription;
use App\Models\VideoSubscriptionPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * SEL-417: Guard de uso mensal do servico "Criador de Videos com IA".
 *
 * Escopo: apenas tenant sellerglobal. Em outros tenants os metodos
 * sao transparentes (canGenerate = true, consume = noop).
 *
 * Fluxo:
 *   1. VideoStudioController::generate() chama canGenerate() antes do dispatch.
 *      Se false -> HTTP 402 (limite atingido).
 *   2. Quando ai_video_pipelines.step transita para "done", chamar consume()
 *      para incrementar videos_used_this_cycle.
 */
class VideoSubscriptionGuard
{
    /**
     * Retorna true se o usuario ainda pode gerar um video neste ciclo.
     *
     * Regras:
     *  - Feature flag VIDEO_SUBSCRIPTIONS_ENABLED=false -> sempre true (backwards-compat).
     *  - Tenant != sellerglobal -> sempre true.
     *  - Usuario sem assinatura ativa -> aplica limites do plano free.
     *  - videos_used_this_cycle >= plan.videos_per_month -> false.
     */
    public function canGenerate(User $user): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        $sub  = $this->activeSubscription($user);
        $plan = $this->resolvePlan($sub);

        if ($plan === null) {
            Log::warning("SEL-417 VideoSubscriptionGuard: plano nao encontrado", [
                "user_id"   => $user->id,
                "plan_slug" => $sub?->plan_slug ?? "free",
            ]);
            return true;
        }

        $used  = $sub?->videos_used_this_cycle ?? 0;
        $limit = $plan->videos_per_month;

        return $used < $limit;
    }

    /**
     * Incrementa videos_used_this_cycle quando um pipeline transita para "done".
     *
     * NAO chamar no queued - so no done, para nao inflar o contador.
     * Se nao existir assinatura ativa, cria uma free para registrar o uso.
     */
    public function consume(User $user): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $sub = $this->activeSubscription($user);

        if ($sub === null) {
            $sub = $this->createFreeSubscription($user);
        }

        $sub->increment("videos_used_this_cycle");

        Log::info("SEL-417 VideoSubscriptionGuard: consumido", [
            "user_id"   => $user->id,
            "plan_slug" => $sub->plan_slug,
            "used"      => $sub->videos_used_this_cycle,
        ]);
    }

    private function isEnabled(): bool
    {
        if (config("app.tenant") !== "sellerglobal") {
            return false;
        }

        return (bool) config("video.subscriptions_enabled", true);
    }

    private function activeSubscription(User $user): ?UserVideoSubscription
    {
        return UserVideoSubscription::where("user_id", $user->id)
            ->where("status", "active")
            ->where(function ($q) {
                $q->whereNull("cycle_ends_at")
                  ->orWhere("cycle_ends_at", ">=", now());
            })
            ->first();
    }

    private function resolvePlan(?UserVideoSubscription $sub): ?VideoSubscriptionPlan
    {
        $slug = $sub?->plan_slug ?? "free";
        return VideoSubscriptionPlan::where("slug", $slug)->where("is_active", true)->first();
    }

    private function createFreeSubscription(User $user): UserVideoSubscription
    {
        $now = Carbon::now();

        return UserVideoSubscription::create([
            "user_id"                => $user->id,
            "plan_slug"              => "free",
            "status"                 => "active",
            "asaas_subscription_id"  => null,
            "cycle_started_at"       => $now->copy()->startOfMonth(),
            "cycle_ends_at"          => null,
            "videos_used_this_cycle" => 0,
        ]);
    }
}

