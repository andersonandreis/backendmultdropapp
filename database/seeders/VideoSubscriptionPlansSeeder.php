<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SEL-417: Popula os 3 tiers de plano de video IA.
 *
 * Free  - 3 videos/mes, gratis
 * Pro   - 30 videos/mes, R$47/mes (R$470/ano, 2 meses gratis)
 * Turbo - 100 videos/mes, R$97/mes (R$970/ano), is_featured=1
 */
class VideoSubscriptionPlansSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                "slug"                  => "free",
                "name"                  => "Free",
                "price_cents_monthly"   => 0,
                "price_cents_yearly"    => 0,
                "videos_per_month"      => 3,
                "features_json"         => json_encode([
                    "3 videos por mes",
                    "Acesso ao Studio IA",
                    "Qualidade 720p",
                    "Marca dagua HubAI",
                ]),
                "is_featured"           => false,
                "is_active"             => true,
                "sort_order"            => 1,
                "created_at"            => now(),
                "updated_at"            => now(),
            ],
            [
                "slug"                  => "pro",
                "name"                  => "Pro",
                "price_cents_monthly"   => 4700,
                "price_cents_yearly"    => 47000,
                "videos_per_month"      => 30,
                "features_json"         => json_encode([
                    "30 videos por mes",
                    "Qualidade 1080p",
                    "Sem marca dagua",
                    "Voz sincronizada com labios",
                    "Suporte prioritario",
                ]),
                "is_featured"           => false,
                "is_active"             => true,
                "sort_order"            => 2,
                "created_at"            => now(),
                "updated_at"            => now(),
            ],
            [
                "slug"                  => "turbo",
                "name"                  => "Turbo",
                "price_cents_monthly"   => 9700,
                "price_cents_yearly"    => 97000,
                "videos_per_month"      => 100,
                "features_json"         => json_encode([
                    "100 videos por mes",
                    "Qualidade 1080p",
                    "Sem marca dagua",
                    "Voz sincronizada com labios",
                    "Acesso a todos os avatares",
                    "Suporte VIP 24h",
                ]),
                "is_featured"           => true,
                "is_active"             => true,
                "sort_order"            => 3,
                "created_at"            => now(),
                "updated_at"            => now(),
            ],
        ];

        foreach ($plans as $plan) {
            DB::table("video_subscription_plans")->updateOrInsert(
                ["slug" => $plan["slug"]],
                $plan
            );
        }

        $this->command->info("VideoSubscriptionPlansSeeder: 3 planos inseridos/atualizados.");
    }
}

