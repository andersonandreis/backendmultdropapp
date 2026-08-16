<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-200A: seed novos planos (TT Shop + Dropshipping tiers anuais).
 * Idempotente — só insere se slug nao existir.
 */
return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $plans = [
            [
                'slug' => 'tt_shop_trial_3d',
                'name' => 'TikTok Shop — Teste 3 dias',
                'description' => 'Trial gratuito 3 dias. Depois: R$29,90/mês ou R$297/ano.',
                'category' => 'tt_shop',
                'price_monthly' => 0.00, 'price_yearly' => 0.00,
                'trial_days' => 3,
                'max_skus' => 0, 'max_marketplace_connections' => 0,
                'has_drop_internacional' => 0, 'max_erp_connections' => 0,
                'ai_features' => json_encode([]),
                'ai_monthly_video_limit' => 0, 'ai_monthly_credits' => 0,
                'push_admin_enabled' => 1, 'push_client_enabled' => 1,
                'is_active' => 1,
            ],
            [
                'slug' => 'tt_shop_monthly',
                'name' => 'TikTok Shop — Mensal',
                'description' => 'R$29,90/mês. Acesso completo: produtos alta, criadores, fornecedores, risco restrição, viral potential, melhores horários.',
                'category' => 'tt_shop',
                'price_monthly' => 29.90, 'price_yearly' => 0.00,
                'trial_days' => 0,
                'max_skus' => 0, 'max_marketplace_connections' => 0,
                'has_drop_internacional' => 0, 'max_erp_connections' => 0,
                'ai_features' => json_encode([]),
                'ai_monthly_video_limit' => 0, 'ai_monthly_credits' => 0,
                'push_admin_enabled' => 1, 'push_client_enabled' => 1,
                'is_active' => 1,
            ],
            [
                'slug' => 'tt_shop_annual',
                'name' => 'TikTok Shop — Anual (economia + créditos IA)',
                'description' => 'R$297/ano. Bônus: 5 créditos IA vídeo pra experimentar.',
                'category' => 'tt_shop',
                'price_monthly' => 0.00, 'price_yearly' => 297.00,
                'trial_days' => 0,
                'max_skus' => 0, 'max_marketplace_connections' => 0,
                'has_drop_internacional' => 0, 'max_erp_connections' => 0,
                'ai_features' => json_encode(['video_seedance']),
                'ai_monthly_video_limit' => 0,
                'ai_monthly_credits' => 5,
                'push_admin_enabled' => 1, 'push_client_enabled' => 1,
                'is_active' => 1,
            ],
            [
                'slug' => 'drop_start',
                'name' => 'Dropshipping Start',
                'description' => 'R$997/ano. Dropshipping básico + Lista de Fornecedores incluso.',
                'category' => 'dropshipping',
                'price_monthly' => 0.00, 'price_yearly' => 997.00,
                'trial_days' => 0,
                'max_skus' => 100, 'max_marketplace_connections' => 3,
                'has_drop_internacional' => 0, 'max_erp_connections' => 1,
                'ai_features' => json_encode(['image']),
                'ai_monthly_video_limit' => 0, 'ai_monthly_credits' => 0,
                'push_admin_enabled' => 1, 'push_client_enabled' => 1,
                'is_active' => 1,
            ],
            [
                'slug' => 'drop_meio',
                'name' => 'Dropshipping Escalar',
                'description' => 'R$2.997/ano. Dropshipping + IA imagens ilimitada + 300 SKUs.',
                'category' => 'dropshipping',
                'price_monthly' => 0.00, 'price_yearly' => 2997.00,
                'trial_days' => 0,
                'max_skus' => 300, 'max_marketplace_connections' => 5,
                'has_drop_internacional' => 1, 'max_erp_connections' => 2,
                'ai_features' => json_encode(['image','analyze_image','virtual_try_on']),
                'ai_monthly_video_limit' => 3, 'ai_monthly_credits' => 3,
                'push_admin_enabled' => 1, 'push_client_enabled' => 1,
                'is_active' => 1,
            ],
            [
                'slug' => 'drop_top',
                'name' => 'Dropshipping Total',
                'description' => 'R$5.997/ano. Tudo incluso: Dropshipping ilimitado + TT Shop + Lista Fornec + Kling ilimitado + grupo VIP prioritário.',
                'category' => 'dropshipping',
                'price_monthly' => 0.00, 'price_yearly' => 5997.00,
                'trial_days' => 0,
                'max_skus' => 99999, 'max_marketplace_connections' => 99,
                'has_drop_internacional' => 1, 'max_erp_connections' => 10,
                'ai_features' => json_encode(['image','analyze_image','virtual_try_on','video_seedance','video_do_zero','animar_foto','trocar_pessoa','efeitos_virais','tts_openai','tts_elevenlabs','lip_sync','voice_clone','ebook_de_fotos','script','transcribe']),
                'ai_monthly_video_limit' => 30, 'ai_monthly_credits' => 100,
                'push_admin_enabled' => 1, 'push_client_enabled' => 1,
                'is_active' => 1,
            ],
        ];

        foreach ($plans as $p) {
            if (!DB::table('plans')->where('slug', $p['slug'])->exists()) {
                DB::table('plans')->insert(array_merge($p, [
                    'affiliate_commission_percent' => 0.00,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }
    }

    public function down(): void
    {
        DB::table('plans')->whereIn('slug', ['tt_shop_trial_3d','tt_shop_monthly','tt_shop_annual','drop_start','drop_meio','drop_top'])->delete();
    }
};
