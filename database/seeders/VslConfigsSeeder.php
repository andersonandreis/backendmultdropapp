<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SEL-260 — Seed inicial das VSLs do vslPool.ts (seller-global).
 *
 * Importa as constantes fixas + pool rotativa. Idempotente: só insere
 * se a tabela estiver vazia.
 *
 * Rodar: php artisan db:seed --class=VslConfigsSeeder
 */
class VslConfigsSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('vsl_configs')->count() > 0) {
            $this->command->info('[VslConfigsSeeder] Tabela já tem dados — skip.');
            return;
        }

        $now = now();

        // VSLs fixas por slot (menu_slug).
        $fixed = [
            // tiktok_shopping — VSL_TIKTOK_SHOPPING
            [
                'menu_slug'   => 'tiktok_shopping',
                'video_url'   => 'https://vz-fc93f0ef-a26.b-cdn.net/7a4fd56b-afcf-497a-ae1e-c82aa082f487/playlist.m3u8',
                'thumbnail_url' => null,
                'active'      => 1,
                'sort_order'  => 0,
                'uploaded_by' => null,
                'created_at'  => $now,
                'updated_at'  => $now,
                'deleted_at'  => null,
            ],
            // dropshipping — VSL_SUPPLIERS (usado no slot Fornecedores / modal onboarding)
            [
                'menu_slug'   => 'dropshipping',
                'video_url'   => 'https://vz-4e6a8067-00c.b-cdn.net/8183e6e4-e00c-48d4-9184-9a007a46bd65/playlist.m3u8',
                'thumbnail_url' => null,
                'active'      => 1,
                'sort_order'  => 0,
                'uploaded_by' => null,
                'created_at'  => $now,
                'updated_at'  => $now,
                'deleted_at'  => null,
            ],
            // landing — VSL_WHATSAPP_EMBED (iframe embed, WhatsApp CTA / landing pages)
            [
                'menu_slug'   => 'landing',
                'video_url'   => 'https://player.mediadelivery.net/play/567931/0ddbd455-6fe7-449d-b91f-c5a38109f16e',
                'thumbnail_url' => null,
                'active'      => 1,
                'sort_order'  => 0,
                'uploaded_by' => null,
                'created_at'  => $now,
                'updated_at'  => $now,
                'deleted_at'  => null,
            ],
        ];

        // Pool rotativa de dropshipping (VSL_ROTATION_POOL) — cadastradas no slot landing
        // com sort_order incremental, ativas. Frontend pode puxar todas e rotacionar client-side.
        $rotationPool = [
            'https://vz-4e6a8067-00c.b-cdn.net/c274436d-6952-4fa6-b5c4-cde04a8f416c/playlist.m3u8',
            'https://vz-4e6a8067-00c.b-cdn.net/79397736-b6b6-485f-9db9-736129e8a860/playlist.m3u8',
            'https://vz-4e6a8067-00c.b-cdn.net/e84c801c-abd9-48e8-a426-7a4e5305a1ce/playlist.m3u8',
            'https://vz-4e6a8067-00c.b-cdn.net/79db6244-8dc9-4e33-86b8-e78b31b1437f/playlist.m3u8',
            'https://vz-4e6a8067-00c.b-cdn.net/cd63ba61-0f9f-4432-82b7-79f8600d3a66/playlist.m3u8',
            'https://vz-4e6a8067-00c.b-cdn.net/7d8b8b32-d9e0-4087-974a-d2d235251700/playlist.m3u8',
            'https://vz-4e6a8067-00c.b-cdn.net/f610fc2b-e138-465d-9203-60d214dc45c1/playlist.m3u8',
            'https://vz-4e6a8067-00c.b-cdn.net/5183f043-60b7-4d26-927c-aafaeb269c5b/playlist.m3u8',
        ];

        foreach ($rotationPool as $i => $url) {
            $fixed[] = [
                'menu_slug'    => 'dropshipping',
                'video_url'    => $url,
                'thumbnail_url' => null,
                'active'       => 1,
                'sort_order'   => $i + 1,  // sort_order 0 = VSL_SUPPLIERS principal
                'uploaded_by'  => null,
                'created_at'   => $now,
                'updated_at'   => $now,
                'deleted_at'   => null,
            ];
        }

        DB::table('vsl_configs')->insert($fixed);
        $this->command->info('[VslConfigsSeeder] ' . count($fixed) . ' VSLs inseridas.');
    }
}
